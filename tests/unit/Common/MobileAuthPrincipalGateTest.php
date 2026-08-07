<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Audit B, P1 — the four `api/v1` authorization gaps that let a caller act outside
 * its own principal or tenant.
 *
 * These are structural assertions rather than request tests because the mint path
 * needs a `jwt.secret` and the POS path needs a migrated `pos_terminals`, neither of
 * which exists in the test environment. Each assertion below was mutation-checked:
 * the fix was reverted, the test observed to fail, and the fix restored.
 */
final class MobileAuthPrincipalGateTest extends CIUnitTestCase
{
    private const AUTH   = 'Controllers/Api/V1/AuthApiController.php';
    private const VENDOR = 'Controllers/Api/V1/VendorApiController.php';
    private const POS    = 'Controllers/Api/V1/PosController.php';
    private const REPO   = 'Models/PosSyncRepository.php';

    /**
     * Source with comments stripped.
     *
     * Every assertion here must measure CODE. These fixes carry comments naming the
     * vulnerable construct they replaced (`env('JWT_SECRET', '')`, and so on), so a
     * plain file_get_contents() would let prose satisfy — or violate — the assertion
     * while the real statement was free to change. Stripping via the tokenizer also
     * removes braces inside comments, which makes the body extractor below exact.
     */
    private function read(string $rel): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents(APPPATH . $rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }

