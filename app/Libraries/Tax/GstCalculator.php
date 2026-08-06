<?php

declare(strict_types=1);

namespace App\Libraries\Tax;

use App\Libraries\Money;

/**
 * GstCalculator — Indian GST engine. Splits a line value into taxable + tax,
 * supporting GST-inclusive and GST-exclusive pricing, and intra-state
 * (CGST+SGST) vs inter-state (IGST). CGST/SGST are split so they always sum
 * back to the total tax (no rounding leak). All amounts are returned as
 * 2-decimal strings.
 *
 * Arithmetic runs on Money (audit L10) rather than float: this computes on
 * every order line, POS sale, POS return and checkout, and is SUMmed across
 * thousands of sub-orders for the filed GST return — float division residue
 * would otherwise accumulate in whichever direction the rounding biases.
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
        $amt = Money::of($this->norm($amount));

        // Basis points (rate * 100) as an exact integer ratio denominator/numerator
        // pair — GST slabs (0/0.25/3/5/12/18/28) are exactly representable in a
        // float multiply by 100, so this one-time, bounded conversion of the RATE
        // does not reintroduce the drift this fix removes from the AMOUNT math.
        $rateBp = (int) round($ratePct * 100);

        if ($inclusive) {
            $taxableRaw = $amt->mulRatio(10000, 10000 + $rateBp);
            $taxRaw     = $amt->sub($taxableRaw);
            $totalRaw   = $amt;
        } else {
            $taxableRaw = $amt;
            $taxRaw     = $amt->mulRatio($rateBp, 10000);
            $totalRaw   = $amt->add($taxRaw);
        }

        $taxable = $taxableRaw->roundTo(2);
        $tax     = $taxRaw->roundTo(2);
        $total   = $totalRaw->roundTo(2);

        if ($interState) {
            $cgst = Money::of(0);
            $sgst = Money::of(0);
            $igst = $tax;
        } else {
            $cgst = $tax->mulRatio(1, 2)->roundTo(2);
            $sgst = $tax->sub($cgst); // remainder -> no leak; both sides already 2dp
            $igst = Money::of(0);
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

    private function fmt(Money $m): string
    {
        // Money::amount() is always 4dp ("100.0000"); an already-2dp-rounded value
        // always has "00" as its last two digits, so this is an exact truncation,
        // not a second rounding step.
        return substr($m->roundTo(2)->amount(), 0, -2);
    }

    /**
     * Callers pass (string) casts of float expressions, which can render as
     * "1.0E-5" — Money::of() throws on that, and an exception here inside
     * StoreOrderRepository::place() would roll back the whole order. Normalise
     * anything that isn't a plain decimal to '0' rather than propagate it.
     */
    private function norm(string $amount): string
    {
        $a = trim($amount);

        return preg_match('/^[+-]?\d+(\.\d+)?$/', $a) ? $a : '0';
    }
}
