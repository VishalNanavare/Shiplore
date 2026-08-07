<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Audit B, P1e — the S3 gateway never checked an upload against the hash the
 * client signed for it.
 *
 * SigV4 puts `x-amz-content-sha256` into the canonical request, so the signature
 * covers the CLAIMED digest. `SigV4Auth::resolvePayloadHash()` returned that claim
 * verbatim and nothing ever hashed the body, so the claim went untested: capture a
 * signed PUT (the service does not force HTTPS), replace the body, leave the header
 * alone, and the signature still verifies against attacker-chosen bytes.
 *
 * S3Storage has no framework dependencies, so these are real behavioural tests
 * against real files in a temp directory, not source assertions.
 *
 * Shipping log-only first is deliberate — see Config\S3Server::$verifyPayloadHash.
 */
final class S3PayloadHashTest extends CIUnitTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        // s3_storage is a separate CI4 application whose App\ namespace is not on the
        // main app's autoloader. Nothing in the main app declares these names.
        require_once ROOTPATH . 's3_storage/app/Libraries/S3Storage.php';

        $this->root = sys_get_temp_dir() . '/s3hash_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->rmrf($this->root);
        }
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @param int $cap verifyPayloadMaxBytes; 0 disables the cap */
    private function storage(int $cap = 0): object
    {
        $class   = 'App\Libraries\S3Storage';
        $storage = new $class($this->root, false, 86_400, 0, 200, $cap);
        $storage->createBucket('media');

        return $storage;
    }

    /** @return array<string,mixed> */
    private function put(object $storage, string $key, string $body, string $expect = ''): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $body);
        rewind($stream);

        try {
            return $storage->putObject('media', $key, $stream, [], $expect);
        } finally {
            fclose($stream);
        }
    }

    /** A body matching its signed digest hashes to exactly that digest. */
    public function testMatchingPayloadHashesToTheSignedDigest(): void
    {
        $body   = 'hello world';
        $result = $this->put($this->storage(), 'a.txt', $body, hash('sha256', $body));

        $this->assertSame(hash('sha256', $body), $result['sha256']);
        $this->assertSame($body, file_get_contents($result['path']), 'the object itself must be stored unchanged');
    }

    /** The whole point: a swapped body no longer hashes to the signed digest. */
    public function testTamperedBodyDoesNotMatchTheSignedDigest(): void
    {
        $signed = hash('sha256', 'the bytes the client signed');
        $result = $this->put($this->storage(), 'b.txt', 'ENTIRELY DIFFERENT BYTES', $signed);

        $this->assertNotSame('', $result['sha256'], 'a digest must have been computed to compare against');
        $this->assertFalse(
            hash_equals($signed, $result['sha256']),
            'a body that does not match the signed digest must be detectable — this is the tamper the signature alone cannot see',
        );
    }

    /**
     * No claim means no work. UNSIGNED-PAYLOAD and the STREAMING-* forms are
     * legitimate, so they must cost nothing rather than being treated as failures.
     */
    public function testNoClaimSkipsHashingEntirely(): void
    {
        $result = $this->put($this->storage(), 'c.txt', 'hello world', '');

        $this->assertSame('', $result['sha256'], 'hashing must not run when the client committed to no digest');
    }

    /** Over the size cap the object is stored and the check is skipped, not failed. */
    public function testOversizeUploadSkipsVerificationButStillStores(): void
    {
        $body   = str_repeat('x', 2048);
        $result = $this->put($this->storage(1024), 'd.bin', $body, hash('sha256', $body));

        $this->assertSame('', $result['sha256'], 'the cap must skip the extra pass');
        $this->assertSame(2048, $result['size']);
        $this->assertSame($body, file_get_contents($result['path']), 'a skipped check must never mean a dropped upload');
    }

    /** A zero cap means unlimited, not "verify nothing". */
    public function testZeroCapMeansNoLimit(): void
    {
        $body   = str_repeat('y', 100_000);
        $result = $this->put($this->storage(0), 'e.bin', $body, hash('sha256', $body));

        $this->assertSame(hash('sha256', $body), $result['sha256']);
    }

    /** The new parameter is additive: omitting it reproduces the old behaviour exactly. */
    public function testOmittingTheExpectedHashPreservesPreviousBehaviour(): void
    {
        $body    = 'hello world';
        $storage = $this->storage();

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $body);
        rewind($stream);
        $result = $storage->putObject('media', 'f.txt', $stream, []);
        fclose($stream);

        $this->assertSame('', $result['sha256'], 'callers that do not opt in must not pay for a hash');
        $this->assertSame(md5($body), $result['etag'], 'the ETag contract must be unchanged');
        $this->assertSame(strlen($body), $result['size']);
        $this->assertArrayHasKey('mtime', $result);
    }

    /** Only a bare 64-char lowercase hex digest counts as a commitment. */
    public function testOnlyABareHexDigestIsTreatedAsACommitment(): void
    {
        $src = (string) file_get_contents(ROOTPATH . 's3_storage/app/Libraries/SigV4Auth.php');

        $this->assertMatchesRegularExpression(
            '/public function signedPayloadHash\(IncomingRequest \$request\): string/',
            $src,
            'the claimed digest must be exposed for the write path to compare against',
        );
        $this->assertMatchesRegularExpression(
            "~preg_match\('/\^\[0-9a-f\]\{64\}\\\$/', \\\$claim\) === 1 \? \\\$claim : ''~",
            $src,
            'UNSIGNED-PAYLOAD, STREAMING-* and any other non-digest must resolve to "" so they are skipped, not rejected',
        );
    }

    /** Enforcement must not leave the rejected object on disk. */
    public function testEnforcedMismatchDeletesTheStoredObject(): void
    {
        $src = (string) file_get_contents(ROOTPATH . 's3_storage/app/Controllers/S3Controller.php');

        $this->assertMatchesRegularExpression(
            '/if \(! \$enforcing\) \{\s*return null;\s*\}\s*\$this->storage->deleteObject\(\$bucket, \$key\);/',
            $src,
            'under enforce the object written before the check must be removed, and under log-only it must be kept',
        );
        $this->assertStringContainsString(
            "'XAmzContentSHA256Mismatch'",
            $src,
            'a rejected upload should return the real S3 error code clients already handle',
        );
    }

    /** Default is log-only, and an unrecognised value must not silently disable the check. */
    public function testConfigDefaultsToLogAndFallsBackToLog(): void
    {
        $src = (string) file_get_contents(ROOTPATH . 's3_storage/app/Config/S3Server.php');

        $this->assertMatchesRegularExpression(
            '/public string \$verifyPayloadHash = \'log\';/',
            $src,
            'the check must ship log-only, per the rollout convention',
        );
        $this->assertMatchesRegularExpression(
            "/in_array\(\\\$mode, \['off', 'log', 'enforce'\], true\) \? \\\$mode : 'log'/",
            $src,
            'a typo in .env must fall back to log, never to off',
        );
    }
}
