<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

use Throwable;

/**
 * RiderPayoutService — Phase X5 (GAP-033): rider compensation engine for
 * vendor self-delivery. Five models (doc 44 §7.2): fixed commission (% of
 * order), per-KM, per-order, salary (payroll-side, ₹0 per delivery), and
 * hybrid (per-order incentive above a monthly threshold on top of salary).
 *
 * compute() is pure and unit-tested; accrue() persists one entry per
 * delivered delivery (idempotent via the unique delivery_id key).
 */
final class RiderPayoutService
{
    /** @var callable|null fn(int $riderUserId): ?array plan row */
    private $planLoader;

    /** @var callable|null fn(array $entry): void */
    private $entryWriter;

    /** @var callable|null fn(int $riderUserId, string $monthSql): int delivered count this month */
    private $monthlyCounter;

    public function __construct(?callable $planLoader = null, ?callable $entryWriter = null, ?callable $monthlyCounter = null)
    {
        $this->planLoader     = $planLoader;
        $this->entryWriter    = $entryWriter;
        $this->monthlyCounter = $monthlyCounter;
    }

    /**
     * Pure: the payout amount for one delivered order under a plan.
     *
     * @param array<string,mixed> $plan  model, pct_of_order, per_km_rate,
     *                                   per_order_amount, incentive_threshold
     * @param int $deliveredThisMonth    used by hybrid's threshold
     */
    public static function compute(array $plan, float $orderValue, float $distanceKm, int $deliveredThisMonth = 0): float
    {
        return round(match ((string) ($plan['model'] ?? '')) {
            'fixed_commission' => $orderValue * ((float) ($plan['pct_of_order'] ?? 0)) / 100,
            'per_km'           => $distanceKm * (float) ($plan['per_km_rate'] ?? 0),
            'per_order'        => (float) ($plan['per_order_amount'] ?? 0),
            'salary'           => 0.0, // paid via payroll (staff_attendance), not per delivery
            'hybrid'           => $deliveredThisMonth >= (int) ($plan['incentive_threshold'] ?? 0)
                ? (float) ($plan['per_order_amount'] ?? 0)
                : 0.0,
            default => 0.0,
        }, 2);
    }

    /**
     * Accrue the payout entry for a delivered delivery. Best-effort by design:
     * a payout fault must never block the delivery flow.
     * @param array<string,mixed> $delivery id, rider_user_id, vendor_id, order_value, distance_km
     */
    public function accrueForDelivery(array $delivery): ?array
    {
        try {
            $riderUserId = (int) ($delivery['rider_user_id'] ?? 0);
            if ($riderUserId <= 0 || $this->planLoader === null || $this->entryWriter === null) {
                return null;
            }
            $plan = ($this->planLoader)($riderUserId);
            if ($plan === null) {
                return null; // no plan assigned → marketplace/payroll handled elsewhere
            }

            $monthly = 0;
            if (($plan['model'] ?? '') === 'hybrid' && $this->monthlyCounter !== null) {
                $monthly = (int) ($this->monthlyCounter)($riderUserId, date('Y-m-01 00:00:00'));
            }

            $amount = self::compute($plan, (float) ($delivery['order_value'] ?? 0), (float) ($delivery['distance_km'] ?? 0), $monthly);

            $entry = [
                'delivery_id'   => (int) ($delivery['id'] ?? 0) ?: null,
                'rider_user_id' => $riderUserId,
                'vendor_id'     => $delivery['vendor_id'] ?? null,
                'model'         => (string) $plan['model'],
                'order_value'   => number_format((float) ($delivery['order_value'] ?? 0), 2, '.', ''),
                'distance_km'   => number_format((float) ($delivery['distance_km'] ?? 0), 2, '.', ''),
                'amount'        => number_format($amount, 2, '.', ''),
                'status'        => 'accrued',
            ];
            ($this->entryWriter)($entry);

            return $entry;
        } catch (Throwable) {
            return null;
        }
    }
}
