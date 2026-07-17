<?php

declare(strict_types=1);

namespace App\Libraries\Tax;

/**
 * GstCalculator — Indian GST engine. Splits a line value into taxable + tax,
 * supporting GST-inclusive and GST-exclusive pricing, and intra-state
 * (CGST+SGST) vs inter-state (IGST). CGST/SGST are split so they always sum
 * back to the total tax (no rounding leak). All amounts are returned as
 * 2-decimal strings.
 *
 * @see docs/architecture/10-GST-ARCHITECTURE.md
 */
final class GstCalculator
{
    /**
     * @return array{taxable:string,tax:string,cgst:string,sgst:string,igst:string,total:string}
     */
    public function compute(string $amount, float $ratePct, bool $inclusive, bool $interState): array
    {
        $amt = (float) $amount;

        if ($inclusive) {
            $taxable = $ratePct > 0 ? $amt / (1 + $ratePct / 100) : $amt;
            $tax     = $amt - $taxable;
            $total   = $amt;
        } else {
            $taxable = $amt;
            $tax     = $amt * $ratePct / 100;
            $total   = $amt + $tax;
        }

        $taxable = round($taxable, 2);
        $tax     = round($tax, 2);
        $total   = round($total, 2);

        if ($interState) {
            $cgst = 0.0;
            $sgst = 0.0;
            $igst = $tax;
        } else {
            $cgst = round($tax / 2, 2);
            $sgst = round($tax - $cgst, 2); // remainder -> no leak
            $igst = 0.0;
        }

        return [
            'taxable' => $this->fmt($taxable),
            'tax'     => $this->fmt($tax),
            'cgst'    => $this->fmt($cgst),
            'sgst'    => $this->fmt($sgst),
            'igst'    => $this->fmt($igst),
            'total'   => $this->fmt($total),
        ];
    }

    /** Inter-state when the place of supply differs from the seller's state. */
    public function isInterState(string $sellerStateCode, string $placeOfSupply): bool
    {
        return $sellerStateCode !== $placeOfSupply;
    }

    private function fmt(float $v): string
    {
        return number_format($v, 2, '.', '');
    }
}
