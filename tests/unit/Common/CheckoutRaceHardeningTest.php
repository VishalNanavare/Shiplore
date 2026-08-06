<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Checkout race-condition guards on `StoreOrderRepository::place()` (audit H7, H8,
 * M19) and the vendor/manufacturer tenant-isolation guard on
 * `VendorAccountRepository` (audit M11).
 *
 * The common shape of H7/H8: a check whose outcome authorises a write ran outside
 * the lock that write takes, so concurrent checkouts could all pass the same
 * check and all place. M19: the web checkout was the one sales channel that never
 * wrote an inventory_ledger row, so ledger balance_after diverged from
 * inventory.on_hand the first time anything sold online.
 */
final class CheckoutRaceHardeningTest extends CIUnitTestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

    /** Crude brace-matching body extractor — same convention as the other suites here. */
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

    // ------------------------------------------------------------------ H7

    /** The stock-availability check must run under FOR UPDATE inside the transaction, not before it. */
    public function testStockCheckIsLockedInsideTheTransaction(): void
    {
        $body = $this->methodBody($this->read('Models/StoreOrderRepository.php'), 'place');

        $this->assertStringContainsString('FOR UPDATE', $body, 'the availability check must lock the rows it reads, or two concurrent checkouts can both pass it for the last unit');

        // The real SQL clause, not the explanatory comment above usort() that also
        // mentions "FOR UPDATE" in prose — search for the actual quoted clause.
        $begin = strpos($body, 'transBegin(');
        $lock  = strpos($body, "FOR UPDATE',");
        $this->assertNotFalse($begin);
        $this->assertNotFalse($lock, 'no literal FOR UPDATE SQL clause found (only the explanatory comment?)');
        $this->assertLessThan($lock, $begin, 'the lock must be taken AFTER transBegin(), or it is not held for the write that follows');
    }

    /** Cart lines must sort by variant_id before any lock is taken, so two concurrent multi-item checkouts can't deadlock on opposite lock orders. */
    public function testCartLinesAreSortedBeforeLocking(): void
    {
        $body = $this->methodBody($this->read('Models/StoreOrderRepository.php'), 'place');

        $this->assertStringContainsString('usort(', $body);
        $this->assertStringContainsString('variant_id', $body);

        $sort = strpos($body, 'usort(');
        $lock = strpos($body, "FOR UPDATE',");
        $this->assertNotFalse($sort);
        $this->assertLessThan($lock, $sort, 'lines must be sorted before the first lock is taken, or lock order is not deterministic');
    }

    // ------------------------------------------------------------------ H8

    /** The coupon usage-limit check must be a predicate on the UPDATE itself, not a separate unlocked read. */
    public function testCouponUsageLimitIsEnforcedByTheUpdatePredicate(): void
    {
        $body = $this->methodBody($this->read('Models/StoreOrderRepository.php'), 'place');

        $this->assertStringContainsString('used_count < usage_limit', $body, 'the usage_limit check must gate the UPDATE, or concurrent checkouts can all read the same used_count and all place');
        $this->assertStringContainsString('affectedRows()', $body, 'the caller must confirm the conditional UPDATE actually matched a row before treating the coupon as applied');
    }

    /** A per-user redemption limit must also be enforced, not just the global usage_limit. */
    public function testPerUserCouponLimitIsChecked(): void
    {
        $body = $this->methodBody($this->read('Models/StoreOrderRepository.php'), 'place');

        $this->assertStringContainsString('per_user_limit', $body);
    }

    /** Either coupon race must abort the order, not silently drop the coupon and place at a discount the caller never priced. */
    public function testCouponRaceLossAbortsTheOrder(): void
    {
        $body = $this->methodBody($this->read('Models/StoreOrderRepository.php'), 'place');
        preg_match_all('/transRollback\(\);\s*return null;/', $body, $m);
        $this->assertGreaterThanOrEqual(
            2,
            count($m[0]),
            'both the usage_limit race and the per_user_limit race must roll back and abort, matching $totals which already has the discount baked in',
        );
    }

    // ------------------------------------------------------------------ M19

    /** A web checkout sale must post an inventory_ledger row, same as every other stock-moving channel. */
    public function testWebCheckoutPostsAnInventoryLedgerRow(): void
    {
        $body = $this->methodBody($this->read('Models/StoreOrderRepository.php'), 'place');

        $this->assertStringContainsString("table('inventory_ledger')", $body);
        $this->assertStringContainsString("'movement_type' => 'sale'", $body);
        $this->assertStringContainsString("'ref_type' => 'order'", $body);

        $decrement = strpos($body, "set('on_hand', 'GREATEST(on_hand");
        $ledger    = strpos($body, "table('inventory_ledger')");
        $this->assertNotFalse($decrement);
        $this->assertNotFalse($ledger);
        $this->assertLessThan($ledger, $decrement, 'balance_after must be read AFTER the decrement, or the ledger records the pre-sale balance');
    }

    // ------------------------------------------------------------------ M11

    /** Vendor and manufacturer tenants share `vendors`, discriminated by party_type — both lookups must filter on it, or a manufacturer owner resolves as a vendor. */
    public function testVendorLookupsAreScopedToVendorPartyType(): void
    {
        $src = $this->read('Models/VendorAccountRepository.php');

        $this->assertStringContainsString('PARTY_TYPE', $src);

        foreach (['findByOwnerUserId', 'findStaffVendor'] as $method) {
            $body = $this->methodBody($src, $method);
            $this->assertNotSame('', $body, "VendorAccountRepository::{$method}() not found");
            $this->assertMatchesRegularExpression(
                "/where\\(\\s*'v?\\.?party_type'\\s*,\\s*self::PARTY_TYPE\\s*\\)/",
                $body,
                "{$method}() must filter on party_type, or a manufacturer owner's session resolves to a vendors row and passes requireVendor()",
            );
        }
    }
}
