<?php

declare(strict_types=1);

namespace App\Libraries\Storage;

use Throwable;

/**
 * DocumentStorage — presigned-URL uploads for vendor documents (PDF / images).
 *
 * When the S3 integration is configured (admin Integrations → S3 Storage) it
 * issues a real presigned PUT URL via the AWS SDK, so the browser uploads the
 * file straight to S3. With NO config it falls back to a "dummy" mode that
 * targets our own local PUT endpoint and stores under writable/uploads — so the
 * whole dropzone → presign → AJAX-PUT → confirm flow works today, and dropping in
 * real S3 credentials is a zero-code change.
 *
 * @see App\Controllers\Vendor\DocumentUploadController
 */
final class DocumentStorage
{
    /** Allowed upload mime types => extension. */
    private const ALLOWED  = [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
        'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
    ];
    private const MAX_BYTES = 10_485_760; // 10 MB per file

    /** Broader allow-list for the general media library (vendor/shop files). */
    private const MEDIA_ALLOWED = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
        'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg',
        'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
        'audio/mpeg' => 'mp3', 'audio/wav' => 'wav',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/plain' => 'txt', 'text/csv' => 'csv',
        'application/zip' => 'zip', 'application/x-zip-compressed' => 'zip',
    ];
    private const MEDIA_MAX_BYTES = 209_715_200; // 200 MB per file (video-friendly)

    /** @param array<string,mixed> $cfg saved S3 config: endpoint, bucket, region, access_key, secret_key */
    public function __construct(private array $cfg) {}

    public function configured(): bool
    {
        // 'local' mode (admin toggle) forces same-origin storage — used when the
        // configured endpoint isn't a real S3 API (e.g. a plain nginx host that
        // 405s PUTs and has no CORS), so browser presigned uploads stop failing.
        if ($this->str('mode') === 'local') {
            return false;
        }

        return $this->str('endpoint') !== '' && $this->str('bucket') !== '';
    }

    public function bucket(): string
    {
        return $this->str('bucket') ?: 'vendor-documents';
    }

    public function extFor(string $mime): ?string
    {
        return self::ALLOWED[strtolower(trim($mime))] ?? null;
    }

    /**
     * Build an upload target for one file. Returns the URL the browser should
     * PUT the bytes to (a real presigned S3 URL, or our local dummy endpoint).
     *
     * @return array{ok:bool,message?:string,key?:string,url?:string,method?:string,mode?:string,headers?:array<string,string>}
     */
    public function presign(int $vendorId, string $filename, string $mime, int $size): array
    {
        $ext = $this->extFor($mime);
        if ($ext === null) {
            return ['ok' => false, 'message' => 'Only PDF and image files are allowed.'];
        }
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return ['ok' => false, 'message' => 'Each file must be between 1 byte and 10 MB.'];
        }

        $key = 'vendors/' . $vendorId . '/documents/' . date('Y/m') . '/' . bin2hex(random_bytes(8)) . '.' . $ext;

        return $this->buildUpload($key, $mime, 'vendor/kyc/put');
    }

    public function extForMedia(string $mime): ?string
    {
        return self::MEDIA_ALLOWED[strtolower(trim($mime))] ?? null;
    }

    public function mediaMaxBytes(): int
    {
        return self::MEDIA_MAX_BYTES;
    }

    /**
     * Build an upload target for one general-media file, owned by the vendor or one
     * of its shops. Same presigned-PUT mechanics as documents, broader file types.
     *
     * @return array{ok:bool,message?:string,key?:string,url?:string,method?:string,mode?:string,headers?:array<string,string>}
     */
    public function presignMedia(int $vendorId, ?int $shopId, string $filename, string $mime, int $size): array
    {
        $ext = $this->extForMedia($mime);
        if ($ext === null) {
            return ['ok' => false, 'message' => 'That file type is not allowed.'];
        }
        if ($size <= 0 || $size > self::MEDIA_MAX_BYTES) {
            return ['ok' => false, 'message' => 'Each file must be between 1 byte and 200 MB.'];
        }

        $prefix = $shopId !== null && $shopId > 0
            ? 'vendors/' . $vendorId . '/shops/' . $shopId . '/media/'
            : 'vendors/' . $vendorId . '/media/';
        $key = $prefix . date('Y/m') . '/' . bin2hex(random_bytes(8)) . '.' . $ext;

        return $this->buildUpload($key, $mime, 'vendor/media/put');
    }

    /** A time-limited URL to view/download an object (presigned GET, or local dummy serve). */
    public function presignGet(string $key, string $dummyRoute = 'vendor/kyc/file'): string
    {
        if ($this->configured()) {
            try {
                $client  = $this->client();
                $command = $client->getCommand('GetObject', ['Bucket' => $this->bucket(), 'Key' => $key]);

                return (string) $client->createPresignedRequest($command, '+15 minutes')->getUri();
            } catch (Throwable $e) {
                log_message('error', 'DocumentStorage presignGet failed: ' . $e->getMessage());
            }
        }

        return site_url($dummyRoute . '?key=' . rawurlencode($key));
    }

    /**
     * Shared presigned-PUT builder (real S3 or local dummy endpoint).
     *
     * @return array{ok:bool,key:string,url:string,method:string,mode:string,headers:array<string,string>}
     */
    private function buildUpload(string $key, string $mime, string $dummyPutRoute): array
    {
        if ($this->configured()) {
            try {
                $client  = $this->client();
                $command = $client->getCommand('PutObject', ['Bucket' => $this->bucket(), 'Key' => $key, 'ContentType' => $mime]);
                $request = $client->createPresignedRequest($command, '+15 minutes');

                return ['ok' => true, 'mode' => 's3', 'key' => $key, 'url' => (string) $request->getUri(), 'method' => 'PUT', 'headers' => ['Content-Type' => $mime]];
            } catch (Throwable $e) {
                log_message('error', 'DocumentStorage buildUpload failed, using dummy: ' . $e->getMessage());
            }
        }

        // Dummy mode PUTs land on OUR origin with the session cookie attached, so
        // they are state-changing same-origin requests and must carry CSRF like
        // any other. The uploader in app/Views/vendor/{documents/upload,media/index}.php
        // already forwards this array verbatim as the fetch() headers, so handing
        // the token back here is all that is needed on the client side.
        //
        // Deliberately NOT added to the s3 branch above: that PUT goes to AWS, and
        // sending our CSRF token would both leak it to a third party and break the
        // presigned signature, which covers the header set.
        return ['ok' => true, 'mode' => 'dummy', 'key' => $key, 'url' => site_url($dummyPutRoute . '?key=' . rawurlencode($key)), 'method' => 'PUT', 'headers' => ['Content-Type' => $mime, csrf_header() => csrf_hash()]];
    }

    /**
     * Server-side upload of in-memory bytes to S3 (used by MediaService so product
     * images, generated PDFs and exports flow through the same store). Returns
     * false when S3 isn't configured or the put fails — callers fall back to local.
     */
    public function putBytes(string $key, string $bytes, string $mime): bool
    {
        if (! $this->configured()) {
            return false;
        }
        try {
            $this->client()->putObject([
                'Bucket' => $this->bucket(), 'Key' => $key, 'Body' => $bytes, 'ContentType' => $mime,
            ]);

            return true;
        } catch (Throwable $e) {
            log_message('error', 'DocumentStorage putBytes failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Round-trip self-test: write → read → delete a tiny test object so an admin
     * can confirm the S3 credentials/endpoint actually accept writes (not just
     * serve files). Returns a human-readable verdict with the real HTTP status.
     *
     * @return array{ok:bool,message:string}
     */
    public function selfTest(): array
    {
        if ($this->str('mode') === 'local') {
            return ['ok' => true, 'message' => 'Local storage mode is ON — uploads are stored on this server (writable/uploads) and served same-origin (no S3, no CORS). Change storage_mode to "s3" once you have a real S3-compatible endpoint.'];
        }
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Not configured — set endpoint and bucket first.'];
        }
        $key = 'media/_selftest/' . bin2hex(random_bytes(8)) . '.txt';
        try {
            $client = $this->client();
            $client->putObject(['Bucket' => $this->bucket(), 'Key' => $key, 'Body' => 'selftest', 'ContentType' => 'text/plain']);
            $got = (string) $client->getObject(['Bucket' => $this->bucket(), 'Key' => $key])['Body'];
            $client->deleteObject(['Bucket' => $this->bucket(), 'Key' => $key]);

            return $got === 'selftest'
                ? ['ok' => true, 'message' => 'Success — wrote, read and deleted a test object in bucket "' . $this->bucket() . '".']
                : ['ok' => false, 'message' => 'Wrote but read back unexpected content — check bucket/path config.'];
        } catch (\Aws\S3\Exception\S3Exception $e) {
            $resp   = $e->getResponse();
            $status = $resp ? $resp->getStatusCode() : 0;
            $hint   = $status === 405
                ? ' — the endpoint returned "405 Not Allowed" (it is a plain file server, e.g. nginx, not an S3 API). Point "endpoint" at a real S3-compatible host (AWS S3, MinIO, Wasabi, DO Spaces).'
                : ($status === 403 ? ' — access denied; check client_key / client_secret and bucket permissions.' : '');

            return ['ok' => false, 'message' => 'S3 write failed (HTTP ' . $status . ' ' . ($e->getAwsErrorCode() ?: 'error') . ')' . $hint];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'S3 test failed: ' . $e->getMessage()];
        }
    }

    /** Server-side read of object bytes from S3 (null when unconfigured/missing). */
    public function getBytes(string $key): ?string
    {
        if (! $this->configured() || $key === '') {
            return null;
        }
        try {
            $result = $this->client()->getObject(['Bucket' => $this->bucket(), 'Key' => $key]);

            return (string) $result['Body'];
        } catch (Throwable $e) {
            log_message('error', 'DocumentStorage getBytes failed: ' . $e->getMessage());

            return null;
        }
    }

    /** Delete an object (S3 DeleteObject, or the local dummy file). */
    public function delete(string $key): void
    {
        if ($key === '') {
            return;
        }
        if ($this->configured()) {
            try {
                $this->client()->deleteObject(['Bucket' => $this->bucket(), 'Key' => $key]);
            } catch (Throwable $e) {
                log_message('error', 'DocumentStorage delete failed: ' . $e->getMessage());
            }

            return;
        }
        // A stored object_key that escapes the upload root would turn delete()
        // into an arbitrary file-deletion primitive. Refuse rather than unlink.
        try {
            $path = $this->dummyPath($key);
        } catch (\InvalidArgumentException $e) {
            log_message('error', 'DocumentStorage delete refused unsafe key: ' . $key);

            return;
        }
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Dummy receiver: persist the uploaded bytes locally. */
    public function saveDummy(string $key, $stream): bool
    {
        try {
            $path = $this->dummyPath($key);
        } catch (\InvalidArgumentException $e) {
            log_message('error', 'DocumentStorage write refused unsafe key: ' . $key);

            return false;
        }
        @mkdir(dirname($path), 0775, true);
        $out = @fopen($path, 'wb');
        if (! is_resource($out)) {
            return false;
        }
        try {
            stream_copy_to_stream($stream, $out);
        } finally {
            fclose($out);
        }

        return is_file($path);
    }

    /**
     * Local filesystem path for a storage key, guaranteed to stay inside
     * WRITEPATH/uploads.
     *
     * The previous sanitiser was `preg_replace('#[^a-zA-Z0-9_\-/.]#', '_', $key)`,
     * whose character class whitelists BOTH '.' and '/'. A key of
     * "vendors/7/../../../../s3_storage/.env" therefore survived it byte for byte
     * and resolved outside the upload root. Because the vendor `ownsKey()` checks
     * are prefix-only ("starts with vendors/{id}/"), that gave any vendor-panel
     * user an arbitrary file READ through the /vendor/{kyc,media}/file endpoints
     * and an arbitrary file WRITE — i.e. remote code execution — through the raw
     * PUT upload endpoints, which reach saveDummy().
     *
     * Keys are now normalised segment by segment and any attempt to traverse
     * above the root is rejected outright rather than sanitised into something
     * that still escapes. Legitimate generated keys (vendors/7/media/2026/07/
     * ab12cd34.jpg) are unaffected — they contain no '..' segment.
     *
     * @throws \InvalidArgumentException when the key escapes the upload root.
     */
    public function dummyPath(string $key): string
    {
        return rtrim(WRITEPATH, '/\\') . '/uploads/' . $this->safeKey($key);
    }

    /**
     * Normalise a storage key to a relative path that cannot escape its root.
     *
     * @throws \InvalidArgumentException on traversal, null bytes, or an empty key.
     */
    private function safeKey(string $key): string
    {
        // A null byte truncates the path in the underlying C calls.
        if (str_contains($key, "\0")) {
            throw new \InvalidArgumentException('Invalid storage key.');
        }

        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $key)) as $segment) {
            // Empty ("a//b", or a leading "/" making the path absolute) and "."
            // segments are noise: drop them rather than reject the whole key.
            if ($segment === '' || $segment === '.') {
                continue;
            }
            // "..": the only segment that can climb out. Never rewrite it — a
            // rewrite is what made the original sanitiser exploitable.
            if ($segment === '..') {
                throw new \InvalidArgumentException('Invalid storage key.');
            }
            // Same character class as before, minus the separators already handled.
            $segments[] = (string) preg_replace('#[^a-zA-Z0-9_\-.]#', '_', $segment);
        }

        if ($segments === []) {
            throw new \InvalidArgumentException('Invalid storage key.');
        }

        return implode('/', $segments);
    }

    private function client(): \Aws\S3\S3Client
    {
        return new \Aws\S3\S3Client([
            'version'                 => 'latest',
            'region'                  => $this->str('region') ?: 'us-east-1',
            'endpoint'                => $this->str('endpoint'),
            'use_path_style_endpoint' => true,
            'credentials'             => ['key' => $this->str('access_key'), 'secret' => $this->str('secret_key')],
        ]);
    }

    private function str(string $key): string
    {
        return trim((string) ($this->cfg[$key] ?? ''));
    }
}
