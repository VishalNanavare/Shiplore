<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Audit H10 — RefundService::complete() must resolve the GST/commission basis
 * from the sub-order the RETURN actually names, not the first sub-order of the
 * whole order. One order carries one sub-order per product; picking the wrong
 * one unbalances the double-entry ledger and stamps a filed GST credit note
 * with the wrong basis.
 *
 * Behaviour-preserving for the common cases: single-sub-order orders and any
 * refund with no return_id (POS/legacy/whole-order refunds) still resolve via
 * the `orderBy('so.id', 'ASC')` fallback — this only changes the multi-product,
 * item-level-return case, which was wrong before.
 */
final class RefundSubOrderResolutionTest extends CIUnitTestCase
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

    public function testCompleteResolvesTheReturnsActualSubOrder(): void
    {
        $body = $this->methodBody($this->read('Libraries/Payment/RefundService.php'), 'complete');

        $this->assertStringContainsString(
            "table('returns')",
            $body,
            'must look up the return to find the sub-order it actually names, or a multi-product refund keeps using the first sub-order of the order',
        );
        // Not just present in the method — genuinely gated on $subOrderId !== null,
        // so a mutation like `if (false)` (which still contains the where() text)
        // is caught rather than passing vacuously.
        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*\\\$subOrderId\s*!==\s*null\s*\)\s*\{\s*\\\$builder->where\('so\.id',\s*\\\$subOrderId\)/",
            $body,
            'the payment/sub-order query must be constrained to the returned sub-order once one is known, and that constraint must actually run',
        );

        // $subOrderId must be resolved BEFORE the payment/sub-order row is queried,
        // or the constraint can never be applied to that query.
        $returnLookup = strpos($body, "table('returns')");
        $paymentQuery = strpos($body, "table('payments p')");
        $this->assertNotFalse($returnLookup);
        $this->assertNotFalse($paymentQuery);
        $this->assertLessThan($paymentQuery, $returnLookup, 'the return must be resolved before the payment/sub-order row is queried');
    }

    /** POS/legacy/whole-order refunds (no return_id) must keep resolving via the existing fallback. */
    public function testFallsBackToFirstSubOrderWhenThereIsNoReturn(): void
    {
        $body = $this->methodBody($this->read('Libraries/Payment/RefundService.php'), 'complete');

        $this->assertStringContainsString("orderBy('so.id', 'ASC')", $body);
    }
}
