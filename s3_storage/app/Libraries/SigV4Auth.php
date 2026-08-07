<?php

namespace App\Libraries;

use CodeIgniter\HTTP\IncomingRequest;

class SigV4Auth
{
    private const MAX_PRESIGN_EXPIRES = 604800; // 7 days
    private const DEFAULT_CLOCK_SKEW_SECONDS = 900; // 15 minutes

    public function __construct(
        private readonly string $expectedAccessKey,
        private readonly string $expectedSecretKey,
        private readonly string $expectedRegion,
        private readonly bool $enforceSignatureV4,
        private readonly int $allowedClockSkewSeconds = self::DEFAULT_CLOCK_SKEW_SECONDS
    ) {
    }

    /**
     * @return array{ok: bool, status: int, code: string, message: string}
     */
    public function validate(IncomingRequest $request): array
    {
        if (! $this->enforceSignatureV4) {
            return ['ok' => true, 'status' => 200, 'code' => '', 'message' => ''];
        }

        if ($this->expectedAccessKey === '' || $this->expectedSecretKey === '') {
            return ['ok' => false, 'status' => 500, 'code' => 'InternalError', 'message' => 'S3 credentials are not configured on server.'];
        }

        $authorization = $this->getHeaderValue($request, 'Authorization');
        if ($authorization !== '') {
            return $this->validateAuthorizationHeader($request, $authorization);
        }

        $queryPairs = $this->parseRawQueryPairs();
        if ($this->hasQueryParam($queryPairs, 'X-Amz-Algorithm')) {
            return $this->validatePresignedQuery($request, $queryPairs);
        }

        return ['ok' => false, 'status' => 403, 'code' => 'AccessDenied', 'message' => 'Missing Authorization header or presigned URL signature.'];
    }

