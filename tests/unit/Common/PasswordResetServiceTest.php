<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/PasswordResetService.php';

use App\Libraries\PasswordResetService;

/**
 * Phase 5 (web) — password-reset token generate/verify. Pure: SHA-256 hash of a
 * random token is stored; the raw token is e-mailed. Verify checks hash + expiry.
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §3.3
 */
final class PasswordResetServiceTest extends TestCase
{
    public function testGeneratedTokenVerifiesAgainstItsHash(): void
    {
        $svc = new PasswordResetService();
        $pair = $svc->generate();

        $this->assertNotEmpty($pair['token']);
        $this->assertNotEmpty($pair['hash']);
        $this->assertTrue($svc->verify($pair['token'], $pair['hash'], 1_000, 500)); // now < expiry
    }

    public function testExpiredTokenFails(): void
    {
        $svc = new PasswordResetService();
        $pair = $svc->generate();
        $this->assertFalse($svc->verify($pair['token'], $pair['hash'], 1_000, 1_001)); // now > expiry
    }

    public function testTamperedTokenFails(): void
    {
        $svc = new PasswordResetService();
        $pair = $svc->generate();
        $this->assertFalse($svc->verify('not-the-token', $pair['hash'], 1_000, 500));
    }
}
