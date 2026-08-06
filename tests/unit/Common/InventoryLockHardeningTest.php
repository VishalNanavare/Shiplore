<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Audit M18 — InventoryService::reserve() and consumeBatches() were
 * read-check-write with no row locks (the same shape H7 already fixed on the
 * checkout stock check, and M17 fixed on monline PO receive): a check whose
 * outcome authorises a write ran outside the lock that write takes, so two
 * concurrent reservations/sales could both pass the same availability check
 * and both proceed.
 */
final class InventoryLockHardeningTest extends CIUnitTestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
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

    public function testReserveLocksTheAvailabilityCheck(): void
    {
        $body = $this->methodBody($this->read('Libraries/Inventory/InventoryService.php'), 'reserve');

        // The literal end of the real SQL clause, not just the word appearing
        // anywhere (e.g. in an explanatory comment) — a naive "contains" check
        // would pass even if the actual FOR UPDATE were stripped from the query.
        $this->assertStringContainsString(
            "FOR UPDATE',",
            $body,
            'reserve() must lock the row its availability check authorises a write against',
        );

        $begin = strpos($body, 'transBegin(');
        $lock  = strpos($body, "FOR UPDATE',");
        $this->assertNotFalse($begin);
        $this->assertNotFalse($lock);
        $this->assertLessThan($lock, $begin, 'the lock must be taken AFTER transBegin(), or it is not held for the write that follows');

        // The locked read must run BEFORE the reserved+=qty write it authorises.
        $write = strpos($body, "set('reserved', 'reserved +");
        $this->assertNotFalse($write);
        $this->assertLessThan($write, $lock, 'the availability check must be locked before the reservation write, not after');
    }

    public function testConsumeBatchesLocksTheBatchList(): void
    {
        $body = $this->methodBody($this->read('Libraries/Inventory/InventoryService.php'), 'consumeBatches');

        // The literal end of the real SQL clause, not just the word appearing
        // anywhere (e.g. in an explanatory comment).
        $this->assertStringContainsString(
            'FOR UPDATE",',
            $body,
            'consumeBatches() must lock the batch rows it reads, or two concurrent consumers can both read the same qty and both decrement past zero',
        );

        // The ORDER BY (FIFO) must be preserved under the lock.
        $this->assertStringContainsString('COALESCE(mfg_date, created_at) ASC, id ASC', $body);
    }

    /** Floor the batch decrement to match bump()'s existing GREATEST floor — a race window closed by the lock above should not also be able to go negative through a stale $take. */
    public function testConsumeBatchesFloorsTheDecrementAtZero(): void
    {
        $body = $this->methodBody($this->read('Libraries/Inventory/InventoryService.php'), 'consumeBatches');

        $this->assertMatchesRegularExpression(
            "/set\\('qty',\\s*'GREATEST\\(qty - ' \\. \\\$take \\. ', 0\\)'/",
            $body,
            'the batch qty decrement must be floored at 0, matching bump()',
        );
    }
}