    /**
     * @param array<int, array{key: string, value: string}> $queryPairs
     * @return array{ok: bool, status: int, code: string, message: string}
     */
    private function validatePresignedQuery(IncomingRequest $request, array $queryPairs): array
    {
        $algorithm = $this->getQueryParam($queryPairs, 'X-Amz-Algorithm');
        if ($algorithm !== 'AWS4-HMAC-SHA256') {
            return ['ok' => false, 'status' => 400, 'code' => 'AuthorizationQueryParametersError', 'message' => 'X-Amz-Algorithm must be AWS4-HMAC-SHA256.'];
        }

        $credential = $this->getQueryParam($queryPairs, 'X-Amz-Credential');
        $signedHeadersRaw = $this->getQueryParam($queryPairs, 'X-Amz-SignedHeaders');
        $signature = $this->getQueryParam($queryPairs, 'X-Amz-Signature');
        $amzDate = $this->getQueryParam($queryPairs, 'X-Amz-Date');
        $expiresRaw = $this->getQueryParam($queryPairs, 'X-Amz-Expires');

        if ($credential === null || $signedHeadersRaw === null || $signature === null || $amzDate === null || $expiresRaw === null) {
            return ['ok' => false, 'status' => 400, 'code' => 'AuthorizationQueryParametersError', 'message' => 'Missing one or more required query authentication parameters.'];
        }

        if (! ctype_digit($expiresRaw)) {
            return ['ok' => false, 'status' => 400, 'code' => 'AuthorizationQueryParametersError', 'message' => 'X-Amz-Expires must be an integer value.'];
        }

        $expires = (int) $expiresRaw;
        if ($expires < 1 || $expires > self::MAX_PRESIGN_EXPIRES) {
            return ['ok' => false, 'status' => 400, 'code' => 'AuthorizationQueryParametersError', 'message' => 'X-Amz-Expires must be between 1 and 604800 seconds.'];
        }

        $requestTimestamp = $this->parseAmzDate($amzDate);
        if ($requestTimestamp === null) {
            return ['ok' => false, 'status' => 400, 'code' => 'AuthorizationQueryParametersError', 'message' => 'X-Amz-Date must match format YYYYMMDDTHHMMSSZ.'];
        }

        $now = time();
        if ($requestTimestamp - $now > $this->allowedClockSkewSeconds) {
            return ['ok' => false, 'status' => 403, 'code' => 'RequestTimeTooSkewed', 'message' => 'The difference between the request time and the server time is too large.'];
        }

        if ($now > ($requestTimestamp + $expires)) {
            return ['ok' => false, 'status' => 403, 'code' => 'AccessDenied', 'message' => 'Request has expired.'];
        }

        $credentialParts = explode('/', $credential);
        if (count($credentialParts) !== 5) {
            return ['ok' => false, 'status' => 400, 'code' => 'AuthorizationQueryParametersError', 'message' => 'Malformed X-Amz-Credential scope.'];
        }

        $scopeValidation = $this->validateCredentialScope($credentialParts, true);
        if (! $scopeValidation['ok']) {
            return $scopeValidation;
        }

        [, $dateScope, $regionScope] = $credentialParts;
        if (substr($amzDate, 0, 8) !== $dateScope) {
            return ['ok' => false, 'status' => 403, 'code' => 'AuthorizationQueryParametersError', 'message' => 'X-Amz-Date does not match credential date scope.'];
        }

        $signedHeaders = array_filter(explode(';', strtolower($signedHeadersRaw)));
        if ($signedHeaders === []) {
            return ['ok' => false, 'status' => 400, 'code' => 'AuthorizationQueryParametersError', 'message' => 'X-Amz-SignedHeaders must not be empty.'];
        }

        if (! in_array('host', $signedHeaders, true)) {
            return ['ok' => false, 'status' => 400, 'code' => 'AuthorizationQueryParametersError', 'message' => 'X-Amz-SignedHeaders must include host.'];
        }

        $canonicalHeaders = $this->buildCanonicalHeaders($request, array_values($signedHeaders));
        if ($canonicalHeaders === null) {
            return ['ok' => false, 'status' => 403, 'code' => 'SignatureDoesNotMatch', 'message' => 'Signed headers are missing from request.'];
        }

        $canonicalRequest = strtoupper($request->getMethod())
            . "\n"
            . $this->buildCanonicalUri()
            . "\n"
            . $this->buildCanonicalQueryStringFromPairs($queryPairs, ['x-amz-signature'])
            . "\n"
            . $canonicalHeaders
            . "\n"
            . implode(';', $signedHeaders)
            . "\n"
            . 'UNSIGNED-PAYLOAD';

        $stringToSign = 'AWS4-HMAC-SHA256'
            . "\n"
            . $amzDate
            . "\n"
            . $dateScope . '/' . $regionScope . '/s3/aws4_request'
            . "\n"
            . hash('sha256', $canonicalRequest);

        $signingKey = $this->getSigningKey($this->expectedSecretKey, $dateScope, $regionScope, 's3');
        $expectedSignature = hash_hmac('sha256', $stringToSign, $signingKey);

        if (! hash_equals(strtolower($expectedSignature), strtolower($signature))) {
            return ['ok' => false, 'status' => 403, 'code' => 'SignatureDoesNotMatch', 'message' => 'The request signature we calculated does not match the signature you provided.'];
        }

        return ['ok' => true, 'status' => 200, 'code' => '', 'message' => ''];
    }

