<?php

namespace App\Libraries;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

class S3Storage
{
    private const DIRECTORY_OBJECT_MARKER = '.__ci4s3_object';
    private const MIGRATION_TMP_PREFIX = '.__migrating__';

    private string $rootPath;
    private string $metaPath;
    private string $multipartPath;
    private bool $multipartAutoCleanup;
    private int $multipartMaxAgeSeconds;
    private int $multipartCleanupProbabilityPercent;
    private int $multipartCleanupBatchSize;

    /** 0 disables the cap; see Config\S3Server::$verifyPayloadMaxBytes. */
    private int $verifyPayloadMaxBytes;

    public function __construct(
        string $rootPath,
        bool $multipartAutoCleanup = true,
        int $multipartMaxAgeSeconds = 86_400,
        int $multipartCleanupProbabilityPercent = 5,
        int $multipartCleanupBatchSize = 200,
        int $verifyPayloadMaxBytes = 268_435_456
    )
    {
        $this->rootPath = rtrim($rootPath, "\\/");
        $this->metaPath = $this->rootPath . DIRECTORY_SEPARATOR . '.meta';
        $this->multipartPath = $this->rootPath . DIRECTORY_SEPARATOR . '.multipart';
        $this->multipartAutoCleanup = $multipartAutoCleanup;
        $this->multipartMaxAgeSeconds = max(0, $multipartMaxAgeSeconds);
        $this->multipartCleanupProbabilityPercent = max(0, min(100, $multipartCleanupProbabilityPercent));
        $this->multipartCleanupBatchSize = max(1, $multipartCleanupBatchSize);
        $this->verifyPayloadMaxBytes = max(0, $verifyPayloadMaxBytes);

        $this->ensureDirectory($this->rootPath);
        $this->ensureDirectory($this->metaPath);
        $this->ensureDirectory($this->multipartPath . DIRECTORY_SEPARATOR . 'uploads');
        $this->ensureDirectory($this->multipartPath . DIRECTORY_SEPARATOR . 'parts');
    }

    public function bucketExists(string $bucket): bool
    {
        return is_dir($this->bucketPath($bucket));
    }

    public function createBucket(string $bucket): bool
    {
        $path = $this->bucketPath($bucket);
        if (is_dir($path)) {
            return false;
        }

        $this->ensureDirectory($path);
        return true;
    }

    public function deleteBucket(string $bucket): bool
    {
        $path = $this->bucketPath($bucket);
        if (! is_dir($path)) {
            return false;
        }

        if (! $this->isDirectoryEmpty($path)) {
            return false;
        }

        rmdir($path);
        $metaBucketPath = $this->metaPath . DIRECTORY_SEPARATOR . $bucket;
        if (is_dir($metaBucketPath)) {
            $this->deleteDirectoryRecursively($metaBucketPath);
        }

        return true;
    }

    /**
     * @return array<int, array{name: string, creationDate: string}>
     */
    public function listBuckets(): array
    {
        $result = [];
        $items = scandir($this->rootPath);

        if ($items === false) {
            return $result;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) {
                continue;
            }

            // Never advertise the framework's own runtime directories as buckets —
            // the storage root is `writable`, so they are siblings of the real ones.
            if (in_array(strtolower($item), self::RESERVED_BUCKETS, true)) {
                continue;
            }

            $path = $this->rootPath . DIRECTORY_SEPARATOR . $item;
            if (! is_dir($path)) {
                continue;
            }

            $result[] = [
                'name' => $item,
                'creationDate' => gmdate('Y-m-d\TH:i:s\Z', filemtime($path) ?: time()),
            ];
        }

        usort($result, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $result;
    }

