<?php

namespace App\Controllers;

use App\Libraries\S3Storage;
use App\Libraries\SigV4Auth;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\S3Server;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class S3Controller extends BaseController
{
    private S3Server $config;
    private ?S3Storage $storage = null;
    private ?SigV4Auth $auth = null;
    private string $requestId = '';
    private string $extendedRequestId = '';
    private ?string $bootError = null;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);

        // The previous version let any failure during config or auth construction
        // bubble up. On Windows php-cgi that meant the worker died mid-request,
        // which nginx surfaced as a 502/504 with no useful diagnostic. Wrap every
        // bootstrap step so the controller can always answer with a structured
        // 500 instead of going silent. CORS preflight (OPTIONS) only needs
        // requestId/auth/$config — never storage — so OPTIONS keeps working
        // even when the storage backend is broken.
        $this->requestId = bin2hex(random_bytes(8));
        $this->extendedRequestId = bin2hex(random_bytes(16));

        try {
            $this->config = config('S3Server');
        } catch (Throwable $e) {
            $this->bootError = 'S3Server config failed to load: ' . $e->getMessage();
            $this->config = new S3Server();
            $this->safeLog('critical', 'S3 config bootstrap failed: {message}', ['message' => $e->getMessage()]);
        }

        try {
            $this->storage = new S3Storage(
                $this->config->storagePath,
                $this->config->multipartAutoCleanup,
                $this->config->multipartMaxAgeSeconds,
                $this->config->multipartCleanupProbabilityPercent,
                $this->config->multipartCleanupBatchSize
            );
        } catch (Throwable $e) {
            $this->bootError = 'Storage backend is not writable. Check s3.storagePath and filesystem permissions.';
            $this->safeLog('critical', 'S3 storage bootstrap failed for path "{path}": {message}', [
                'path' => $this->config->storagePath,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $this->auth = new SigV4Auth(
                $this->config->accessKey,
                $this->config->secretKey,
                $this->config->region,
                $this->config->enforceSignatureV4,
                $this->config->headerAuthMaxSkewSeconds
            );
        } catch (Throwable $e) {
            $this->bootError = $this->bootError ?? ('Auth subsystem failed to initialize: ' . $e->getMessage());
            $this->safeLog('critical', 'S3 auth bootstrap failed: {message}', ['message' => $e->getMessage()]);
        }
    }

    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            log_message($level, $message, $context);
        } catch (Throwable) {
            // Logging must never take a worker down. Swallow any logger failure.
        }
    }

    public function root(): ResponseInterface
    {
        if ($storageError = $this->storageErrorResponse()) {
            return $storageError;
        }

        if ($corsError = $this->enforceCorsForActualRequest()) {
            return $corsError;
        }

        // ListBuckets is never anonymous; always require signed credentials.
        if ($authError = $this->authenticate()) {
            return $authError;
        }

        $buckets = $this->storage->listBuckets();
        $xml = '<ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Owner><ID>local-owner</ID><DisplayName>local-owner</DisplayName></Owner>'
            . '<Buckets>';

        foreach ($buckets as $bucket) {
            $xml .= '<Bucket><Name>' . $this->xml($bucket['name']) . '</Name><CreationDate>'
                . $this->xml($bucket['creationDate']) . '</CreationDate></Bucket>';
        }

        $xml .= '</Buckets></ListAllMyBucketsResult>';
        return $this->xmlResponse($xml);
    }

    public function optionsRoot(): ResponseInterface
    {
        return $this->optionsResponse();
    }

    public function bucket(string $bucket): ResponseInterface
    {
        $method = strtolower($this->request->getMethod());
        if ($method === 'options') {
            return $this->optionsResponse();
        }

        if ($storageError = $this->storageErrorResponse()) {
            return $storageError;
        }

        if ($corsError = $this->enforceCorsForActualRequest()) {
            return $corsError;
        }

        // Bucket-only operations have no object key. The previous code
        // referenced an undefined $key, which raised an E_WARNING in PHP 8
        // and could short-circuit auth incorrectly. Pass empty string for
        // the key argument so isPublicReadRequest evaluates against the
        // method only (it returns false for any non-GET/HEAD method, which
        // is exactly what we want for bucket writes).
        if (! $this->isPublicReadRequest($method, $bucket, '')) {
            if ($authError = $this->authenticate()) {
                return $authError;
            }
        }

        $query = $this->query();

        try {
            return match ($method) {
                'put' => $this->createBucket($bucket),
                'head' => $this->headBucket($bucket),
                'get' => $this->getBucket($bucket, $query),
                'delete' => $this->deleteBucket($bucket),
                'post' => $this->postBucket($bucket, $query),
                default => $this->methodNotAllowed(['GET', 'PUT', 'HEAD', 'DELETE', 'POST', 'OPTIONS']),
            };
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse(400, 'InvalidBucketName', $e->getMessage());
        } catch (Throwable $e) {
            log_message('error', 'Bucket operation failed: {message}', ['message' => $e->getMessage()]);
            return $this->errorResponse(500, 'InternalError', 'An internal error occurred.');
        }
    }

    public function object(string $bucket, string $key): ResponseInterface
    {
        $method = strtolower($this->request->getMethod());
        if ($method === 'options') {
            return $this->optionsResponse();
        }

        // Structured request log — every write to a bucket is captured at the
        // entry point so we can see what the SDK is actually sending when an
        // upload fails. The previous code only logged exceptions, which missed
        // cases where the request never reached the controller cleanly (CORS
        // rejection, auth failure, etc.). Reads are NOT logged to avoid
        // flooding the log with thumbnail traffic.
        if (in_array($method, ['put', 'post', 'delete'], true)) {
            log_message('info', 'S3 {method} {bucket}/{key} content-length={cl} content-type={ct} origin={origin} req={reqId}', [
                'method'  => strtoupper($method),
                'bucket'  => $bucket,
                'key'     => $key,
                'cl'      => $this->request->getHeaderLine('Content-Length') ?: ($_SERVER['CONTENT_LENGTH'] ?? ''),
                'ct'      => $this->request->getHeaderLine('Content-Type') ?: ($_SERVER['CONTENT_TYPE'] ?? ''),
                'origin'  => $this->request->getHeaderLine('Origin'),
                'reqId'   => $this->requestId,
            ]);
        }

        if ($storageError = $this->storageErrorResponse()) {
            log_message('error', 'S3 storage backend unavailable for {method} {bucket}/{key}: {error}', [
                'method' => strtoupper($method),
                'bucket' => $bucket,
                'key'    => $key,
                'error'  => $this->bootError ?? '(no detail)',
            ]);
            return $storageError;
        }

        $key = $this->extractObjectKeyFromUri($bucket, $key);

        if ($corsError = $this->enforceCorsForActualRequest()) {
            log_message('warning', 'S3 CORS rejected {method} {bucket}/{key} from origin {origin}', [
                'method' => strtoupper($method),
                'bucket' => $bucket,
                'key'    => $key,
                'origin' => $this->getRequestOrigin(),
            ]);
            return $corsError;
        }

        if (! $this->isPublicReadRequest($method, $bucket, $key)) {
            if ($authError = $this->authenticate()) {
                $authResult = $this->auth?->validate($this->request) ?? ['code' => 'InternalError', 'message' => $this->bootError ?? ''];
                log_message('warning', 'S3 auth failed for {method} {bucket}/{key}: [{code}] {msg}', [
                    'method' => strtoupper($method),
                    'bucket' => $bucket,
                    'key'    => $key,
                    'code'   => $authResult['code'] ?? '',
                    'msg'    => $authResult['message'] ?? '',
                ]);
                return $authError;
            }
        }

        $query = $this->query();

        try {
            return match ($method) {
                'put' => $this->putObject($bucket, $key, $query),
                'post' => $this->postObject($bucket, $key, $query),
                'get' => $this->getObject($bucket, $key, $query),
                'head' => $this->headObject($bucket, $key),
                'delete' => $this->deleteObject($bucket, $key, $query),
                default => $this->methodNotAllowed(['GET', 'PUT', 'HEAD', 'DELETE', 'POST', 'OPTIONS']),
            };
        } catch (InvalidArgumentException $e) {
            log_message('warning', 'S3 invalid request {method} {bucket}/{key}: {message}', [
                'method'  => strtoupper($method),
                'bucket'  => $bucket,
                'key'     => $key,
                'message' => $e->getMessage(),
            ]);
            return $this->errorResponse(400, 'InvalidRequest', $e->getMessage());
        } catch (Throwable $e) {
            log_message('error', 'S3 object operation failed {method} {bucket}/{key}: {message} @ {file}:{line}', [
                'method'  => strtoupper($method),
                'bucket'  => $bucket,
                'key'     => $key,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            return $this->errorResponse(500, 'InternalError', 'An internal error occurred.');
        }
    }

    private function isPublicReadRequest(string $method, string $bucket, string $key): bool
    {
        // Only allow anonymous reads for object files, never writes.
        if (! in_array(strtolower($method), ['get', 'head'], true)) {
            return false;
        }

        // Limit anonymous access to your public media prefixes only.
        $publicPrefixes = [
            'metadata/temp_album_artwork/',
            'metadata/temp_album/',
            'whitelabel/logo/',
            'defaults/noimg.png',
            'client/profile_pic/',
            'payments/invoice/',
            'tickets/',
            'metadata/artwork/',
            'metadata/audio/',
            'metadata/crbt/',
            'metadata/add/',
            'metadata/error/',
            'client/format/',
            'client/contracts/',
            'client/downloads/',
            'client/add/',
            'userprofile/',
            'reports/bulk/',
            'reports/whitelabel_bulk_reports/',
            'metadata/conflict_screenshots/',
            'whitelabel/b2_label_file/',
            'metadata/format/',
            'metadata/temp/',
            'client/error/',
            'userprofile/default_avtars/14.png',
            'metadata/extracts/',
            'reports/search_results/',
            'client/sign/',
            'metadata/temp_artwork/',
            'metadata/temp_audio/',
            'temp',
            'metadata/canvas/',
            'metadata/playlist_screenshots/',


        ];

        foreach ($publicPrefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $query
     */
    private function getBucket(string $bucket, array $query): ResponseInterface
    {
        if (! $this->storage->bucketExists($bucket)) {
            return $this->errorResponse(404, 'NoSuchBucket', 'The specified bucket does not exist.');
        }

        if (array_key_exists('location', $query)) {
            $xml = '<LocationConstraint xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
                . $this->xml($this->config->region)
                . '</LocationConstraint>';

            return $this->xmlResponse($xml);
        }

        if (array_key_exists('uploads', $query)) {
            return $this->listMultipartUploads($bucket, $query);
        }

        $list = $this->storage->listObjectsV2($bucket, $query);
        $xml = '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Name>' . $this->xml($bucket) . '</Name>'
            . '<Prefix>' . $this->xml($list['prefix']) . '</Prefix>'
            . '<Delimiter>' . $this->xml($list['delimiter']) . '</Delimiter>'
            . '<MaxKeys>' . $list['maxKeys'] . '</MaxKeys>'
            . '<KeyCount>' . $list['keyCount'] . '</KeyCount>'
            . '<IsTruncated>' . ($list['isTruncated'] ? 'true' : 'false') . '</IsTruncated>';

        if ($list['nextContinuationToken'] !== '') {
            $xml .= '<NextContinuationToken>' . $this->xml($list['nextContinuationToken']) . '</NextContinuationToken>';
        }

        foreach ($list['contents'] as $item) {
            $xml .= '<Contents>'
                . '<Key>' . $this->xml($item['key']) . '</Key>'
                . '<LastModified>' . $this->xml($item['lastModified']) . '</LastModified>'
                . '<ETag>"' . $this->xml($item['etag']) . '"</ETag>'
                . '<Size>' . $item['size'] . '</Size>'
                . '<StorageClass>STANDARD</StorageClass>'
                . '</Contents>';
        }

        foreach ($list['commonPrefixes'] as $commonPrefix) {
            $xml .= '<CommonPrefixes><Prefix>' . $this->xml($commonPrefix) . '</Prefix></CommonPrefixes>';
        }

        $xml .= '</ListBucketResult>';
        return $this->xmlResponse($xml);
    }

    private function createBucket(string $bucket): ResponseInterface
    {
        if ($this->storage->bucketExists($bucket)) {
            return $this->errorResponse(409, 'BucketAlreadyOwnedByYou', 'Your previous request to create the named bucket succeeded and you already own it.');
        }

        $this->storage->createBucket($bucket);
        return $this->emptyResponse(200);
    }

    private function headBucket(string $bucket): ResponseInterface
    {
        if (! $this->storage->bucketExists($bucket)) {
            return $this->emptyResponse(404);
        }

        return $this->emptyResponse(200);
    }

    private function deleteBucket(string $bucket): ResponseInterface
    {
        if (! $this->storage->bucketExists($bucket)) {
            return $this->errorResponse(404, 'NoSuchBucket', 'The specified bucket does not exist.');
        }

        if (! $this->storage->deleteBucket($bucket)) {
            return $this->errorResponse(409, 'BucketNotEmpty', 'The bucket you tried to delete is not empty.');
        }

        return $this->emptyResponse(204);
    }

    /**
     * @param array<string, string> $query
     */
    private function postBucket(string $bucket, array $query): ResponseInterface
    {
        if (! $this->storage->bucketExists($bucket)) {
            return $this->errorResponse(404, 'NoSuchBucket', 'The specified bucket does not exist.');
        }

        if (! array_key_exists('delete', $query)) {
            return $this->errorResponse(501, 'NotImplemented', 'This bucket POST operation is not implemented.');
        }

        $rawBody = (string) file_get_contents('php://input');
        if ($rawBody === '') {
            return $this->errorResponse(400, 'MalformedXML', 'DeleteObjects body is required.');
        }

        try {
            $xml = new SimpleXMLElement($rawBody);
        } catch (Throwable) {
            return $this->errorResponse(400, 'MalformedXML', 'The XML you provided was not well-formed.');
        }

        $response = '<DeleteResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        foreach ($xml->Object as $objectNode) {
            $key = (string) $objectNode->Key;
            if ($key === '') {
                continue;
            }

            $this->storage->deleteObject($bucket, $key);
            $response .= '<Deleted><Key>' . $this->xml($key) . '</Key></Deleted>';
        }
        $response .= '</DeleteResult>';

        return $this->xmlResponse($response);
    }

    /**
     * @param array<string, string> $query
     */
    private function putObject(string $bucket, string $key, array $query): ResponseInterface
    {
        if (! $this->storage->bucketExists($bucket)) {
            return $this->errorResponse(404, 'NoSuchBucket', 'The specified bucket does not exist.');
        }

        if (isset($query['uploadId'], $query['partNumber'])) {
            $uploadId = (string) $query['uploadId'];
            $partNumber = (int) $query['partNumber'];
            $input = fopen('php://input', 'rb');

            try {
                $result = $this->storage->uploadPart($uploadId, $partNumber, $input);
            } catch (InvalidArgumentException $e) {
                if (is_resource($input)) {
                    fclose($input);
                }
                $msg = $e->getMessage();
                if (str_contains($msg, 'upload ID') || str_contains($msg, 'Upload ID')) {
                    return $this->errorResponse(404, 'NoSuchUpload', 'The specified multipart upload does not exist.');
                }
                return $this->errorResponse(400, 'InvalidRequest', $msg);
            }

            if (is_resource($input)) {
                fclose($input);
            }

            return $this->withCommonHeaders(
                $this->response
                    ->setStatusCode(200)
                    ->setHeader('ETag', '"' . $result['etag'] . '"')
                    ->setBody('')
            );
        }

        $input = fopen('php://input', 'rb');
        $result = $this->storage->putObject($bucket, $key, $input, $this->collectObjectMetadata());
        if (is_resource($input)) {
            fclose($input);
        }

        return $this->withCommonHeaders(
            $this->response
                ->setStatusCode(200)
                ->setHeader('ETag', '"' . $result['etag'] . '"')
                ->setHeader('Last-Modified', gmdate(DATE_RFC7231, $result['mtime']))
                ->setBody('')
        );
    }

    /**
     * @param array<string, string> $query
     */
    private function postObject(string $bucket, string $key, array $query): ResponseInterface
    {
        if (! $this->storage->bucketExists($bucket)) {
            return $this->errorResponse(404, 'NoSuchBucket', 'The specified bucket does not exist.');
        }

        if (array_key_exists('uploads', $query)) {
            $upload = $this->storage->initiateMultipartUpload($bucket, $key, $this->collectObjectMetadata());
            $xml = '<InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
                . '<Bucket>' . $this->xml($bucket) . '</Bucket>'
                . '<Key>' . $this->xml($key) . '</Key>'
                . '<UploadId>' . $this->xml($upload['uploadId']) . '</UploadId>'
                . '</InitiateMultipartUploadResult>';

            return $this->xmlResponse($xml);
        }

        if (isset($query['uploadId'])) {
            $uploadId = (string) $query['uploadId'];
            $parts = $this->parseCompleteMultipartBody((string) file_get_contents('php://input'));
            if ($parts === []) {
                return $this->errorResponse(400, 'MalformedXML', 'CompleteMultipartUpload requires at least one Part.');
            }

            try {
                $result = $this->storage->completeMultipartUpload($uploadId, $parts);
            } catch (InvalidArgumentException $e) {
                return $this->errorResponse(404, 'NoSuchUpload', 'The specified multipart upload does not exist.');
            } catch (RuntimeException $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'ETag mismatch') || str_contains($msg, 'specified parts could not be found')) {
                    return $this->errorResponse(400, 'InvalidPart', $msg);
                }
                throw $e;
            }

            $location = $this->objectUrl($result['bucket'], $result['key']);
            $xml = '<CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
                . '<Location>' . $this->xml($location) . '</Location>'
                . '<Bucket>' . $this->xml($result['bucket']) . '</Bucket>'
                . '<Key>' . $this->xml($result['key']) . '</Key>'
                . '<ETag>"' . $this->xml($result['etag']) . '"</ETag>'
                . '</CompleteMultipartUploadResult>';

            return $this->xmlResponse($xml);
        }

        return $this->errorResponse(501, 'NotImplemented', 'This object POST operation is not implemented.');
    }

    /**
     * @param array<string, string> $query
     */
    private function getObject(string $bucket, string $key, array $query): ResponseInterface
    {
        if (! $this->storage->bucketExists($bucket)) {
            return $this->errorResponse(404, 'NoSuchBucket', 'The specified bucket does not exist.');
        }

        if (isset($query['uploadId'])) {
            $uploadId = (string) $query['uploadId'];

            try {
                $result = $this->storage->listMultipartParts($bucket, $key, $uploadId, $query);
            } catch (InvalidArgumentException) {
                return $this->errorResponse(404, 'NoSuchUpload', 'The specified multipart upload does not exist.');
            }

            $xml = '<ListPartsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
                . '<Bucket>' . $this->xml($result['bucket']) . '</Bucket>'
                . '<Key>' . $this->xml($result['key']) . '</Key>'
                . '<UploadId>' . $this->xml($result['uploadId']) . '</UploadId>'
                . '<Initiator><ID>local-owner</ID><DisplayName>local-owner</DisplayName></Initiator>'
                . '<Owner><ID>local-owner</ID><DisplayName>local-owner</DisplayName></Owner>'
                . '<StorageClass>STANDARD</StorageClass>'
                . '<PartNumberMarker>' . $result['partNumberMarker'] . '</PartNumberMarker>'
                . '<NextPartNumberMarker>' . $result['nextPartNumberMarker'] . '</NextPartNumberMarker>'
                . '<MaxParts>' . $result['maxParts'] . '</MaxParts>'
                . '<IsTruncated>' . ($result['isTruncated'] ? 'true' : 'false') . '</IsTruncated>';

            foreach ($result['parts'] as $part) {
                $xml .= '<Part>'
                    . '<PartNumber>' . $part['partNumber'] . '</PartNumber>'
                    . '<LastModified>' . $this->xml($part['lastModified']) . '</LastModified>'
                    . '<ETag>"' . $this->xml($part['etag']) . '"</ETag>'
                    . '<Size>' . $part['size'] . '</Size>'
                    . '</Part>';
            }

            $xml .= '</ListPartsResult>';
            return $this->xmlResponse($xml);
        }

        $info = $this->storage->getObjectInfo($bucket, $key);
        if ($info === null) {
            return $this->errorResponse(404, 'NoSuchKey', 'The specified key does not exist.');
        }

        $rangeHeader = trim($this->request->getHeaderLine('Range'));
        [$ok, $start, $end] = $this->parseRange($rangeHeader, $info['size']);
        if (! $ok) {
            return $this->withCommonHeaders(
                $this->response
                    ->setStatusCode(416)
                    ->setHeader('Content-Range', 'bytes */' . $info['size'])
                    ->setBody('')
            );
        }

        if ($rangeHeader === '') {
            $download = $this->response->download($info['path'], null, true);
            if ($download === null) {
                throw new InvalidArgumentException('Unable to open object data.');
            }

            $download
                ->inline()
                ->setHeader('Content-Type', $info['contentType'])
                ->setHeader('ETag', '"' . $info['etag'] . '"')
                ->setHeader('Last-Modified', gmdate(DATE_RFC7231, $info['mtime']))
                ->setHeader('Accept-Ranges', 'bytes');

            foreach ($info['metadata'] as $metaKey => $metaValue) {
                $download->setHeader('x-amz-meta-' . $metaKey, $metaValue);
            }

            return $this->withCommonHeaders($download);
        }

        $length = $end - $start + 1;
        if ($this->config->maxRangeBytes > 0 && $length > $this->config->maxRangeBytes) {
            return $this->errorResponse(416, 'InvalidRange', 'Requested range exceeds configured max range size.');
        }

        $body = $this->readFileSlice($info['path'], $start, $length);
        $status = ($start > 0 || $end < ($info['size'] - 1)) ? 206 : 200;

        $response = $this->response
            ->setStatusCode($status)
            ->setHeader('Content-Type', $info['contentType'])
            ->setHeader('Content-Length', (string) $length)
            ->setHeader('ETag', '"' . $info['etag'] . '"')
            ->setHeader('Last-Modified', gmdate(DATE_RFC7231, $info['mtime']))
            ->setHeader('Accept-Ranges', 'bytes')
            ->setBody($body);

        if ($status === 206) {
            $response->setHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $info['size']);
        }

        foreach ($info['metadata'] as $metaKey => $metaValue) {
            $response->setHeader('x-amz-meta-' . $metaKey, $metaValue);
        }

        return $this->withCommonHeaders($response);
    }

    private function headObject(string $bucket, string $key): ResponseInterface
    {
        if (! $this->storage->bucketExists($bucket)) {
            return $this->emptyResponse(404);
        }

        $info = $this->storage->getObjectInfo($bucket, $key);
        if ($info === null) {
            return $this->emptyResponse(404);
        }

        $response = $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', $info['contentType'])
            ->setHeader('Content-Length', (string) $info['size'])
            ->setHeader('ETag', '"' . $info['etag'] . '"')
            ->setHeader('Last-Modified', gmdate(DATE_RFC7231, $info['mtime']))
            ->setHeader('Accept-Ranges', 'bytes')
            ->setBody('');

        foreach ($info['metadata'] as $metaKey => $metaValue) {
            $response->setHeader('x-amz-meta-' . $metaKey, $metaValue);
        }

        return $this->withCommonHeaders($response);
    }

    /**
     * @param array<string, string> $query
     */
    private function deleteObject(string $bucket, string $key, array $query): ResponseInterface
    {
        if (! $this->storage->bucketExists($bucket)) {
            return $this->errorResponse(404, 'NoSuchBucket', 'The specified bucket does not exist.');
        }

        if (isset($query['uploadId'])) {
            $this->storage->abortMultipartUpload((string) $query['uploadId']);
            return $this->emptyResponse(204);
        }

        $this->storage->deleteObject($bucket, $key);
        return $this->emptyResponse(204);
    }

    /**
     * @param array<string, string> $query
     */
    private function listMultipartUploads(string $bucket, array $query): ResponseInterface
    {
        $result = $this->storage->listMultipartUploads($bucket, $query);
        $xml = '<ListMultipartUploadsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Bucket>' . $this->xml($bucket) . '</Bucket>'
            . '<KeyMarker>' . $this->xml($result['keyMarker']) . '</KeyMarker>'
            . '<UploadIdMarker>' . $this->xml($result['uploadIdMarker']) . '</UploadIdMarker>'
            . '<MaxUploads>' . $result['maxUploads'] . '</MaxUploads>'
            . '<IsTruncated>' . ($result['isTruncated'] ? 'true' : 'false') . '</IsTruncated>';

        if ($result['prefix'] !== '') {
            $xml .= '<Prefix>' . $this->xml($result['prefix']) . '</Prefix>';
        }

        if ($result['delimiter'] !== '') {
            $xml .= '<Delimiter>' . $this->xml($result['delimiter']) . '</Delimiter>';
        }

        if ($result['isTruncated']) {
            $xml .= '<NextKeyMarker>' . $this->xml($result['nextKeyMarker']) . '</NextKeyMarker>'
                . '<NextUploadIdMarker>' . $this->xml($result['nextUploadIdMarker']) . '</NextUploadIdMarker>';
        }

        foreach ($result['uploads'] as $upload) {
            $xml .= '<Upload>'
                . '<Key>' . $this->xml($upload['key']) . '</Key>'
                . '<UploadId>' . $this->xml($upload['uploadId']) . '</UploadId>'
                . '<Initiator><ID>local-owner</ID><DisplayName>local-owner</DisplayName></Initiator>'
                . '<Owner><ID>local-owner</ID><DisplayName>local-owner</DisplayName></Owner>'
                . '<StorageClass>STANDARD</StorageClass>'
                . '<Initiated>' . $this->xml($upload['initiated']) . '</Initiated>'
                . '</Upload>';
        }

        foreach ($result['commonPrefixes'] as $commonPrefix) {
            $xml .= '<CommonPrefixes><Prefix>' . $this->xml($commonPrefix) . '</Prefix></CommonPrefixes>';
        }

        $xml .= '</ListMultipartUploadsResult>';
        return $this->xmlResponse($xml);
    }

    /**
     * @return array<string, mixed>
     */
    private function collectObjectMetadata(): array
    {
        $meta = [
            'content_type' => $this->request->getHeaderLine('Content-Type') ?: 'application/octet-stream',
            'cache_control' => $this->request->getHeaderLine('Cache-Control'),
            'content_disposition' => $this->request->getHeaderLine('Content-Disposition'),
            'content_encoding' => $this->request->getHeaderLine('Content-Encoding'),
            'content_language' => $this->request->getHeaderLine('Content-Language'),
            'expires' => $this->request->getHeaderLine('Expires'),
            'user_metadata' => [],
        ];

        foreach ($this->request->headers() as $name => $header) {
            $lower = strtolower($name);
            if (! str_starts_with($lower, 'x-amz-meta-')) {
                continue;
            }
            $key = substr($lower, strlen('x-amz-meta-'));
            $meta['user_metadata'][$key] = $header->getValueLine();
        }

        return $meta;
    }

    private function authenticate(): ?ResponseInterface
    {
        if ($this->auth === null) {
            return $this->errorResponse(500, 'InternalError', $this->bootError ?? 'Auth subsystem unavailable.');
        }

        $result = $this->auth->validate($this->request);
        if ($result['ok']) {
            return null;
        }

        return $this->errorResponse($result['status'], $result['code'], $result['message']);
    }

    private function storageErrorResponse(): ?ResponseInterface
    {
        if ($this->storage !== null) {
            return null;
        }

        return $this->errorResponse(500, 'InternalError', $this->bootError ?? 'Storage backend initialization failed.');
    }

    private function xmlResponse(string $xml, int $statusCode = 200): ResponseInterface
    {
        return $this->withCommonHeaders(
            $this->response
                ->setStatusCode($statusCode)
                ->setHeader('Content-Type', 'application/xml')
                ->setBody($xml)
        );
    }

    private function emptyResponse(int $statusCode): ResponseInterface
    {
        return $this->withCommonHeaders(
            $this->response
                ->setStatusCode($statusCode)
                ->setBody('')
        );
    }

    private function errorResponse(int $statusCode, string $code, string $message): ResponseInterface
    {
        $resource = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $resource = is_string($resource) && $resource !== '' ? $resource : '/';
        $xml = '<Error>'
            . '<Code>' . $this->xml($code) . '</Code>'
            . '<Message>' . $this->xml($message) . '</Message>'
            . '<Resource>' . $this->xml($resource) . '</Resource>'
            . '<RequestId>' . $this->xml($this->requestId) . '</RequestId>'
            . '</Error>';

        return $this->xmlResponse($xml, $statusCode);
    }

    private function optionsResponse(): ResponseInterface
    {
        if (! $this->config->enableCors) {
            return $this->withCommonHeaders(
                $this->response
                    ->setStatusCode(204)
                    ->setHeader('Allow', implode(', ', $this->config->corsAllowedMethods))
                    ->setBody('')
            );
        }

        $origin = $this->getRequestOrigin();
        $requestedMethod = strtoupper(trim($this->request->getHeaderLine('Access-Control-Request-Method')));
        $requestedHeaders = $this->normalizeCsvList($this->request->getHeaderLine('Access-Control-Request-Headers'), false);

        if (
            $origin === ''
            || ! $this->isCorsOriginAllowed($origin)
            || ! $this->isCorsMethodAllowed($requestedMethod)
            || ! $this->areCorsHeadersAllowed($requestedHeaders)
        ) {
            return $this->withCommonHeaders(
                $this->response
                    ->setStatusCode(403)
                    ->setBody('')
            );
        }

        $response = $this->response
            ->setStatusCode(204)
            ->setHeader('Allow', implode(', ', $this->config->corsAllowedMethods))
            ->setBody('');

        return $this->withCommonHeaders($response);
    }

    /**
     * @param array<int, string> $allowedMethods
     */
    private function methodNotAllowed(array $allowedMethods): ResponseInterface
    {
        return $this->withCommonHeaders(
            $this->response
                ->setStatusCode(405)
                ->setHeader('Allow', implode(', ', $allowedMethods))
                ->setBody('')
        );
    }

    private function withCommonHeaders(ResponseInterface $response): ResponseInterface
    {
        $response->setHeader('x-amz-request-id', $this->requestId);
        $response->setHeader('x-amz-id-2', $this->extendedRequestId);

        if ($this->config->enableCors) {
            $origin = $this->getRequestOrigin();
            if ($origin !== '' && $this->isCorsOriginAllowed($origin)) {
                $allowOrigin = $this->resolveCorsAllowOrigin($origin);
                $response->setHeader('Access-Control-Allow-Origin', $allowOrigin);
                $response->setHeader('Access-Control-Allow-Methods', implode(', ', $this->config->corsAllowedMethods));
                $response->setHeader('Access-Control-Allow-Headers', implode(', ', $this->config->corsAllowedHeaders));
                $response->setHeader('Access-Control-Expose-Headers', implode(', ', $this->config->corsExposeHeaders));
                $response->setHeader('Access-Control-Max-Age', (string) $this->config->corsMaxAge);
                $response->setHeader('Vary', 'Origin, Access-Control-Request-Headers, Access-Control-Request-Method');

                if ($this->config->corsAllowCredentials && $allowOrigin !== '*') {
                    $response->setHeader('Access-Control-Allow-Credentials', 'true');
                }
            }
        }

        return $response;
    }

    private function enforceCorsForActualRequest(): ?ResponseInterface
    {
        if (! $this->config->enableCors) {
            return null;
        }

        $origin = $this->getRequestOrigin();
        if ($origin === '') {
            return null;
        }

        if ($this->isCorsOriginAllowed($origin)) {
            return null;
        }

        return $this->withCommonHeaders(
            $this->response
                ->setStatusCode(403)
                ->setBody('')
        );
    }

    private function resolveCorsAllowOrigin(string $requestOrigin): string
    {
        if (in_array('*', $this->config->corsAllowedOrigins, true) && ! $this->config->corsAllowCredentials) {
            return '*';
        }

        return rtrim($requestOrigin, '/');
    }

    private function getRequestOrigin(): string
    {
        return rtrim(trim($this->request->getHeaderLine('Origin')), '/');
    }

    private function isCorsOriginAllowed(string $origin): bool
    {
        if ($origin === '') {
            return false;
        }

        if (in_array('*', $this->config->corsAllowedOrigins, true)) {
            return true;
        }

        $normalized = strtolower(rtrim($origin, '/'));
        foreach ($this->config->corsAllowedOrigins as $allowedOrigin) {
            if ($normalized === strtolower(rtrim($allowedOrigin, '/'))) {
                return true;
            }
        }

        return false;
    }

    private function isCorsMethodAllowed(string $method): bool
    {
        if ($method === '') {
            return false;
        }
        return in_array(strtoupper($method), $this->config->corsAllowedMethods, true);
    }

    /**
     * @param list<string> $requestedHeaders
     */
    private function areCorsHeadersAllowed(array $requestedHeaders): bool
    {
        if ($requestedHeaders === []) {
            return true;
        }

        $allowed = array_map(static fn (string $h): string => strtolower(trim($h)), $this->config->corsAllowedHeaders);
        if (in_array('*', $allowed, true)) {
            return true;
        }

        $allowedLookup = array_fill_keys($allowed, true);

        foreach ($requestedHeaders as $header) {
            $normalized = strtolower(trim($header));
            if ($normalized === '') {
                continue;
            }

            if (isset($allowedLookup[$normalized])) {
                continue;
            }

            $matchedPattern = false;
            foreach ($allowed as $allowedHeader) {
                if (! str_contains($allowedHeader, '*')) {
                    continue;
                }

                $pattern = '/^' . str_replace('\*', '.*', preg_quote($allowedHeader, '/')) . '$/';
                if (preg_match($pattern, $normalized) === 1) {
                    $matchedPattern = true;
                    break;
                }
            }

            if (! $matchedPattern) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function normalizeCsvList(string $raw, bool $upper): array
    {
        $parts = array_filter(array_map(static function (string $item) use ($upper): string {
            $value = trim($item);
            if ($value === '') {
                return '';
            }
            return $upper ? strtoupper($value) : strtolower($value);
        }, explode(',', $raw)));

        return array_values(array_unique($parts));
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @return array<string, string>
     */
    private function query(): array
    {
        $rawQuery = $_SERVER['QUERY_STRING'] ?? '';
        if ($rawQuery === '') {
            return [];
        }

        parse_str($rawQuery, $parsed);
        $result = [];
        foreach ($parsed as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $result[(string) $key] = (string) $value;
        }

        return $result;
    }

    /**
     * @return array{0: bool, 1: int, 2: int}
     */
    private function parseRange(string $rangeHeader, int $size): array
    {
        if ($size <= 0) {
            return [true, 0, -1];
        }

        if ($rangeHeader === '') {
            return [true, 0, $size - 1];
        }

        if (! preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $matches)) {
            return [false, 0, 0];
        }

        $startRaw = $matches[1];
        $endRaw = $matches[2];

        if ($startRaw === '' && $endRaw === '') {
            return [false, 0, 0];
        }

        if ($startRaw === '') {
            $suffixLength = (int) $endRaw;
            if ($suffixLength <= 0) {
                return [false, 0, 0];
            }
            $start = max(0, $size - $suffixLength);
            return [true, $start, $size - 1];
        }

        $start = (int) $startRaw;
        $end = $endRaw === '' ? $size - 1 : (int) $endRaw;

        if ($start >= $size || $start > $end) {
            return [false, 0, 0];
        }

        $end = min($end, $size - 1);
        return [true, $start, $end];
    }

    private function readFileSlice(string $path, int $start, int $length): string
    {
        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Unable to open object data.');
        }

        try {
            if ($start > 0) {
                fseek($stream, $start);
            }

            $content = stream_get_contents($stream, $length);
            return $content === false ? '' : $content;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array<int, array{partNumber: int, etag: string}>
     */
    private function parseCompleteMultipartBody(string $rawBody): array
    {
        if (trim($rawBody) === '') {
            return [];
        }

        try {
            $xml = new SimpleXMLElement($rawBody);
        } catch (Throwable) {
            return [];
        }

        $parts = [];
        foreach ($xml->Part as $partNode) {
            $partNumber = (int) $partNode->PartNumber;
            if ($partNumber <= 0) {
                continue;
            }

            $etag = trim((string) $partNode->ETag, '"');
            $parts[] = [
                'partNumber' => $partNumber,
                'etag' => $etag,
            ];
        }

        return $parts;
    }

    private function objectUrl(string $bucket, string $key): string
    {
        $scheme = $this->request->isSecure() ? 'https' : 'http';
        $host = $this->request->getHeaderLine('Host');
        if ($host === '') {
            $host = 'localhost';
        }

        $segments = array_map(static fn (string $segment): string => rawurlencode($segment), explode('/', $key));
        return $scheme . '://' . $host . '/' . rawurlencode($bucket) . '/' . implode('/', $segments);
    }

    private function extractObjectKeyFromUri(string $bucket, string $fallbackKey): string
    {
        $segments = $this->request->getUri()->getSegments();
        if ($segments === []) {
            return ltrim($fallbackKey, '/');
        }

        if ($segments[0] !== $bucket) {
            return ltrim($fallbackKey, '/');
        }

        $key = implode('/', array_slice($segments, 1));
        return ltrim($key !== '' ? $key : $fallbackKey, '/');
    }
}
