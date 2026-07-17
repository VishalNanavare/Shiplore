<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/TokenException.php';
require_once __DIR__ . '/../../../app/Libraries/TokenService.php';

use App\Libraries\TokenService;
use App\Libraries\TokenException;

/**
 * Phase 6 — JWT (HS256) issue/verify. Pure PHP (hash_hmac), deterministic via injected $now.
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §2
 */
final class TokenServiceTest extends TestCase
{
    private string $secret = 'test-signing-secret';

    public function testRoundTripReturnsClaimsWithIatExp(): void
    {
        $svc   = new TokenService();
        $now   = 1_700_000_000;
        $token = $svc->issue(['sub' => 7, 'typ' => 'vendor', 'scopes' => [['type' => 'vendor', 'id' => 1]]], 900, $this->secret, $now);
        $claims = $svc->verify($token, $this->secret, $now + 10);

        $this->assertSame(7, $claims['sub']);
        $this->assertSame('vendor', $claims['typ']);
        $this->assertSame($now, $claims['iat']);
        $this->assertSame($now + 900, $claims['exp']);
        $this->assertSame([['type' => 'vendor', 'id' => 1]], $claims['scopes']);
    }

    public function testExpiredTokenThrows(): void
    {
        $svc   = new TokenService();
        $now   = 1_700_000_000;
        $token = $svc->issue(['sub' => 1], 60, $this->secret, $now);

        $this->expectException(TokenException::class);
        $svc->verify($token, $this->secret, $now + 61); // past exp
    }

    public function testTamperedPayloadThrows(): void
    {
        $svc   = new TokenService();
        $now   = 1_700_000_000;
        $token = $svc->issue(['sub' => 1, 'typ' => 'customer'], 900, $this->secret, $now);

        [$h, $p, $s] = explode('.', $token);
        $forged = $h . '.' . rtrim(strtr(base64_encode('{"sub":999,"typ":"platform"}'), '+/', '-_'), '=') . '.' . $s;

        $this->expectException(TokenException::class);
        $svc->verify($forged, $this->secret, $now + 10);
    }

    public function testWrongSecretThrows(): void
    {
        $svc   = new TokenService();
        $now   = 1_700_000_000;
        $token = $svc->issue(['sub' => 1], 900, $this->secret, $now);

        $this->expectException(TokenException::class);
        $svc->verify($token, 'wrong-secret', $now + 10);
    }

    public function testMalformedTokenThrows(): void
    {
        $svc = new TokenService();
        $this->expectException(TokenException::class);
        $svc->verify('not-a-jwt', $this->secret, 1_700_000_000);
    }
}
