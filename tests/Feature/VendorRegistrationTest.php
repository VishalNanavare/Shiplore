<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Hardening — vendor self-registration: uniqueness + mobile OTP + email code +
 * GSTIN verification (fail-on-mismatch) before the vendor/shop are created.
 * Services mocked; flow + guards exercised end-to-end.
 */
final class VendorRegistrationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $reg;
    private object $gst;

    protected function setUp(): void
    {
        parent::setUp();

        Services::injectMock('businessTypeRepository', new class {
            public function list(?string $s = null): array { return [['id' => 1, 'code' => 'footwear', 'name' => 'Footwear', 'default_commission_rate' => '12.00', 'status' => 'active', 'category_count' => 3]]; }
        });
        $this->reg = new class {
            public bool $created = false;
            public bool $mobile = false;
            public bool $email = false;
            public bool $gstin = false;
            public function mobileExists(string $p): bool { return $this->mobile; }
            public function emailExists(string $e): bool { return $this->email; }
            public function gstinExists(string $g): bool { return $this->gstin; }
            public function createVendorWithShop(array $d): ?int { $this->created = true; return 7; }
        };
        Services::injectMock('registrationRepository', $this->reg);
        Services::injectMock('otpService', new class {
            public function issue(string $id, string $p = 'verify'): string { return '111111'; }
            // check() validates WITHOUT consuming; complete() calls this before verify()
            // (the code is only spent once the vendor row actually exists) — both must
            // agree on what a correct code is, matching real OtpService::check()/verify().
            public function check(string $id, string $code, string $p = 'verify'): bool { return $code === '111111'; }
            public function verify(string $id, string $code, string $p = 'verify'): bool { return $code === '111111'; }
        });
        $this->gst = new class {
            public bool $ok = true;
            public function verify(string $g, string $n, string $st, string $sc = 'vendor', ?int $si = null, ?int $a = null): array
            {
                return $this->ok ? ['ok' => true, 'status' => 'verified', 'match' => 'match', 'reason' => 'Verified', 'provider' => 'local']
                                 : ['ok' => false, 'status' => 'failed', 'match' => 'mismatch', 'reason' => 'GSTIN state does not match the shop state', 'provider' => 'local'];
            }
        };
        Services::injectMock('gstVerificationService', $this->gst);
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    /** @return array<string,string> */
    private function fields(): array
    {
        return [
            'mobile' => '+919812345678', 'email' => 'new@vendor.in', 'password' => 'secret123',
            'gstin' => '27ABCDE1234F1Z5', 'business_type_id' => '1', 'legal_name' => 'New Traders Pvt Ltd',
            'display_name' => 'New Traders', 'shop_name' => 'New Traders — Main', 'address' => '12 MG Road',
            'area' => 'Central', 'city' => 'Mumbai', 'state_code' => '27', 'pincode' => '400001',
            'latitude' => '19.07', 'longitude' => '72.87', 'delivery_radius' => '5',
        ];
    }

    private function csrf(): array
    {
        return [csrf_token() => csrf_hash()];
    }

    public function testFormRenders(): void
    {
        $r = $this->get('register');
        $r->assertStatus(200);
        $this->assertStringContainsString('Become a Vendor', (string) $r->getBody());
        $this->assertStringContainsString('GSTIN', (string) $r->getBody());
    }

    public function testSendCodesProceeds(): void
    {
        $data = $this->csrf() + $this->fields();
        $r    = $this->withSession(service('session')->get())->post('register/send-codes', $data);
        $r->assertRedirect();
        $this->assertStringContainsString('register', $r->getRedirectUrl());
    }

    public function testDuplicateMobileRejected(): void
    {
        $this->reg->mobile = true;
        $data = $this->csrf() + $this->fields();
        $r    = $this->withSession(service('session')->get())->post('register/send-codes', $data);
        $r->assertRedirect();
        $this->assertFalse($this->reg->created);
    }

    public function testInvalidGstinFormatRejected(): void
    {
        $data = $this->csrf() + array_merge($this->fields(), ['gstin' => 'BADGST']);
        $r    = $this->withSession(service('session')->get())->post('register/send-codes', $data);
        $r->assertRedirect();
        $this->assertFalse($this->reg->created);
    }

    public function testCompleteCreatesVendor(): void
    {
        // mobile_verified is session state set by an earlier step (mobile OTP was
        // already confirmed there) — complete() gates on it directly rather than
        // re-checking a posted mobile_otp, which it does not read at all.
        $session = service('session')->get() + ['reg' => $this->fields() + ['mobile_verified' => true]];
        $data    = $this->csrf() + ['email_code' => '111111'];
        $r       = $this->withSession($session)->post('register/complete', $data);
        $r->assertRedirect();
        $this->assertTrue($this->reg->created);
    }

    /**
     * An unverified mobile must block completion on its own, before the email code is
     * even looked at. This used to post a wrong `mobile_otp`, a field complete() does
     * not read — every test in this file was missing `mobile_verified` in session, so
     * this assertion (assertFalse) was passing vacuously regardless of what it posted:
     * the mobile-verified gate blocked every request in the file, correct code or not.
     */
    public function testUnverifiedMobileStopsRegistration(): void
    {
        $session = service('session')->get() + ['reg' => $this->fields() + ['mobile_verified' => false]];
        $data    = $this->csrf() + ['email_code' => '111111'];
        $r       = $this->withSession($session)->post('register/complete', $data);
        $r->assertRedirect();
        $this->assertStringContainsString('mobile', strtolower((string) (session('error') ?? '')));
        $this->assertFalse($this->reg->created);
    }

    /** A correct-code guess must still be rejected once the mobile gate is properly open. */
    public function testWrongEmailCodeStopsRegistration(): void
    {
        $session = service('session')->get() + ['reg' => $this->fields() + ['mobile_verified' => true]];
        $data    = $this->csrf() + ['email_code' => '000000'];
        $this->withSession($session)->post('register/complete', $data)->assertRedirect();
        $this->assertFalse($this->reg->created);
    }

    public function testGstMismatchStopsRegistration(): void
    {
        $this->gst->ok = false;
        $session = service('session')->get() + ['reg' => $this->fields() + ['mobile_verified' => true]];
        $data    = $this->csrf() + ['email_code' => '111111'];
        $r       = $this->withSession($session)->post('register/complete', $data);
        $r->assertRedirect();
        $this->assertFalse($this->reg->created); // GST failure blocks creation
    }
}
