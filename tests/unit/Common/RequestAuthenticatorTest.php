<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/TokenException.php';
require_once __DIR__ . '/../../../app/Libraries/AuthException.php';
require_once __DIR__ . '/../../../app/Libraries/TokenService.php';
require_once __DIR__ . '/../../../app/Libraries/PolicyEngine.php';
require_once __DIR__ . '/../../../app/Libraries/CapabilityResolver.php';
require_once __DIR__ . '/../../../app/Libraries/RequestAuthenticator.php';

use App\Libraries\CapabilityResolver;
use App\Libraries\RequestAuthenticator;
use App\Libraries\AuthException;
use App\Libraries\TokenService;

/**
 * Phase 6 — turns an Authorization header into a resolved auth context
 * (the array PolicyEngine consumes). Pure: TokenService + CapabilityResolver
 * injected; assignments supplied via a loader callable (repo in production).
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §2,§5
 */
final class RequestAuthenticatorTest extends TestCase
{
    private string $secret = 's3cret';
    private int $now = 1_700_000_000;

    private function make(): RequestAuthenticator
    {
        return new RequestAuthenticator(new TokenService(), new CapabilityResolver());
    }

    private function loader(): callable
    {
        // user_id -> role assignments (as a repository would return)
        return fn (int $uid): array => [
            ['permissions' => ['order.view.own', 'pos.sell'], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => ['discount_cap' => 10]],
        ];
    }

    public function testValidBearerProducesContext(): void
    {
        $ts    = new TokenService();
        $token = $ts->issue(['sub' => 3, 'typ' => 'vendor'], 900, $this->secret, $this->now);

        $ctx = $this->make()->authenticate('Bearer ' . $token, $this->secret, $this->now + 10, $this->loader());

        $this->assertSame(3, $ctx['actor_id']);
        $this->assertSame('vendor', $ctx['principal_type']);
        $this->assertContains('order.view.own', $ctx['permissions']);
        $this->assertEqualsCanonicalizing([['type' => 'vendor', 'id' => 1]], $ctx['scopes']);
        $this->assertSame(10, $ctx['attributes']['discount_cap']);
    }

    public function testMissingHeaderThrows(): void
    {
        $this->expectException(AuthException::class);
        $this->make()->authenticate(null, $this->secret, $this->now, $this->loader());
    }

    public function testNonBearerHeaderThrows(): void
    {
        $this->expectException(AuthException::class);
        $this->make()->authenticate('Token abc', $this->secret, $this->now, $this->loader());
    }

    public function testExpiredTokenThrows(): void
    {
        $ts    = new TokenService();
        $token = $ts->issue(['sub' => 1, 'typ' => 'customer'], 60, $this->secret, $this->now);

        $this->expectException(AuthException::class);
        $this->make()->authenticate('Bearer ' . $token, $this->secret, $this->now + 61, $this->loader());
    }
}
