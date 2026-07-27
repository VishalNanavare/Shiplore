<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Manufacturer self-registration.
 *
 * Requirement 1: the registration flow offers no delivery-range option.
 * Requirement 2: a manufacturer cannot set one afterwards either.
 *
 * The second is guaranteed by schema (`mshops` has no delivery columns — asserted in
 * ManufacturerPanelIsolationTest). This file covers the flow itself: that the controller
 * never collects the field, never writes it, and that the vendor flow is left intact.
 */
final class ManufacturerRegistrationTest extends CIUnitTestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

    /** The collected-field list must not include a delivery radius. */
    public function testControllerCollectsNoDeliveryRange(): void
    {
        $src = $this->read('Controllers/Auth/ManufacturerRegisterController.php');

        preg_match('/private const FIELDS = \[(.*?)\];/s', $src, $m);
        $this->assertNotEmpty($m, 'FIELDS constant not found');

        $this->assertStringNotContainsString('delivery_radius', $m[1]);
        $this->assertStringNotContainsString('delivery_enabled', $m[1]);
        // It collects a unit, not a shop.
        $this->assertStringContainsString('unit_name', $m[1]);
        $this->assertStringNotContainsString('shop_name', $m[1]);
    }

    /** No radius is written, and the row goes to mshops rather than shops. */
    public function testRepositoryWritesNoDeliveryRadiusAndUsesMshops(): void
    {
        $src = $this->read('Models/ManufacturerRegistrationRepository.php');

        // Assert on the written COLUMN, not the bare word — the file's comment names it
        // while explaining the deliberate omission, and that note is worth keeping.
        $this->assertStringNotContainsString(
            "'delivery_radius_km' =>",
            $src,
            'manufacturer registration must not write a delivery radius',
        );
        $this->assertStringContainsString("table('mshops')", $src, 'the first location must be an mshops row');
        $this->assertStringNotContainsString("table('shops')->insert", $src, 'it must not create a vendor shop');
    }

    /** The account must be created as a manufacturer on both axes. */
    public function testAccountIsCreatedAsAManufacturer(): void
    {
        $src = $this->read('Models/ManufacturerRegistrationRepository.php');

        $this->assertStringContainsString("'principal_type'    => 'manufacturer'", $src);
        $this->assertStringContainsString("'party_type'       => 'manufacturer'", $src);
        $this->assertStringContainsString("'scope_type' => 'manufacturer'", $src);
        $this->assertStringContainsString('MANUFACTURER_OWNER_ROLE = 22', $src);
    }

    /**
     * Identifiers are unique across BOTH party types. A manufacturer must not be able to
     * claim a mobile, email or GSTIN already used by a vendor — the DB unique keys make
     * no distinction, so a pre-check that missed one would fail at insert instead.
     */
    public function testUniquenessChecksSpanBothPartyTypes(): void
    {
        $src = $this->read('Models/ManufacturerRegistrationRepository.php');

        // users is shared, so these are inherently cross-type.
        $this->assertMatchesRegularExpression("/function mobileExists.*?table\('users'\)/s", $src);
        $this->assertMatchesRegularExpression("/function emailExists.*?table\('users'\)/s", $src);

        // GSTIN lives in three places and all three must be checked.
        $gstin = $this->methodBody($src, 'gstinExists');
        foreach (["table('vendors')", "table('shops')", "table('mshops')"] as $t) {
            $this->assertStringContainsString($t, $gstin, "gstinExists() must also check {$t}");
        }
    }

    /**
     * The in-progress registration must NOT share the vendor flow's session key.
     *
     * The session cookie is domain-wide (.shiplore.in), so a half-finished vendor
     * registration and a half-finished manufacturer registration live in the same
     * session — reusing 'reg' would let them silently overwrite each other.
     */
    public function testRegistrationUsesItsOwnSessionKey(): void
    {
        $src = $this->read('Controllers/Auth/ManufacturerRegisterController.php');

        $this->assertMatchesRegularExpression(
            "/private const SESSION_KEY = '(?!reg')[a-z_]+';/",
            $src,
            "the manufacturer flow must not reuse the vendor flow's 'reg' session key",
        );
        $this->assertStringNotContainsString("session()->get('reg')", $src);
        $this->assertStringNotContainsString("session()->set('reg'", $src);
    }

    /** Nothing is consumed until every fallible step passes — same ordering as the vendor flow. */
    public function testEmailCodeIsOnlySpentAfterTheAccountExists(): void
    {
        $body = $this->methodBody($this->read('Controllers/Auth/ManufacturerRegisterController.php'), 'complete');

        $check  = strpos($body, '->check(');
        $create = strpos($body, 'createManufacturerWithUnit');
        $verify = strpos($body, '->verify($reg[');

        $this->assertNotFalse($check);
        $this->assertNotFalse($create);
        $this->assertNotFalse($verify);
        $this->assertLessThan($create, $check, 'the code must be validated (not consumed) before the write');
        $this->assertGreaterThan($create, $verify, 'the code must only be SPENT after the account exists');
    }

    /** The Firebase token must be bound to the number in session. */
    public function testMobileVerificationBindsTheTokenToTheSessionNumber(): void
    {
        $body = $this->methodBody($this->read('Controllers/Auth/ManufacturerRegisterController.php'), 'verifyMobile');

        $this->assertStringContainsString('normalizePhone', $body);
        $this->assertStringContainsString("!== \$reg['mobile']", $body, 'any valid Firebase token would otherwise pass');
    }

    /** Every sending endpoint is throttled — these cost real money per request. */
    public function testSendingRoutesAreThrottled(): void
    {
        $routes = $this->read('Config/Routes.php');

        foreach ([
            'manufacturer-register/send-codes',
            'manufacturer-register/otp-ticket',
            'manufacturer-register/verify-mobile',
            'manufacturer-register/resend/email',
        ] as $route) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($route, '/') . "'.*?throttle:/",
                $routes,
                "{$route} must be throttled — it triggers an SMS or email",
            );
        }
    }

    /** The vendor registration flow must be completely untouched. */
    public function testVendorRegistrationIsUnchanged(): void
    {
        $vendor = $this->read('Controllers/Auth/RegisterController.php');

        $this->assertStringContainsString('delivery_radius', $vendor, 'the vendor flow still collects a delivery radius');
        $this->assertStringContainsString("'shop_name'", $vendor);
        $this->assertStringNotContainsString('manufacturer', strtolower($vendor));
    }

    /** Crude brace-matching body extractor — enough to scope an assertion to one method. */
    private function methodBody(string $src, string $method): string
    {
        if (! preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $brace = strpos($src, '{', (int) $m[0][1]);
        if ($brace === false) {
            return '';
        }

        $depth = 0;
        for ($i = $brace, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }

        return '';
    }
}
