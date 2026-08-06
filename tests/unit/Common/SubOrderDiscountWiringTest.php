<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Wiring checks for the H11 safe-half discount tracking against the real
 * place() method — source-scan convention (same as CheckoutRaceHardeningTest),
 * needed because place() is DB-coupled and not independently callable here.
 */
final class SubOrderDiscountWiringTest extends CIUnitTestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

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

    public function testSubOrdersInsertRecordsTheAllocatedDiscount(): void
    {
        $body = $this->methodBody($this->read('Models/StoreOrderRepository.php'), 'place');

        $this->assertStringContainsString('allocateDiscount(', $body);
        $this->assertMatchesRegularExpression("/'discount_total'\\s*=>/", $body, 'the sub_orders insert must record the allocated discount');
    }

    /**
     * Safe-half invariant: GST must still be computed on the UNDISCOUNTED line
     * total. If this ever changes, it silently becomes the full (High-risk) fix
     * that was explicitly deferred pending a separate accounting decision.
     */
    public function testGstIsStillComputedOnTheUndiscountedLineTotal(): void
    {
        $body = $this->methodBody($this->read('Models/StoreOrderRepository.php'), 'place');

        $this->assertMatchesRegularExpression(
            "/\\\$g\\s*=\\s*\\\$gst->compute\\(\\(string\\)\\s*\\\$lineTotal/",
            $body,
            'H11 safe half only: GST/commission must stay on the undiscounted line total — moving this basis is the deferred High-risk half',
        );
    }
}