    /**
     * @param array<string, mixed> $meta
     * @param string $expectSha256 SHA-256 the client signed for this body, or '' to
     *        skip verification. Purely additive: existing callers that omit it get
     *        exactly the previous behaviour. The caller decides what a mismatch
     *        means — see S3Controller::verifyUploadedPayload().
     * @return array{etag: string, size: int, mtime: int, path: string, sha256: string}
     */
    public function putObject(string $bucket, string $key, $inputStream, array $meta, string $expectSha256 = ''): array
    {
        if (! $this->bucketExists($bucket)) {
            throw new InvalidArgumentException('Bucket does not exist.');
        }

        $this->ensureParentDirectoriesWithMigration($bucket, $key);
        $objectPath = $this->resolveObjectPathForWrite($bucket, $key);
        $this->ensureDirectory(dirname($objectPath));

        $output = fopen($objectPath, 'wb');
        if (! is_resource($output)) {
            throw new RuntimeException('Unable to open object file for writing.');
        }

        try {
            stream_copy_to_stream($inputStream, $output);
        } finally {
            fclose($output);
        }

        $this->saveObjectMetadata($bucket, $key, $meta);

        $size  = filesize($objectPath) ?: 0;
        $mtime = filemtime($objectPath) ?: time();

        // Hashed from the file rather than the stream: php://input is consumed by the
        // copy above and cannot be rewound, and buffering the body to hash it first
        // would pull a whole upload into memory. This is a second pass over the file,
        // the same order of cost as the md5_file() the ETag already needs.
        $sha256 = '';
        if ($expectSha256 !== '' && ($this->verifyPayloadMaxBytes === 0 || $size <= $this->verifyPayloadMaxBytes)) {
            $sha256 = hash_file('sha256', $objectPath) ?: '';
        }

        return [
            'etag' => md5_file($objectPath) ?: '',
            'size' => $size,
            'mtime' => $mtime,
            'path' => $objectPath,
            'sha256' => $sha256,
        ];
    }

    /**
     * @return array{
     *   path: string,
     *   size: int,
     *   mtime: int,
     *   etag: string,
     *   contentType: string,
     *   metadata: array<string, string>
     * }|null
     */
    public function getObjectInfo(string $bucket, string $key): ?array
    {
        if (! $this->bucketExists($bucket)) {
            return null;
        }

        $path = $this->resolveObjectPathForRead($bucket, $key);
        if (! is_file($path)) {
            return null;
        }

        $meta = $this->loadObjectMetadata($bucket, $key);
        $contentType = (string) ($meta['content_type'] ?? '');

        if ($contentType === '') {
            $detected = @mime_content_type($path);
            $contentType = is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
        }

        return [
            'path' => $path,
            'size' => filesize($path) ?: 0,
            'mtime' => filemtime($path) ?: time(),
            'etag' => md5_file($path) ?: '',
            'contentType' => $contentType,
            'metadata' => is_array($meta['user_metadata'] ?? null) ? $meta['user_metadata'] : [],
        ];
    }

    public function deleteObject(string $bucket, string $key): void
    {
        if (! $this->bucketExists($bucket)) {
            return;
        }

        $basePath = $this->objectPath($bucket, $key);
        $legacyPath = $basePath;
        $markerPath = $basePath . DIRECTORY_SEPARATOR . self::DIRECTORY_OBJECT_MARKER;

        if (is_file($legacyPath)) {
            unlink($legacyPath);
            $this->cleanupEmptyParents(dirname($legacyPath), $this->bucketPath($bucket));
        }

        if (is_file($markerPath)) {
            unlink($markerPath);
            $this->cleanupEmptyParents(dirname($markerPath), $this->bucketPath($bucket));
        }

        $metaPath = $this->objectMetaPath($bucket, $key);
        if (is_file($metaPath)) {
            unlink($metaPath);
            $this->cleanupEmptyParents(dirname($metaPath), $this->metaPath . DIRECTORY_SEPARATOR . $bucket);
        }
    }

