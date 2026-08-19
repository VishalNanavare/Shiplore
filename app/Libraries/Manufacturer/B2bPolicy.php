<?php

declare(strict_types=1);

namespace App\Libraries\Manufacturer;

/**
 * The commercial rules of B2B trade, in one place and configurable.
 *
 * Every number here is a BUSINESS decision the platform owner has to make, not something
 * engineering can derive. Hardcoding a commission rate or a return window would bake a
 * guess into the ledger and make it expensive to change; inventing one and showing it on
 * a settlement screen would be worse still, because a manufacturer would reconcile
 * against it.
 *
 * So the defaults are deliberately the ones that CANNOT mislead:
 *
 *   - commission 0%. "Nothing is configured" and "we take nothing" produce the same
 *     number, so a manufacturer reading an unconfigured settlement sees their gross and
 *     is not lied to. A made-up 5% would look authoritative and be wrong.
 *   - return window 0 days, meaning returns are CLOSED until someone opens them. An
 *     invented 30-day window would silently authorise refunds nobody agreed to.
 *   - deduction 0%. Same reasoning as commission.
 *
 * All four live in the `settings` table under the `b2b` namespace, so they are changed
 * from the admin panel without a deploy, and the screens that use them state the value in
 * force rather than assuming the reader knows it.
 */
final class B2bPolicy
{
    private const NS = 'b2b';

    /**
     * Platform commission on a manufacturer's B2B sale, as a percentage.
     *
     * Clamped to 0-100: a negative rate would pay the manufacturer more than the order
     * was worth, and above 100 would invert the settlement.
     */
    public function commissionPercent(): float
    {
        return $this->pct('commission_percent');
    }

    /** Commission in money on a given order value. */
    public function commissionOn(float $orderTotal): float
    {
        return round(max(0.0, $orderTotal) * $this->commissionPercent() / 100, 2);
    }

    /**
     * How many days after RECEIPT a buyer may return goods.
     *
     * Zero means returns are closed. Counted from receipt rather than dispatch, because
     * the buyer cannot inspect what has not arrived.
     */
    public function returnWindowDays(): int
    {
        return max(0, (int) $this->setting('return_window_days', 0));
    }

    /** Restocking deduction withheld from a B2B refund, as a percentage. */
    public function returnDeductionPercent(): float
    {
        return $this->pct('return_deduction_percent');
    }

    /**
     * Whether a purchase order received on $receivedAt is still returnable.
     *
     * Both arguments are dates rather than a duration so the caller cannot accidentally
     * compare against "now" in a different timezone than the row was written in.
     */
    public function isWithinReturnWindow(?string $receivedAt, ?string $now = null): bool
    {
        $days = $this->returnWindowDays();
        if ($days <= 0 || $receivedAt === null || trim($receivedAt) === '') {
            return false;
        }

        $from = strtotime($receivedAt);
        $to   = strtotime($now ?? date('Y-m-d H:i:s'));
        if ($from === false || $to === false || $to < $from) {
            return false;
        }

        return ($to - $from) <= $days * 86400;
    }

    /**
     * What a buyer actually gets back on a refund of $lineValue.
     *
     * @return array{refund:float,deduction:float}
     */
    public function refundBreakdown(float $lineValue): array
    {
        $value     = max(0.0, $lineValue);
        $deduction = round($value * $this->returnDeductionPercent() / 100, 2);

        return ['refund' => round($value - $deduction, 2), 'deduction' => $deduction];
    }

    /**
     * Days between payout runs. Zero means payouts are not scheduled — earnings are still
     * visible, they are simply not being batched into settlements yet.
     */
    public function payoutCycleDays(): int
    {
        return max(0, (int) $this->setting('payout_cycle_days', 0));
    }

    /** True when a payout cycle has been configured at all. */
    public function payoutsConfigured(): bool
    {
        return $this->payoutCycleDays() > 0;
    }

    /**
     * True when NOTHING has been configured, so a screen can say so plainly instead of
     * presenting zeros as though they were negotiated.
     */
    public function isUnconfigured(): bool
    {
        return $this->commissionPercent() === 0.0
            && $this->returnWindowDays() === 0
            && $this->payoutCycleDays() === 0;
    }

    private function pct(string $key): float
    {
        return max(0.0, min(100.0, (float) $this->setting($key, 0)));
    }

    private function setting(string $key, mixed $default): mixed
    {
        return service('settingsRepository')->get(self::NS, $key, $default);
    }
}
