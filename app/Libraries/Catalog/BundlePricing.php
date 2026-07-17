<?php

declare(strict_types=1);

namespace App\Libraries\Catalog;

/**
 * BundlePricing — pure pricing + validation for the specialized product types.
 * Bundles price as the sum of their components (each component can override its
 * contribution); combos use a fixed price set on the bundle product itself.
 * Gift-card amounts are validated against denominations / min / max. No DB.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md (product module, sections 27-29)
 */
final class BundlePricing
{
    /**
     * Dynamic bundle total = Σ qty × (price_contribution ?? unit_price).
     * @param list<array{qty:float,unit_price:float,price_contribution:float|null}> $components
     */
    public static function dynamicTotal(array $components): float
    {
        $sum = 0.0;
        foreach ($components as $c) {
            $unit = ($c['price_contribution'] ?? null) !== null ? (float) $c['price_contribution'] : (float) $c['unit_price'];
            $sum += (float) $c['qty'] * $unit;
        }

        return round($sum, 4);
    }

    /**
     * Effective bundle price: 'combo' uses the fixed price, 'bundle' sums
     * components. Combo savings = dynamic − fixed (>= 0 means a discount).
     * @param list<array{qty:float,unit_price:float,price_contribution:float|null}> $components
     * @return array{price:float, components_total:float, savings:float}
     */
    public static function effective(string $mode, float $fixedPrice, array $components): array
    {
        $dyn = self::dynamicTotal($components);
        if ($mode === 'combo') {
            return ['price' => round($fixedPrice, 4), 'components_total' => $dyn, 'savings' => round($dyn - $fixedPrice, 4)];
        }

        return ['price' => $dyn, 'components_total' => $dyn, 'savings' => 0.0];
    }

    /**
     * Is a chosen gift-card amount valid? If denominations are set, the amount
     * must be one of them; otherwise it must be within [min, max] (when given).
     * @param list<float> $denominations
     */
    public static function giftCardAmountValid(float $amount, array $denominations, ?float $min, ?float $max): bool
    {
        if ($amount <= 0) {
            return false;
        }
        if ($denominations !== []) {
            foreach ($denominations as $d) {
                if (abs((float) $d - $amount) < 1e-9) {
                    return true;
                }
            }

            return false;
        }
        if ($min !== null && $amount < $min) {
            return false;
        }
        if ($max !== null && $max > 0 && $amount > $max) {
            return false;
        }

        return true;
    }
}