    /**
     * @param array<string, string> $query
     * @return array{
     *   name: string,
     *   prefix: string,
     *   delimiter: string,
     *   maxKeys: int,
     *   keyCount: int,
     *   isTruncated: bool,
     *   nextContinuationToken: string,
     *   contents: array<int, array{key: string, etag: string, size: int, lastModified: string}>,
     *   commonPrefixes: array<int, string>
     * }
     */
    public function listObjectsV2(string $bucket, array $query): array
    {
        if (! $this->bucketExists($bucket)) {
            throw new InvalidArgumentException('Bucket does not exist.');
        }

        $prefix = (string) ($query['prefix'] ?? '');
        $delimiter = (string) ($query['delimiter'] ?? '');
        $maxKeys = (int) ($query['max-keys'] ?? 1000);
        if ($maxKeys < 1) {
            $maxKeys = 1;
        } elseif ($maxKeys > 1000) {
            $maxKeys = 1000;
        }

        $marker = '';
        if (isset($query['continuation-token']) && $query['continuation-token'] !== '') {
            $decoded = base64_decode((string) $query['continuation-token'], true);
            if ($decoded !== false) {
                $marker = $decoded;
            }
        } elseif (isset($query['start-after'])) {
            $marker = (string) $query['start-after'];
        }

        $allKeys = $this->listObjectKeys($bucket);
        $filtered = [];
        foreach ($allKeys as $key) {
            if ($prefix !== '' && ! str_starts_with($key, $prefix)) {
                continue;
            }
            if ($marker !== '' && strcmp($key, $marker) <= 0) {
                continue;
            }
            $filtered[] = $key;
        }

        sort($filtered, SORT_STRING);

        $contents = [];
        $commonPrefixSet = [];
        $itemCount = 0;
        $isTruncated = false;
        $lastKeySeen = '';

        foreach ($filtered as $key) {
            if ($itemCount >= $maxKeys) {
                $isTruncated = true;
                break;
            }

            $lastKeySeen = $key;
            if ($delimiter !== '') {
                $remaining = substr($key, strlen($prefix));
                $delimiterPos = strpos($remaining, $delimiter);
                if ($delimiterPos !== false) {
                    $commonPrefix = $prefix . substr($remaining, 0, $delimiterPos + strlen($delimiter));
                    if (! isset($commonPrefixSet[$commonPrefix])) {
                        $commonPrefixSet[$commonPrefix] = true;
                        $itemCount++;
                    }
                    continue;
                }
            }

            $info = $this->getObjectInfo($bucket, $key);
            if ($info === null) {
                continue;
            }

            $contents[] = [
                'key' => $key,
                'etag' => $info['etag'],
                'size' => $info['size'],
                'lastModified' => gmdate('Y-m-d\TH:i:s\Z', $info['mtime']),
            ];
            $itemCount++;
        }

        $commonPrefixes = array_keys($commonPrefixSet);
        sort($commonPrefixes, SORT_STRING);

        return [
            'name' => $bucket,
            'prefix' => $prefix,
            'delimiter' => $delimiter,
            'maxKeys' => $maxKeys,
            'keyCount' => $itemCount,
            'isTruncated' => $isTruncated,
            'nextContinuationToken' => $isTruncated ? base64_encode($lastKeySeen) : '',
            'contents' => $contents,
            'commonPrefixes' => $commonPrefixes,
        ];
    }

    /**
     * @param array<string, mixed> $meta Object metadata (content_type, cache_control, etc.)
     * @return array{uploadId: string}
     */
    public function initiateMultipartUpload(string $bucket, string $key, array $meta = []): array
    {
        if (! $this->bucketExists($bucket)) {
            throw new InvalidArgumentException('Bucket does not exist.');
        }

        $this->maybeCleanupMultipartUploads();
        $this->normalizeObjectKey($key);
        $uploadId = bin2hex(random_bytes(16));
        $manifest = [
            'bucket' => $bucket,
            'key' => $key,
            'createdAt' => gmdate('c'),
            'metadata' => $meta,
        ];

        file_put_contents($this->multipartManifestPath($uploadId), json_encode($manifest, JSON_THROW_ON_ERROR));
        return ['uploadId' => $uploadId];
    }

    /**
     * @return array{etag: string}
     */
    public function uploadPart(string $uploadId, int $partNumber, $inputStream): array
    {
        if ($partNumber < 1 || $partNumber > 10000) {
            throw new InvalidArgumentException('Part number must be between 1 and 10000.');
        }

        $this->maybeCleanupMultipartUploads();
        $manifest = $this->loadMultipartManifest($uploadId);
        if ($manifest === null) {
            throw new InvalidArgumentException('Invalid upload ID.');
        }

        $partsDirectory = $this->multipartPath . DIRECTORY_SEPARATOR . 'parts' . DIRECTORY_SEPARATOR . $uploadId;
        $this->ensureDirectory($partsDirectory);
        $partPath = $partsDirectory . DIRECTORY_SEPARATOR . $partNumber . '.part';

        $output = fopen($partPath, 'wb');
        if (! is_resource($output)) {
            throw new RuntimeException('Unable to open multipart part file.');
        }

        try {
            stream_copy_to_stream($inputStream, $output);
        } finally {
            fclose($output);
        }

        return ['etag' => md5_file($partPath) ?: ''];
    }

