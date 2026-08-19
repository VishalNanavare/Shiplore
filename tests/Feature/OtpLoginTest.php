<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Phone-OTP staff sign-in (LoginController::otpLogin): a verified Firebase token
 * is matched to an active staff/vendor account and a session is started. Invalid
 * token, no account, inactive account and non-staff principals are all rejected.
 */
final class OtpLoginTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $verifier;
    private object $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = new class {
            public ?array $claims = ['phone_number' => '+919800000000', 'sub' => 'fbuid'];
            public function verify(string $t, int $n = 0): ?array { return $this->claims; }
        };
        $this->users = new class {
            public ?array $user = ['id' => 1, 'name' => 'Vishal', 'email' => 'v@x.com', 'phone' => '9800000000', 'status' => 'active', 'principal_type' => 'platform'];
            public function findByPhone(string $p): ?array { return $this->user; }
        };

        Services::injectMock('firebaseVerifier', $this->verifier);
        Services::injectMock('userRepository', $this->users);
        Services::injectMock('loginAttemptRepository', new class {
            public function record($l, $s, $r, $u, $ip, $ua): void {}
            public function recentFailureCount($l, $w): int { return 0; }
        });
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function otpPost(array $data = [])
    {
        $data[csrf_token()] = csrf_hash();
        $data['id_token']   = $data['id_token'] ?? 'tok';

        return $this->withSession(service('session')->get())->post('login/otp', $data);
    }

    private function body($r): array
    {
        return json_decode((string) $r->response()->getBody(), true) ?: [];
    }

    public function testValidOtpSignsInPlatformAdmin(): void
    {
        $r = $this->otpPost();
        $r->assertStatus(200);
        $json = $this->body($r);
        $this->assertTrue($json['ok']);
        $this->assertStringContainsString('admin/dashboard', $json['redirect']);
    }

    public function testVendorLandsInVendorPanel(): void
    {
        $this->users->user['principal_type'] = 'vendor';
        $this->assertStringContainsString('vendor/dashboard', $this->body($this->otpPost())['redirect']);
    }

    /**
     * The landing URL must name the panel's OWN host, not whichever host you signed in on.
     *
     * This is the reported bug at its source. landingFor() returned a relative path and
     * otpLogin() wrapped it in site_url(), which resolves against the CURRENT host —
     * and SiteURIFactory substitutes the real Host whenever it is in allowedHostnames.
     * So an admin signing in by OTP at manufacturer.<domain> was handed
     * manufacturer.<domain>/admin/dashboard: a path that host never registers, because
     * the admin group is subdomain-pinned. Our own login JSON emitted the 404.
     *
     * The 404 override added in 981eafb catches this, but catching a ball we throw is
     * not the fix — it is the safety net for stale links and typed URLs.
     */
    public function testTheLandingUrlNamesThePanelsOwnHostNotTheSigninHost(): void
    {
        service('superglobals')->setServer('HTTP_HOST', 'manufacturer.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('siteurifactory');
        Services::resetSingle('uri');

        try {
            $redirect = $this->body($this->otpPost())['redirect'] ?? '';

            // assertSame on the WHOLE url, not assertStringContainsString on a fragment.
            // A containment check passes on
            //   https://admin.<domain>/https:/admin.<domain>/admin/dashboard
            // because the doubled string still contains both fragments — which is
            // exactly how that malformed URL reached production. The only assertion
            // that catches a mangled URL is one that pins the entire thing.
            $this->assertSame('http://admin.shiplore.test/admin/dashboard', $redirect);
        } finally {
            service('superglobals')->unsetServer('HTTP_HOST');
        }
    }

    /** Same rule the other way: a vendor signing in on the admin host lands on vendor. */
    public function testAVendorSigningInOnTheAdminHostLandsOnTheVendorHost(): void
    {
        $this->users->user['principal_type'] = 'vendor';
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('siteurifactory');
        Services::resetSingle('uri');

        try {
            $this->assertSame('http://vendor.shiplore.test/vendor/dashboard', $this->body($this->otpPost())['redirect'] ?? '');
        } finally {
            service('superglobals')->unsetServer('HTTP_HOST');
        }
    }

    /** And a manufacturer lands on its own host — each principal needs its own case. */
    public function testAManufacturerLandsOnTheManufacturerHost(): void
    {
        $this->users->user['principal_type'] = 'manufacturer';
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('siteurifactory');
        Services::resetSingle('uri');

        try {
            $this->assertSame('http://manufacturer.shiplore.test/manufacturer/dashboard', $this->body($this->otpPost())['redirect'] ?? '');
        } finally {
            service('superglobals')->unsetServer('HTTP_HOST');
        }
    }

    public function testInvalidTokenRejected(): void
    {
        $this->verifier->claims = null;
        $this->otpPost()->assertStatus(401);
    }

    public function testNoAccountRejected(): void
    {
        $this->users->user = null;
        $this->otpPost()->assertStatus(404);
    }

    public function testInactiveAccountRejected(): void
    {
        $this->users->user['status'] = 'suspended';
        $this->otpPost()->assertStatus(403);
    }

    public function testCustomerRejectedFromStaffLogin(): void
    {
        $this->users->user['principal_type'] = 'customer';
        $this->otpPost()->assertStatus(403);
    }
}