    /**
     * @return array{ok: bool, status: int, code: string, message: string}
     */
    private function validateAuthorizationHeader(IncomingRequest $request, string $authorization): array
    {
        if (! str_starts_with($authorization, 'AWS4-HMAC-SHA256 ')) {
            return ['ok' => false, 'status' => 400, 'code' => 'InvalidRequest', 'message' => 'Unsupported Authorization algorithm.'];
        }

        $parsed = $this->parseAuthorizationHeader(substr($authorization, 17));
        if (! isset($parsed['Credential'], $parsed['SignedHeaders'], $parsed['Signature'])) {
            return ['ok' => false, 'status' => 400, 'code' => 'AuthorizationHeaderMalformed', 'message' => 'Malformed Authorization header.'];
        }

        $credentialParts = explode('/', $parsed['Credential']);
        if (count($credentialParts) !== 5) {
            return ['ok' => false, 'status' => 400, 'code' => 'AuthorizationHeaderMalformed', 'message' => 'Malformed Credential scope.'];
        }

        $scopeValidation = $this->validateCredentialScope($credentialParts);
        if (! $scopeValidation['ok']) {
            return $scopeValidation;
        }

        [$accessKey, $dateScope, $regionScope] = $credentialParts;
        if ($accessKey !== $this->expectedAccessKey) {
            return ['ok' => false, 'status' => 403, 'code' => 'InvalidAccessKeyId', 'message' => 'The AWS Access Key Id you provided does not exist.'];
        }

        $amzDate = $this->getHeaderValue($request, 'x-amz-date');
        if ($amzDate === '') {
            return ['ok' => false, 'status' => 403, 'code' => 'AccessDenied', 'message' => 'Missing x-amz-date header.'];
        }
        $requestTimestamp = $this->parseAmzDate($amzDate);
        if ($requestTimestamp === null) {
            return ['ok' => false, 'status' => 400, 'code' => 'AuthorizationHeaderMalformed', 'message' => 'x-amz-date must match format YYYYMMDDTHHMMSSZ.'];
        }

        if (substr($amzDate, 0, 8) !== $dateScope) {
            return ['ok' => false, 'status' => 403, 'code' => 'AuthorizationHeaderMalformed', 'message' => 'x-amz-date does not match Credential date scope.'];
        }

        $now = time();
        if (abs($now - $requestTimestamp) > $this->allowedClockSkewSeconds) {
            return ['ok' => false, 'status' => 403, 'code' => 'RequestTimeTooSkewed', 'message' => 'The difference between the request time and the server time is too large.'];
        }

        $signedHeaders = array_filter(explode(';', strtolower($parsed['SignedHeaders'])));
        if ($signedHeaders === []) {
            return ['ok' => false, 'status' => 400, 'code' => 'AuthorizationHeaderMalformed', 'message' => 'SignedHeaders cannot be empty.'];
        }

        $canonicalHeaders = $this->buildCanonicalHeaders($request, array_values($signedHeaders));
        if ($canonicalHeaders === null) {
            return ['ok' => false, 'status' => 403, 'code' => 'SignatureDoesNotMatch', 'message' => 'SignedHeaders does not match request headers.'];
        }

        $canonicalRequest = strtoupper($request->getMethod())
            . "\n"
            . $this->buildCanonicalUri()
            . "\n"
            . $this->buildCanonicalQueryString()
            . "\n"
            . $canonicalHeaders
            . "\n"
            . implode(';', $signedHeaders)
            . "\n"
            . $this->resolvePayloadHash($request);

        $stringToSign = 'AWS4-HMAC-SHA256'
            . "\n"
            . $amzDate
            . "\n"
            . $dateScope . '/' . $regionScope . '/s3/aws4_request'
            . "\n"
            . hash('sha256', $canonicalRequest);

        $signingKey = $this->getSigningKey($this->expectedSecretKey, $dateScope, $regionScope, 's3');
        $expectedSignature = hash_hmac('sha256', $stringToSign, $signingKey);

        if (! hash_equals($expectedSignature, $parsed['Signature'])) {
            return ['ok' => false, 'status' => 403, 'code' => 'SignatureDoesNotMatch', 'message' => 'The request signature we calculated does not match the signature you provided.'];
        }

        return ['ok' => true, 'status' => 200, 'code' => '', 'message' => ''];
    }