    /**
     * @param array<int, array{partNumber: int, etag: string}> $parts
     * @return array{bucket: string, key: string, etag: string}
     */
    public function completeMultipartUpload(string $uploadId, array $parts): array
    {
        $this->maybeCleanupMultipartUploads();
        $manifest = $this->loadMultipartManifest($uploadId);
        if ($manifest === null) {
            throw new InvalidArgumentException('Invalid upload ID.');
        }

        usort($parts, static fn (array $a, array $b): int => $a['partNumber'] <=> $b['partNumber']);

        $bucket = (string) $manifest['bucket'];
        $key = (string) $manifest['key'];

        // Verify all parts exist and ETags match before assembling the object.
        $partsDir = $this->multipartPath . DIRECTORY_SEPARATOR . 'parts' . DIRECTORY_SEPARATOR . $uploadId;
        foreach ($parts as $part) {
            $partNumber = (int) $part['partNumber'];
            $clientEtag = trim((string) ($part['etag'] ?? ''), "\" \t\n\r");
            $partPath = $partsDir . DIRECTORY_SEPARATOR . $partNumber . '.part';

            if (! is_file($partPath)) {
                throw new RuntimeException('One or more of the specified parts could not be found. The part might not have been uploaded.');
            }

            if ($clientEtag !== '') {
                $storedEtag = md5_file($partPath) ?: '';
                if (! hash_equals($storedEtag, $clientEtag)) {
                    throw new RuntimeException('ETag mismatch for part ' . $partNumber . '.');
                }
            }
        }

        $this->ensureParentDirectoriesWithMigration($bucket, $key);
        $objectPath = $this->resolveObjectPathForWrite($bucket, $key);
        $this->ensureDirectory(dirname($objectPath));

        $output = fopen($objectPath, 'wb');
        if (! is_resource($output)) {
            throw new RuntimeException('Unable to create completed object.');
        }

        try {
            foreach ($parts as $part) {
                $partNumber = (int) $part['partNumber'];
                $partPath = $partsDir . DIRECTORY_SEPARATOR . $partNumber . '.part';

                $input = fopen($partPath, 'rb');
                if (! is_resource($input)) {
                    throw new RuntimeException('Unable to read multipart part.');
                }

                try {
                    stream_copy_to_stream($input, $output);
                } finally {
                    fclose($input);
                }
            }
        } catch (Throwable $e) {
            fclose($output);
            throw $e;
        }

        fclose($output);

        $meta = is_array($manifest['metadata'] ?? null) ? $manifest['metadata'] : [];
        if ($meta !== []) {
            $this->saveObjectMetadata($bucket, $key, $meta);
        }

        $this->deleteMultipartUpload($uploadId);

        return [
            'bucket' => $bucket,
            'key' => $key,
            'etag' => md5_file($objectPath) ?: '',
        ];
    }

    public function abortMultipartUpload(string $uploadId): void
    {
        $this->maybeCleanupMultipartUploads();
        $this->deleteMultipartUpload($uploadId);
    }

