<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Audit B, P1d — two money races, both reproduced against a real MySQL 9.6 at the
 * server's actual REPEATABLE-READ isolation before being fixed.
 *
 * 1. CreditRepository::repay() read the udhaar balance OUTSIDE the transaction with
 *    no lock, computed the new balance in PHP, and wrote an ABSOLUTE value back.
 *    Two cashiers each taking 400 against the same 1000 balance both read 1000,
 *    both wrote 600. Measured: balance 600 with 800 collected — the vendor loses
 *    400 with two valid receipts in hand. With the read moved inside the
 *    transaction under FOR UPDATE the same interleaving yields 200.
 *
 * 2. StoreOrderRepository coupon `per_user_limit` counted prior redemptions with a
 *    plain SELECT. The UPDATE on `coupons` above it does X-lock the coupon row, so
 *    two checkouts serialize — but under REPEATABLE READ a consistent read is
 *    served from the snapshot taken at the transaction's FIRST read, which happens
 *    before that UPDATE blocks. So the second checkout counted redemptions as of
 *    before the first committed. Measured with per_user_limit=1: 2 redemptions.
 *    With FOR UPDATE (a locking read sees the latest committed row): 1.
 *
 * Source assertions — the test environment has no migrated database — but the
 * behaviour they stand in for was demonstrated, not assumed. Comments are stripped
 * so the explanatory prose these fixes carry cannot satisfy an assertion.
 */
final class MoneyRaceHardeningTest extends CIUnitTestCase
{
    private const CREDIT = 'Models/CreditRepository.php';
    private const ORDER  = 'Models/StoreOrderRepository.php';

    /** Source with comments removed, so assertions measure code only. */
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

    /** Crude brace-matching body extractor — same convention as the other suites here. */
    private function methodBody(string $src, string $method): string
    {
        if (! preg_match('/(private|public|protected)\s+function\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
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

    /** The balance that authorises the write must be read under a row lock. */
    public function testRepayLocksTheCreditRowItDecrements(): void
    {
        $body = $this->methodBody($this->read(self::CREDIT), 'repay');
        $this->assertNotSame('', $body, 'CreditRepository::repay() not found');

        $this->assertMatchesRegularExpression(
            '/SELECT balance FROM customer_credits WHERE id = \? AND vendor_id = \? FOR UPDATE/',
            $body,
            'repay() must read the balance under FOR UPDATE, or two concurrent repayments both write the same absolute value',
        );
    }

    /** A lock taken after the transaction opens is the whole point — order matters. */
    public function testRepayTakesTheLockInsideTheTransaction(): void
    {
        $body = $this->methodBody($this->read(self::CREDIT), 'repay');

        $begin  = strpos($body, 'transBegin()');
        $lock   = strpos($body, 'FOR UPDATE');
        $update = strpos($body, "table('customer_credits')->where('id', \$creditId)->update(");

        $this->assertNotFalse($begin, 'repay() must still open a transaction');
        $this->assertNotFalse($lock, 'repay() must take a row lock');
        $this->assertNotFalse($update, 'repay() must still write the new balance');

        $this->assertGreaterThan($begin, $lock, 'a FOR UPDATE outside the transaction releases immediately and locks nothing useful');
        $this->assertLessThan($update, $lock, 'the lock must be held before the balance is written, not after');
    }

    /**
     * Every early return between the lock and the commit must release it.
     *
     * The rollback has to be matched inside the guard's OWN block. Searching
     * backwards from the message for the nearest transRollback() is not enough:
     * it finds the preceding guard's rollback and passes even when this guard has
     * none — which is exactly what the mutation run caught.
     */
    public function testRepayRollsBackOnEveryGuardAfterTheLock(): void
    {
        $body = $this->methodBody($this->read(self::CREDIT), 'repay');

        $guards = [
            'Credit not found.'               => '/if\s*\(\$credit === null\)\s*\{\s*\$db->transRollback\(\);\s*return \[[^\]]*\'Credit not found\.\'\];\s*\}/',
            'This credit is already cleared.' => '/if\s*\(\$balance <= 0\)\s*\{\s*\$db->transRollback\(\);\s*return \[[^\]]*\'This credit is already cleared\.\'\];\s*\}/',
        ];

        foreach ($guards as $msg => $pattern) {
            $this->assertStringContainsString($msg, $body, "guard \"{$msg}\" disappeared from repay()");
            $this->assertMatchesRegularExpression(
                $pattern,
                $body,
                "the \"{$msg}\" guard must roll back in its own block — returning after FOR UPDATE without one leaks an open transaction still holding the row lock",
            );
        }
    }

    /** The per-user redemption count must be a locking read to escape the snapshot. */
    public function testCouponPerUserLimitCountIsALockingRead(): void
    {
        $src = $this->read(self::ORDER);

        $this->assertMatchesRegularExpression(
            '/SELECT COUNT\(\*\) AS c FROM coupon_redemptions WHERE coupon_id = \? AND customer_id = \? FOR UPDATE/',
            $src,
            'the per_user_limit count must be a locking read; a consistent read uses a snapshot older than the coupon UPDATE that serializes checkouts',
        );

        // The old non-locking builder count must be gone, not merely supplemented.
        $this->assertDoesNotMatchRegularExpression(
            '/table\(\'coupon_redemptions\'\)\s*->where\(\'coupon_id\'.*?countAllResults\(\)/s',
            $src,
            'the non-locking countAllResults() on coupon_redemptions is still present',
        );
    }

    /** The count only sees committed data because the coupon UPDATE blocks first. */
    public function testPerUserCountRunsAfterTheCouponRowIsLocked(): void
    {
        $src = $this->read(self::ORDER);

        $update = strpos($src, "set('used_count', 'used_count + 1', false)");
        $count  = strpos($src, 'SELECT COUNT(*) AS c FROM coupon_redemptions');
        $insert = strpos($src, "table('coupon_redemptions')->insert(");

        $this->assertNotFalse($update, 'the coupon used_count UPDATE is missing');
        $this->assertNotFalse($count, 'the per-user count is missing');
        $this->assertNotFalse($insert, 'the redemption insert is missing');

        $this->assertGreaterThan($update, $count, 'the count must follow the UPDATE that X-locks the coupon row, which is what serializes concurrent checkouts');
        $this->assertLessThan($insert, $count, 'the count must precede the redemption insert');
    }
}