    /**
     * @param array<int, string> $credentialParts
     * @return array{ok: bool, status: int, code: string, message: string}
     */
    private function validateCredentialScope(array $credentialParts, bool $isQueryAuth = false): array
    {
        [$accessKey, , $regionScope, $serviceScope, $terminal] = $credentialParts;

        if ($terminal !== 'aws4_request' || $serviceScope !== 's3') {
            return ['ok' => false, 'status' => 403, 'code' => 'SignatureDoesNotMatch', 'message' => 'Credential scope is invalid.'];
        }

        if ($accessKey !== $this->expectedAccessKey) {
            return ['ok' => false, 'status' => 403, 'code' => 'InvalidAccessKeyId', 'message' => 'The AWS Access Key Id you provided does not exist.'];
        }

        if ($this->expectedRegion !== '' && $regionScope !== $this->expectedRegion) {
            $code = $isQueryAuth ? 'AuthorizationQueryParametersError' : 'AuthorizationHeaderMalformed';
            return ['ok' => false, 'status' => 403, 'code' => $code, 'message' => 'Credential region does not match server region.'];
        }

        return ['ok' => true, 'status' => 200, 'code' => '', 'message' => ''];
    }

    /**
     * @return array<string, string>
     */
    private function parseAuthorizationHeader(string $header): array
    {
        $result = [];
        $parts = array_map('trim', explode(',', $header));

        foreach ($parts as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $key = trim($key);
            $value = trim($value);
            if ($key === '' || $value === '') {
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<int, string> $signedHeaders
     */
    private function buildCanonicalHeaders(IncomingRequest $request, array $signedHeaders): ?string
    {
        $allHeaders = [];
        foreach ($request->headers() as $name => $header) {
            $allHeaders[strtolower($name)] = trim(preg_replace('/\s+/', ' ', $header->getValueLine()) ?? '');
        }

        if (! isset($allHeaders['host'])) {
            $allHeaders['host'] = $this->getServerHeaderValue('host');
        }

        $canonical = '';
        foreach ($signedHeaders as $headerName) {
            if (! array_key_exists($headerName, $allHeaders)) {
                [$found, $fallback] = $this->getServerHeaderValueWithPresence($headerName);
                if ($found) {
                    $allHeaders[$headerName] = $fallback;
                }
            }

            if (! array_key_exists($headerName, $allHeaders)) {
                // AWS SDKs may include transport headers like x-amz-user-agent
                // in SignedHeaders with an intentionally empty value.
                if ($headerName === 'x-amz-user-agent') {
                    $allHeaders[$headerName] = '';
                } else {
                return null;
                }
            }
            $canonical .= $headerName . ':' . $allHeaders[$headerName] . "\n";
        }

        return $canonical;
    }

    private function resolvePayloadHash(IncomingRequest $request): string
    {
        $payloadHash = $this->getHeaderValue($request, 'x-amz-content-sha256');
        return $payloadHash !== '' ? $payloadHash : 'UNSIGNED-PAYLOAD';
    }

    /**
     * The SHA-256 the client signed for its body, or '' when it did not commit to one.
     *
     * resolvePayloadHash() feeds this value into the canonical request, so the
     * signature covers the CLAIM. Nothing ever hashed the body to test the claim,
     * which is what makes a captured signed PUT re-usable with different content.
     * Returning it here lets the write path compare, without touching the signature
     * maths above (that stays byte-for-byte what it was).
     *
     * '' means "no commitment, nothing to verify":
     *   - UNSIGNED-PAYLOAD — the documented opt-out, used by presigned URLs
     *   - STREAMING-*      — aws-chunked; the wire bytes are chunk-framed and
     *                        legitimately do not hash to the object content
     *   - anything not a bare 64-char hex digest
     */
    public function signedPayloadHash(IncomingRequest $request): string
    {
        $claim = strtolower(trim($this->resolvePayloadHash($request)));

        return preg_match('/^[0-9a-f]{64}$/', $claim) === 1 ? $claim : '';
    }

    private function buildCanonicalUri(): string
    {
        $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($rawUri, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return '/';
        }

        $segments = explode('/', $path);
        $encoded = array_map(static function (string $segment): string {
            $value = rawurlencode(rawurldecode($segment));
            return str_replace('%7E', '~', $value);
        }, $segments);

        $canonical = implode('/', $encoded);
        if ($canonical === '') {
            return '/';
        }

        return str_starts_with($canonical, '/') ? $canonical : '/' . $canonical;
    }

    private function buildCanonicalQueryString(): string
    {
        return $this->buildCanonicalQueryStringFromPairs($this->parseRawQueryPairs());
    }

    /**
     * @param array<int, array{key: string, value: string}> $queryPairs
     * @param array<int, string> $excludeKeys
     */
    private function buildCanonicalQueryStringFromPairs(array $queryPairs, array $excludeKeys = []): string
    {
        $exclude = [];
        foreach ($excludeKeys as $key) {
            $exclude[strtolower($key)] = true;
        }

        $pairs = [];
        foreach ($queryPairs as $pair) {
            if (isset($exclude[strtolower($pair['key'])])) {
                continue;
            }
            $key = $this->awsEncode($pair['key']);
            $value = $this->awsEncode($pair['value']);
            $pairs[] = [$key, $value];
        }

        usort($pairs, static function (array $a, array $b): int {
            $keyCompare = strcmp($a[0], $b[0]);
            if ($keyCompare !== 0) {
                return $keyCompare;
            }
            return strcmp($a[1], $b[1]);
        });

        $result = [];
        foreach ($pairs as [$key, $value]) {
            $result[] = $key . '=' . $value;
        }

        return implode('&', $result);
    }

    /**
     * @return array<int, array{key: string, value: string}>
     */
    private function parseRawQueryPairs(): array
    {
        $rawUri = $_SERVER['REQUEST_URI'] ?? '';
        $query = parse_url($rawUri, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return [];
        }

        $pairs = [];
        foreach (explode('&', $query) as $rawPair) {
            if ($rawPair === '') {
                continue;
            }

            [$rawKey, $rawValue] = array_pad(explode('=', $rawPair, 2), 2, '');
            $pairs[] = [
                'key' => rawurldecode($rawKey),
                'value' => rawurldecode($rawValue),
            ];
        }

        return $pairs;
    }

    /**
     * @param array<int, array{key: string, value: string}> $queryPairs
     */
    private function hasQueryParam(array $queryPairs, string $name): bool
    {
        return $this->getQueryParam($queryPairs, $name) !== null;
    }

    /**
     * @param array<int, array{key: string, value: string}> $queryPairs
     */
    private function getQueryParam(array $queryPairs, string $name): ?string
    {
        foreach ($queryPairs as $pair) {
            if (strcasecmp($pair['key'], $name) === 0) {
                return $pair['value'];
            }
        }

        return null;
    }

    private function parseAmzDate(string $amzDate): ?int
    {
        $dt = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $amzDate, new \DateTimeZone('UTC'));
        if (! $dt instanceof \DateTimeImmutable) {
            return null;
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $dt->getTimestamp();
    }

    private function awsEncode(string $value): string
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }

    private function getSigningKey(string $secretKey, string $date, string $region, string $service): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function getHeaderValue(IncomingRequest $request, string $headerName): string
    {
        $value = trim($request->getHeaderLine($headerName));
        if ($value !== '') {
            return $value;
        }

        return $this->getServerHeaderValue($headerName);
    }

    private function getServerHeaderValue(string $headerName): string
    {
        [, $value] = $this->getServerHeaderValueWithPresence($headerName);
        return $value;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function getServerHeaderValueWithPresence(string $headerName): array
    {
        $normalized = strtoupper(str_replace('-', '_', $headerName));
        $candidates = [
            'HTTP_' . $normalized,
            'REDIRECT_HTTP_' . $normalized,
        ];

        if (strcasecmp($headerName, 'Authorization') === 0) {
            $candidates[] = 'HTTP_AUTHORIZATION';
            $candidates[] = 'REDIRECT_HTTP_AUTHORIZATION';
            $candidates[] = 'X_HTTP_AUTHORIZATION';
        }

        if (strcasecmp($headerName, 'host') === 0) {
            $candidates[] = 'HTTP_HOST';
        }

        foreach ($candidates as $key) {
            if (array_key_exists($key, $_SERVER)) {
                return [true, trim((string) $_SERVER[$key])];
            }
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, $headerName) === 0) {
                    return [true, trim((string) $value)];
                }
            }
        }

        return [false, ''];
    }
}