    /**
     * @param array<string, string> $query
     * @return array{
     *   bucket: string,
     *   keyMarker: string,
     *   uploadIdMarker: string,
     *   nextKeyMarker: string,
     *   nextUploadIdMarker: string,
     *   maxUploads: int,
     *   isTruncated: bool,
     *   uploads: array<int, array{key: string, uploadId: string, initiated: string}>,
     *   prefix: string,
     *   delimiter: string,
     *   commonPrefixes: array<int, string>
     * }
     */
    public function listMultipartUploads(string $bucket, array $query = []): array
    {
        if (! $this->bucketExists($bucket)) {
            throw new InvalidArgumentException('Bucket does not exist.');
        }

        $this->maybeCleanupMultipartUploads();

        $prefix = (string) ($query['prefix'] ?? '');
        $delimiter = (string) ($query['delimiter'] ?? '');
        $keyMarker = (string) ($query['key-marker'] ?? '');
        $uploadIdMarker = (string) ($query['upload-id-marker'] ?? '');
        $maxUploads = (int) ($query['max-uploads'] ?? 1000);
        if ($maxUploads < 1) {
            $maxUploads = 1;
        } elseif ($maxUploads > 1000) {
            $maxUploads = 1000;
        }

        $uploadsDir = $this->multipartPath . DIRECTORY_SEPARATOR . 'uploads';
        $files = glob($uploadsDir . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            $files = [];
        }

        $allUploads = [];
        foreach ($files as $manifestPath) {
            $uploadId = pathinfo($manifestPath, PATHINFO_FILENAME);
            if ($uploadId === '') {
                continue;
            }

            $manifest = $this->loadMultipartManifest($uploadId);
            if ($manifest === null) {
                continue;
            }

            $manifestBucket = (string) ($manifest['bucket'] ?? '');
            if ($manifestBucket !== $bucket) {
                continue;
            }

            $key = (string) ($manifest['key'] ?? '');
            $initiated = (string) ($manifest['createdAt'] ?? gmdate('c'));

            $allUploads[] = [
                'key' => $key,
                'uploadId' => $uploadId,
                'initiated' => $initiated,
            ];
        }

        usort($allUploads, static function (array $a, array $b): int {
            $keyCmp = strcmp($a['key'], $b['key']);
            if ($keyCmp !== 0) {
                return $keyCmp;
            }
            return strcmp($a['uploadId'], $b['uploadId']);
        });

        $filtered = [];
        foreach ($allUploads as $upload) {
            if ($prefix !== '' && ! str_starts_with($upload['key'], $prefix)) {
                continue;
            }
            if ($keyMarker !== '') {
                $keyCmp = strcmp($upload['key'], $keyMarker);
                if ($keyCmp < 0) {
                    continue;
                }
                if ($keyCmp === 0 && $uploadIdMarker !== '' && strcmp($upload['uploadId'], $uploadIdMarker) <= 0) {
                    continue;
                }
                if ($keyCmp === 0 && $uploadIdMarker === '') {
                    continue;
                }
            }
            $filtered[] = $upload;
        }

        $uploads = [];
        $commonPrefixSet = [];
        $itemCount = 0;
        $isTruncated = false;
        $nextKeyMarker = '';
        $nextUploadIdMarker = '';

        foreach ($filtered as $upload) {
            if ($itemCount >= $maxUploads) {
                $isTruncated = true;
                break;
            }

            if ($delimiter !== '') {
                $remaining = substr($upload['key'], strlen($prefix));
                $delimiterPos = strpos($remaining, $delimiter);
                if ($delimiterPos !== false) {
                    $commonPrefix = $prefix . substr($remaining, 0, $delimiterPos + strlen($delimiter));
                    if (! isset($commonPrefixSet[$commonPrefix])) {
                        $commonPrefixSet[$commonPrefix] = true;
                        $itemCount++;
                    }
                    continue;
                }
            }

            $uploads[] = $upload;
            $nextKeyMarker = $upload['key'];
            $nextUploadIdMarker = $upload['uploadId'];
            $itemCount++;
        }

        $commonPrefixes = array_keys($commonPrefixSet);
        sort($commonPrefixes, SORT_STRING);

        return [
            'bucket' => $bucket,
            'keyMarker' => $keyMarker,
            'uploadIdMarker' => $uploadIdMarker,
            'nextKeyMarker' => $isTruncated ? $nextKeyMarker : '',
            'nextUploadIdMarker' => $isTruncated ? $nextUploadIdMarker : '',
            'maxUploads' => $maxUploads,
            'isTruncated' => $isTruncated,
            'uploads' => $uploads,
            'prefix' => $prefix,
            'delimiter' => $delimiter,
            'commonPrefixes' => $commonPrefixes,
        ];
    }

