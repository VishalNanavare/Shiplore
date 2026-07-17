<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/AuthException.php';
require_once __DIR__ . '/../../../app/Libraries/WebAuthenticator.php';

use App\Libraries\AuthException;
use App\Libraries\WebAuthenticator;

/**
 * Phase 5 (web) — session-login credential check. Pure: user lookup injected
 * via callable. Uniform error message (no user enumeration).
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §2,§7
 */
final class WebAuthenticatorTest extends TestCase
{
    // bcrypt hash of "password"
    private string $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    private function lookup(?array $user): callable
    {
        return static fn (string $login): ?array => $user;
    }

    public function testValidCredentialsReturnUser(): void
    {
        $user = ['id' => 1, 'name' => 'Admin', 'status' => 'active', 'password_hash' => $this->hash, 'principal_type' => 'platform'];
        $result = (new WebAuthenticator())->attempt('admin@platform.local', 'password', $this->lookup($user));
        $this->assertSame(1, $result['id']);
    }

    public function testUnknownUserThrows(): void
    {
        $this->expectException(AuthException::class);
        (new WebAuthenticator())->attempt('nobody@x.com', 'password', $this->lookup(null));
    }

    public function testWrongPasswordThrows(): void
    {
        $user = ['id' => 1, 'status' => 'active', 'password_hash' => $this->hash];
        $this->expectException(AuthException::class);
        (new WebAuthenticator())->attempt('admin@platform.local', 'WRONG', $this->lookup($user));
    }

    public function testInactiveAccountThrows(): void
    {
        $user = ['id' => 1, 'status' => 'suspended', 'password_hash' => $this->hash];
        $this->expectException(AuthException::class);
        (new WebAuthenticator())->attempt('admin@platform.local', 'password', $this->lookup($user));
    }
}
