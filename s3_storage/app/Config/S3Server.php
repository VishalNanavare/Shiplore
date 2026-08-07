<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class S3Server extends BaseConfig
{
    /**
     * Root directory for bucket/object storage.
     */
    public string $storagePath = WRITEPATH . 's3data';

    /**
     * Region exposed in S3 XML responses.
     */
    public string $region = 'us-east-1';

    /**
     * Shared credentials for signature validation.
     * For multi-tenant keys, replace with DB-backed lookup.
     */
    public string $accessKey = '';
    public string $secretKey = '';

    /**
     * If false, accepts all requests without Signature V4 validation.
     */
    public bool $enforceSignatureV4 = true;

    /**
     * Bucket that S3Controller::isPublicReadRequest()'s anonymous-read prefix
     * allow-list applies to.
     *
     * That allow-list takes a $bucket argument but never consulted it, so the ~31
     * "public media" prefixes (including `payments/invoice/`, `tickets/` and
     * `reports/bulk/`) were anonymously readable in EVERY bucket, not just the
     * media one. Set this to the media bucket to scope them.
     *
     * Left empty by default so behaviour is unchanged until it is configured —
     * setting it is a narrowing, and narrowing the wrong name would 403 live
     * public media. Configure via `s3.publicReadBucket` in .env.
     */
    public string $publicReadBucket = '';

    /**
     * CORS policy.
     * Keep policy here so application logic does not duplicate values.
     */
    public bool $enableCors = true;
    /** @var list<string> */
    public array $corsAllowedOrigins = [];
    /** @var list<string> */
    public array $corsAllowedMethods = ['GET', 'PUT', 'POST', 'DELETE', 'HEAD', 'OPTIONS'];
    /** @var list<string> */
    public array $corsAllowedHeaders = [
        'accept',
        'authorization',
        'cache-control',
        'content-disposition',
        'content-encoding',
        'content-language',
        'content-type',
        'expires',
        'x-amz-acl',
        'x-amz-content-sha256',
        'x-amz-date',
        'x-amz-meta-*',
        'x-amz-security-token',
        'x-amz-sdk-checksum-algorithm',
        'x-amz-user-agent',
        'x-amz-storage-class',
        'x-amz-server-side-encryption',
        'x-amz-server-side-encryption-aws-kms-key-id',
    ];
    /** @var list<string> */
    public array $corsExposeHeaders = ['ETag', 'x-amz-request-id', 'x-amz-id-2'];
    public int $corsMaxAge = 86400;
    public bool $corsAllowCredentials = false;

    /**
     * Security/abuse controls.
     * maxRangeBytes: 0 disables limit.
     */
    public int $maxRangeBytes = 8_388_608; // 8 MiB
    public int $headerAuthMaxSkewSeconds = 900; // 15 minutes

    /**
     * Multipart lifecycle cleanup.
     */
    public bool $multipartAutoCleanup = true;
    public int $multipartMaxAgeSeconds = 86_400; // 24h
    public int $multipartCleanupProbabilityPercent = 5; // run on ~5% of multipart requests
    public int $multipartCleanupBatchSize = 200;

    public function __construct()
    {
        parent::__construct();

        $storagePath = trim((string) env('s3.storagePath', $this->storagePath), " \t\n\r\0\x0B\"'");
        if ($storagePath === '') {
            $storagePath = WRITEPATH . 's3data';
        }
        $this->storagePath = rtrim($storagePath, "\\/");

        $this->region = trim((string) env('s3.region', (string) env('S3_REGION', $this->region)), " \t\n\r\0\x0B\"'");
        $this->accessKey = trim((string) env('s3.accessKey', (string) env('S3_ACCESS_KEY', $this->accessKey)), " \t\n\r\0\x0B\"'");
        $this->secretKey = trim((string) env('s3.secretKey', (string) env('S3_SECRET_KEY', $this->secretKey)), " \t\n\r\0\x0B\"'");
        $this->enforceSignatureV4 = filter_var(env('s3.enforceSignatureV4', $this->enforceSignatureV4), FILTER_VALIDATE_BOOLEAN);
        $this->publicReadBucket = trim((string) env('s3.publicReadBucket', $this->publicReadBucket), " \t\n\r\0\x0B\"'");
        $this->enableCors = filter_var(env('s3.enableCors', $this->enableCors), FILTER_VALIDATE_BOOLEAN);
        $originsEnv = (string) env('s3.cors.origins', (string) env('s3.corsAllowOrigin', implode(',', $this->corsAllowedOrigins)));
        $this->corsAllowedOrigins = $this->parseCsv($originsEnv, $this->corsAllowedOrigins, false);
        $this->corsAllowedMethods = $this->parseCsv((string) env('s3.cors.methods', implode(',', $this->corsAllowedMethods)), $this->corsAllowedMethods, true);
        $this->corsAllowedHeaders = $this->parseCsv((string) env('s3.cors.headers', implode(',', $this->corsAllowedHeaders)), $this->corsAllowedHeaders, false);
        $this->corsExposeHeaders = $this->parseCsv((string) env('s3.cors.exposeHeaders', implode(',', $this->corsExposeHeaders)), $this->corsExposeHeaders, false);
        $this->corsMaxAge = max(0, (int) env('s3.cors.maxAge', $this->corsMaxAge));
        $this->corsAllowCredentials = filter_var(env('s3.cors.allowCredentials', $this->corsAllowCredentials), FILTER_VALIDATE_BOOLEAN);
        $this->maxRangeBytes = max(0, (int) env('s3.maxRangeBytes', $this->maxRangeBytes));
        $this->headerAuthMaxSkewSeconds = max(1, (int) env('s3.headerAuthMaxSkewSeconds', $this->headerAuthMaxSkewSeconds));
        $this->multipartAutoCleanup = filter_var(env('s3.multipart.autoCleanup', $this->multipartAutoCleanup), FILTER_VALIDATE_BOOLEAN);
        $this->multipartMaxAgeSeconds = max(0, (int) env('s3.multipart.maxAgeSeconds', $this->multipartMaxAgeSeconds));
        $this->multipartCleanupProbabilityPercent = max(0, min(100, (int) env('s3.multipart.cleanupProbabilityPercent', $this->multipartCleanupProbabilityPercent)));
        $this->multipartCleanupBatchSize = max(1, (int) env('s3.multipart.cleanupBatchSize', $this->multipartCleanupBatchSize));
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    private function parseCsv(string $raw, array $default, bool $normalizeUpper): array
    {
        $trimmed = trim($raw, " \t\n\r\0\x0B\"'");
        if ($trimmed === '') {
            return $default;
        }

        $parts = array_filter(array_map(static function (string $item) use ($normalizeUpper): string {
            $value = trim($item, " \t\n\r\0\x0B\"'");
            if ($value === '') {
                return '';
            }
            return $normalizeUpper ? strtoupper($value) : strtolower($value);
        }, explode(',', $trimmed)));

        if ($parts === []) {
            return $default;
        }

        return array_values(array_unique($parts));
    }
}
