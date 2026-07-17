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
            public ?array $claims = ['phone_number' => '+919768181958', 'sub' => 'fbuid'];
            public function verify(string $t, int $n = 0): ?array { return $this->claims; }
        };
        $this->users = new class {
            public ?array $user = ['id' => 1, 'name' => 'Vishal', 'email' => 'v@x.com', 'phone' => '9768181958', 'status' => 'active', 'principal_type' => 'platform'];
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