    /**
     * @param array<string, string> $query
     * @return array{
     *   bucket: string,
     *   key: string,
     *   uploadId: string,
     *   partNumberMarker: int,
     *   nextPartNumberMarker: int,
     *   maxParts: int,
     *   isTruncated: bool,
     *   parts: array<int, array{partNumber: int, etag: string, size: int, lastModified: string}>
     * }
     */
    public function listMultipartParts(string $bucket, string $key, string $uploadId, array $query): array
    {
        $this->maybeCleanupMultipartUploads();
        $manifest = $this->loadMultipartManifest($uploadId);
        if ($manifest === null) {
            throw new InvalidArgumentException('Invalid upload ID.');
        }

        $manifestBucket = (string) ($manifest['bucket'] ?? '');
        $manifestKey = (string) ($manifest['key'] ?? '');
        if ($manifestBucket !== $bucket || $manifestKey !== $key) {
            throw new InvalidArgumentException('Upload does not match bucket/key.');
        }

        $partNumberMarker = (int) ($query['part-number-marker'] ?? 0);
        if ($partNumberMarker < 0) {
            $partNumberMarker = 0;
        }

        $maxParts = (int) ($query['max-parts'] ?? 1000);
        if ($maxParts < 1) {
            $maxParts = 1;
        } elseif ($maxParts > 1000) {
            $maxParts = 1000;
        }

        $partsDirectory = $this->multipartPath . DIRECTORY_SEPARATOR . 'parts' . DIRECTORY_SEPARATOR . $uploadId;
        $partNumbers = [];
        if (is_dir($partsDirectory)) {
            $entries = scandir($partsDirectory);
            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if (preg_match('/^(\d+)\.part$/', $entry, $matches) !== 1) {
                        continue;
                    }
                    $partNumber = (int) $matches[1];
                    if ($partNumber > 0) {
                        $partNumbers[] = $partNumber;
                    }
                }
            }
        }

        sort($partNumbers, SORT_NUMERIC);
        $filteredPartNumbers = [];
        foreach ($partNumbers as $partNumber) {
            if ($partNumber > $partNumberMarker) {
                $filteredPartNumbers[] = $partNumber;
            }
        }

        $isTruncated = count($filteredPartNumbers) > $maxParts;
        if ($isTruncated) {
            $filteredPartNumbers = array_slice($filteredPartNumbers, 0, $maxParts);
        }

        $parts = [];
        $nextPartNumberMarker = $partNumberMarker;
        foreach ($filteredPartNumbers as $partNumber) {
            $partPath = $partsDirectory . DIRECTORY_SEPARATOR . $partNumber . '.part';
            if (! is_file($partPath)) {
                continue;
            }

            $parts[] = [
                'partNumber' => $partNumber,
                'etag' => md5_file($partPath) ?: '',
                'size' => filesize($partPath) ?: 0,
                'lastModified' => gmdate('Y-m-d\TH:i:s\Z', filemtime($partPath) ?: time()),
            ];
            $nextPartNumberMarker = $partNumber;
        }

        return [
            'bucket' => $bucket,
            'key' => $key,
            'uploadId' => $uploadId,
            'partNumberMarker' => $partNumberMarker,
            'nextPartNumberMarker' => $isTruncated ? $nextPartNumberMarker : 0,
            'maxParts' => $maxParts,
            'isTruncated' => $isTruncated,
            'parts' => $parts,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function listObjectKeys(string $bucket): array
    {
        $bucketPath = $this->bucketPath($bucket);
        if (! is_dir($bucketPath)) {
            return [];
        }

        $keys = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($bucketPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile()) {
                continue;
            }
            $fullPath = $fileInfo->getPathname();
            $relative = substr($fullPath, strlen($bucketPath) + 1);

            if ($fileInfo->getFilename() === self::DIRECTORY_OBJECT_MARKER) {
                $dir = dirname(str_replace('\\', '/', $relative));
                if ($dir !== '.' && $dir !== '') {
                    $keys[$dir] = true;
                }
                continue;
            }

            $key = str_replace('\\', '/', $relative);
            $keys[$key] = true;
        }

        return array_keys($keys);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function saveObjectMetadata(string $bucket, string $key, array $meta): void
    {
        $path = $this->objectMetaPath($bucket, $key);
        $this->ensureDirectory(dirname($path));
        file_put_contents($path, json_encode($meta, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadObjectMetadata(string $bucket, string $key): array
    {
        $path = $this->objectMetaPath($bucket, $key);
        if (! is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if ($json === false || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadMultipartManifest(string $uploadId): ?array
    {
        $path = $this->multipartManifestPath($uploadId);
        if (! is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function deleteMultipartUpload(string $uploadId): void
    {
        $manifestPath = $this->multipartManifestPath($uploadId);
        if (is_file($manifestPath)) {
            unlink($manifestPath);
        }

        $partsDirectory = $this->multipartPath . DIRECTORY_SEPARATOR . 'parts' . DIRECTORY_SEPARATOR . $uploadId;
        if (is_dir($partsDirectory)) {
            $this->deleteDirectoryRecursively($partsDirectory);
        }
    }

    private function multipartManifestPath(string $uploadId): string
    {
        return $this->multipartPath . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $uploadId . '.json';
    }

    private function bucketPath(string $bucket): string
    {
        $this->validateBucketName($bucket);
        return $this->rootPath . DIRECTORY_SEPARATOR . $bucket;
    }

    private function objectPath(string $bucket, string $key): string
    {
        $safeKey = $this->normalizeObjectKey($key);
        return $this->bucketPath($bucket) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safeKey);
    }

    private function objectMetaPath(string $bucket, string $key): string
    {
        $safeKey = $this->normalizeObjectKey($key);
        return $this->metaPath . DIRECTORY_SEPARATOR . $bucket . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safeKey) . '.json';
    }

    /**
     * Framework runtime directories that must never be addressable as buckets.
     *
     * A bucket is just a direct subdirectory of the storage root, and the deployed
     * root is `writable` (s3.storagePath), which is also CodeIgniter's own writable
     * directory — so `cache`, `logs`, `session` and `debugbar` sit alongside the real
     * buckets and every one of them satisfies the name regex below. Without this
     * deny-list they can be listed, read, written and deleted through the S3 API.
     *
     * Deliberately limited to the four names that are unambiguously framework
     * internals. `data` and `uploads` are NOT listed: they follow the same CI4
     * convention but could plausibly be real buckets here, and denying a live bucket
     * would break media rather than protect it.
     */
    private const RESERVED_BUCKETS = ['cache', 'logs', 'session', 'debugbar'];

    private function validateBucketName(string $bucket): void
    {
        // Underscores are forbidden by the AWS S3 spec because bucket names
        // double as DNS labels in virtual-hosted-style addressing. We use
        // path-style endpoints (use_path_style_endpoint=true) where the bucket
        // is in the URL path, not the hostname, so the DNS rule does not apply
        // and underscores are safe. MinIO and Ceph allow them for the same
        // reason.
        if (! preg_match('/^[a-z0-9_](?:[a-z0-9._-]{1,61})[a-z0-9_]$/', $bucket)) {
            throw new InvalidArgumentException('Invalid bucket name.');
        }

        if (in_array(strtolower($bucket), self::RESERVED_BUCKETS, true)) {
            throw new InvalidArgumentException('Invalid bucket name.');
        }
    }

    private function normalizeObjectKey(string $key): string
    {
        $key = ltrim(str_replace('\\', '/', $key), '/');
        if ($key === '') {
            throw new InvalidArgumentException('Object key must not be empty.');
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $key)) {
            throw new InvalidArgumentException('Invalid object key path.');
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === self::DIRECTORY_OBJECT_MARKER || str_starts_with($segment, self::MIGRATION_TMP_PREFIX)) {
                throw new InvalidArgumentException('Object key uses a reserved segment name.');
            }
        }

        return $key;
    }

    private function resolveObjectPathForRead(string $bucket, string $key): string
    {
        $basePath = $this->objectPath($bucket, $key);
        if (is_file($basePath)) {
            return $basePath;
        }

        $markerPath = $basePath . DIRECTORY_SEPARATOR . self::DIRECTORY_OBJECT_MARKER;
        if (is_file($markerPath)) {
            return $markerPath;
        }

        return $basePath;
    }

    private function resolveObjectPathForWrite(string $bucket, string $key): string
    {
        $basePath = $this->objectPath($bucket, $key);
        if (is_dir($basePath)) {
            return $basePath . DIRECTORY_SEPARATOR . self::DIRECTORY_OBJECT_MARKER;
        }

        return $basePath;
    }

    private function ensureParentDirectoriesWithMigration(string $bucket, string $key): void
    {
        $safeKey = $this->normalizeObjectKey($key);
        $segments = explode('/', $safeKey);

        if (count($segments) < 2) {
            return;
        }

        for ($i = 1; $i < count($segments); $i++) {
            $prefix = implode('/', array_slice($segments, 0, $i));
            $path = $this->objectPath($bucket, $prefix);

            if (is_file($path)) {
                $this->migrateLegacyObjectFileToDirectory($path);
                continue;
            }

            if (! is_dir($path)) {
                $this->ensureDirectory($path);
            }
        }
    }

    private function migrateLegacyObjectFileToDirectory(string $filePath): void
    {
        if (! is_file($filePath)) {
            return;
        }

        $tmpPath = $filePath . self::MIGRATION_TMP_PREFIX . uniqid('', true);
        if (! @rename($filePath, $tmpPath)) {
            throw new RuntimeException('Unable to migrate conflicting object key path: ' . $filePath);
        }

        try {
            $this->ensureDirectory($filePath);
            $markerPath = $filePath . DIRECTORY_SEPARATOR . self::DIRECTORY_OBJECT_MARKER;
            if (! @rename($tmpPath, $markerPath)) {
                throw new RuntimeException('Unable to finalize migrated object key path: ' . $filePath);
            }
        } catch (Throwable $e) {
            if (is_file($tmpPath)) {
                @rename($tmpPath, $filePath);
            }
            throw $e;
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (@mkdir($path, 0775, true) || is_dir($path)) {
            return;
        }

        $lastError = error_get_last();
        $reason = is_array($lastError) && isset($lastError['message']) ? $lastError['message'] : 'unknown error';
        throw new RuntimeException('Unable to create directory: ' . $path . ' (' . $reason . ')');
    }

    private function maybeCleanupMultipartUploads(): void
    {
        if (! $this->multipartAutoCleanup || $this->multipartMaxAgeSeconds <= 0) {
            return;
        }

        if ($this->multipartCleanupProbabilityPercent < 100) {
            if (random_int(1, 100) > $this->multipartCleanupProbabilityPercent) {
                return;
            }
        }

        $uploadsDir = $this->multipartPath . DIRECTORY_SEPARATOR . 'uploads';
        $files = glob($uploadsDir . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false || $files === []) {
            return;
        }

        $cutoff = time() - $this->multipartMaxAgeSeconds;
        $processed = 0;

        foreach ($files as $manifestPath) {
            if ($processed >= $this->multipartCleanupBatchSize) {
                break;
            }
            $processed++;

            $uploadId = pathinfo($manifestPath, PATHINFO_FILENAME);
            if ($uploadId === '') {
                continue;
            }

            $createdAt = filemtime($manifestPath) ?: time();
            $json = file_get_contents($manifestPath);
            if ($json !== false && $json !== '') {
                $decoded = json_decode($json, true);
                if (is_array($decoded) && isset($decoded['createdAt'])) {
                    $parsed = strtotime((string) $decoded['createdAt']);
                    if ($parsed !== false) {
                        $createdAt = $parsed;
                    }
                }
            }

            if ($createdAt <= $cutoff) {
                $this->deleteMultipartUpload($uploadId);
            }
        }
    }

    private function isDirectoryEmpty(string $path): bool
    {
        $items = scandir($path);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            return false;
        }

        return true;
    }

    private function cleanupEmptyParents(string $startDirectory, string $stopDirectory): void
    {
        $current = rtrim($startDirectory, "\\/");
        $stop = rtrim($stopDirectory, "\\/");

        while ($current !== '' && str_starts_with($current, $stop) && $current !== $stop) {
            if (! is_dir($current) || ! $this->isDirectoryEmpty($current)) {
                return;
            }
            rmdir($current);
            $current = dirname($current);
        }
    }

    private function deleteDirectoryRecursively(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());
            } else {
                unlink($fileInfo->getPathname());
            }
        }

        rmdir($path);
    }
}