        return $out;
    }

    /** Crude brace-matching body extractor — same convention as VendorApiOwnerGateTest. */
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

    /**
     * P1a — password login had no principal gate, so an admin's email+password minted
     * a 30-day JWT carrying `typ = platform`. The OTP and Firebase siblings both pin
     * 'customer'; only this path pinned nothing.
     */
    public function testPasswordLoginRefusesNonMobilePrincipals(): void
    {
        $src  = $this->read(self::AUTH);
        $body = $this->methodBody($src, 'login');
        $this->assertNotSame('', $body, 'AuthApiController::login() not found');

        $this->assertMatchesRegularExpression(
            '/in_array\(\s*\(string\)\s*\(\$user\[\'principal_type\'\]\s*\?\?\s*\'\'\),\s*self::MOBILE_PRINCIPALS,\s*true\s*\)/',
            $body,
            'login() must allow-list principal_type against MOBILE_PRINCIPALS — otherwise a staff credential mints a mobile JWT',
        );

        // The regex above pins `true` as the third in_array() argument: loose comparison
        // would let a principal_type of 0 match any string in the list.

        // The list itself must stay an allow-list of exactly the three app principals.
        $this->assertMatchesRegularExpression(
            '/const\s+MOBILE_PRINCIPALS\s*=\s*\[\'customer\',\s*\'vendor\',\s*\'rider\'\];/',
            $src,
            'MOBILE_PRINCIPALS must remain the three mobile apps; adding platform/admin re-opens the escalation',
        );
    }

    /**
     * The gate must sit AFTER password_verify() and BEFORE session(), so it neither
     * leaks which identifiers are staff accounts nor mints before refusing.
     */
    public function testPrincipalGateSitsBetweenCredentialCheckAndMint(): void
    {
        $body = $this->methodBody($this->read(self::AUTH), 'login');

        $verify = strpos($body, 'password_verify(');
        $gate   = strpos($body, 'self::MOBILE_PRINCIPALS');
        $mint   = strpos($body, '$this->session($user)');

        $this->assertNotFalse($verify, 'login() must still verify the password');
        $this->assertNotFalse($gate, 'login() must gate the principal type');
        $this->assertNotFalse($mint, 'login() must still mint a session on success');

        $this->assertGreaterThan($verify, $gate, 'gating before password_verify() turns login into a staff-account oracle');
        $this->assertLessThan($mint, $gate, 'gating after session() means the token was already issued');
    }

    /**
     * P1a — VendorApiController::refresh() was the only mint site reading the secret
     * with env('JWT_SECRET', ''), which yields '' when unset instead of failing.
     * An empty HMAC key lets anyone forge a token for any user.
     */
    public function testVendorRefreshMintsWithFailClosedSecret(): void
    {
        $src  = $this->read(self::VENDOR);
        $body = $this->methodBody($src, 'refresh');
        $this->assertNotSame('', $body, 'VendorApiController::refresh() not found');

        $this->assertStringContainsString(
            'TokenService::secret()',
            $body,
            'refresh() must resolve the signing key through TokenService::secret(), which throws on an unset secret',
        );
        $this->assertStringNotContainsString(
            "env('JWT_SECRET'",
            $body,
            "refresh() must not fall back to env('JWT_SECRET', '') — an empty key signs forgeable tokens",
        );

        // No mint site anywhere in the class may reach for the raw env value.
        $this->assertStringNotContainsString(
            "env('JWT_SECRET', '')",
            $src,
            'no mint site in VendorApiController may default the JWT secret to an empty string',
        );
    }

    /**
     * P1b — inventoryLedger() takes shop_id and variant_id from the request body.
     * inShopScope() returns true unconditionally for an owner (shopScope() is null),
     * and InventoryService::ensureRow() INSERTS, so an owner could write stock rows
     * against another tenant's shop/variant.
     */
    public function testInventoryLedgerVerifiesVariantAndShopOwnership(): void
    {
        $src  = $this->read(self::VENDOR);
        $body = $this->methodBody($src, 'inventoryLedger');
        $this->assertNotSame('', $body, 'VendorApiController::inventoryLedger() not found');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$this->ownsVariantAndShop\(\$variantId,\s*\$shopId,\s*\$vid\)\s*\)\s*\{/',
            $body,
            'inventoryLedger() must reject variant/shop pairs the caller does not own — inShopScope() alone is not an ownership check',
        );

        // The predicate must actually consult both tables; an ownership check that
        // only looks at shops still lets a foreign variant through.
        $gate = $this->methodBody($src, 'ownsVariantAndShop');
        $this->assertNotSame('', $gate, 'ownsVariantAndShop() not found');
        $this->assertStringContainsString("table('shops')", $gate, 'ownership check must verify the shop belongs to the vendor');
        $this->assertStringContainsString("table('product_variants')", $gate, 'ownership check must verify the variant belongs to the vendor');
        $this->assertSame(2, substr_count($gate, "'vendor_id'"), 'both the shop and the variant must be matched against the caller vendor id');

        // Gate must precede the write, not follow it.
        $ownership = strpos($body, 'ownsVariantAndShop(');
        $write     = strpos($body, 'inventoryService');
        $this->assertNotFalse($ownership);
        if ($write !== false) {
            $this->assertLessThan($write, $ownership, 'the ownership check must run before the inventory write, not after');
        }
    }

    /**
     * P1b — PosController::activate() never resolved the caller's vendors, so any
     * authenticated principal holding an activation code could bind their device to
     * another tenant's terminal.
     */
    public function testPosActivateScopesToCallerVendors(): void
    {
        $body = $this->methodBody($this->read(self::POS), 'activate');
        $this->assertNotSame('', $body, 'PosController::activate() not found');

        $this->assertMatchesRegularExpression(
            '/->activate\(\s*[^;]*\$this->callerVendorIds\(\),?\s*\)/s',
            $body,
            'activate() must pass callerVendorIds() so the lookup is bound to the caller tenant',
        );
    }

    /**
     * The predicate has to be part of the lookup, not a check on the result:
     * PosSyncRepository::activate() writes the device fingerprint as soon as a row
     * matches, so a post-hoc rejection would return an error AFTER binding the device.
     */
    public function testPosRepositoryAppliesVendorPredicateBeforeTheWrite(): void
    {
        $body = $this->methodBody($this->read(self::REPO), 'activate');
        $this->assertNotSame('', $body, 'PosSyncRepository::activate() not found');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$allowedVendorIds\s*!==\s*null\s*\)\s*\{\s*\$qb->whereIn\(\'vendor_id\'/',
            $body,
            'the vendor predicate must be added to the query builder when an allow-list is supplied',
        );

        $predicate = strpos($body, "whereIn('vendor_id'");
        $select    = strpos($body, '$qb->get()');
        $update    = strpos($body, '->update([');

        $this->assertNotFalse($predicate, 'vendor predicate missing');
        $this->assertNotFalse($select, 'the terminal lookup must still execute');
        $this->assertNotFalse($update, 'activate() must still bind the fingerprint');

        $this->assertLessThan($select, $predicate, 'the predicate must be applied before the query runs');
        $this->assertLessThan($update, $select, 'the lookup must complete before the fingerprint is written');
    }

    /** An empty allow-list means "caller owns no vendors" and must match nothing, not everything. */
    public function testEmptyAllowListMatchesNothing(): void
    {
        $body = $this->methodBody($this->read(self::REPO), 'activate');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$allowedVendorIds\s*!==\s*null\s*&&\s*\$allowedVendorIds\s*===\s*\[\]\s*\)\s*\{\s*return\s+null;/',
            $body,
            'an empty allow-list must return null — whereIn() with an empty array is a no-op in some drivers and would match every terminal',
        );
    }
}
