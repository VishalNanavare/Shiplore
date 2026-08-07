<?php

declare(strict_types=1);

use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Phase 5 (web) — session login end-to-end: guard, CSRF (session token),
 * brute-force lockout, valid/invalid credentials. Repositories mocked so the
 * flow is verified deterministically without a DB.
 */
final class WebAuthTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // "password"

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.in');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');

        $hash = $this->hash;
        Services::injectMock('userRepository', new class ($hash) {
            public function __construct(private string $hash) {}
            public function findByLogin(string $login): ?array
            {
                return $login === 'admin@platform.local'
                    ? ['id' => 1, 'name' => 'Super Admin', 'email' => $login, 'phone' => null,
                       'password_hash' => $this->hash, 'status' => 'active', 'principal_type' => 'platform']
                    : null;
            }
        });
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => ['vendor.approve'], 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
        // default: no recent failures, record is a no-op
        $this->mockAttempts(0);
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function mockAttempts(int $recentFailures): void
    {
        Services::injectMock('loginAttemptRepository', new class ($recentFailures) {
            public function __construct(private int $recent) {}
            public function recentFailureCount(string $identifier, int $windowMinutes): int { return $this->recent; }
            public function record(string $i, bool $s, ?string $r, ?int $u, ?string $ip, ?string $ua): void {}
        });
    }

    /**
     * CSRF-valid login post: have the framework generate+store the hash (under
     * its own session key), then carry the whole session into the request and
     * post the matching token. With session CSRF this validates deterministically.
     */
    private function postLogin(array $data)
    {
        $data[csrf_token()] = csrf_hash();           // generates + stores in session
        $session            = service('session')->get(); // includes the CSRF key

        return $this->withSession($session)->post('login', $data);
    }

    public function testLoginPageRenders(): void
    {
        $this->get('login')->assertStatus(200); // staff login moved off root (root = storefront)
    }

    public function testDashboardRedirectsWhenNotLoggedIn(): void
    {
        $result = $this->get('admin/dashboard');
        $result->assertRedirect();
        $this->assertStringNotContainsString('Welcome back', (string) $result->getBody());
    }

    public function testValidLoginRedirectsToDashboard(): void
    {
        $result = $this->postLogin(['login' => 'admin@platform.local', 'password' => 'password']);
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }

    public function testVendorLoginLandsOnVendorPanel(): void
    {
        // a vendor-principal staff user must land in the vendor panel, not admin
        $hash = $this->hash;
        Services::injectMock('userRepository', new class ($hash) {
            public function __construct(private string $hash) {}
            public function findByLogin(string $login): ?array
            {
                return $login === 'owner@shop.in'
                    ? ['id' => 50, 'name' => 'Shop Owner', 'email' => $login, 'phone' => null, 'password_hash' => $this->hash, 'status' => 'active', 'principal_type' => 'vendor']
                    : null;
            }
        });
        $result = $this->postLogin(['login' => 'owner@shop.in', 'password' => 'password']);
        $result->assertRedirect();
        $this->assertStringContainsString('vendor/dashboard', $result->getRedirectUrl());
        $this->assertStringNotContainsString('admin/dashboard', $result->getRedirectUrl());
    }

    public function testInvalidPasswordDoesNotReachDashboard(): void
    {
        $result = $this->postLogin(['login' => 'admin@platform.local', 'password' => 'WRONG']);
        $result->assertRedirect();
        $this->assertStringNotContainsString('admin/dashboard', (string) $result->getRedirectUrl());
    }

    public function testUnknownUserDoesNotReachDashboard(): void
    {
        $result = $this->postLogin(['login' => 'ghost@nowhere.test', 'password' => 'password']);
        $result->assertRedirect();
        $this->assertStringNotContainsString('admin/dashboard', (string) $result->getRedirectUrl());
    }

    public function testMissingCsrfTokenIsRejected(): void
    {
        // No token in body → CSRF filter blocks before the controller runs.
        // In testing, CI4 throws (in production it 403-redirects); either way the
        // request never reaches the controller — that's the security guarantee.
        $this->expectException(SecurityException::class);
        $this->post('login', ['login' => 'admin@platform.local', 'password' => 'password']);
    }

    public function testBruteForceLockout(): void
    {
        $this->mockAttempts(5); // at/over the threshold
        $result = $this->postLogin(['login' => 'admin@platform.local', 'password' => 'password']);
        $result->assertRedirect();
        $this->assertStringNotContainsString('admin/dashboard', (string) $result->getRedirectUrl());
    }

    public function testDashboardRendersWithSession(): void
    {
        $result = $this->withSession([
            'isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Super Admin', 'principal_type' => 'platform',
        ])->get('admin/dashboard');

        $result->assertStatus(200);
        $this->assertStringContainsString('Welcome back', (string) $result->getBody());
    }
}
