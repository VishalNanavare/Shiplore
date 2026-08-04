<?php

declare(strict_types=1);

namespace App\Libraries\Catalog;

/**
 * PurchaseRules — pure validation of a requested quantity against a product's
 * purchase constraints (minimum, maximum, step/multiple). No DB access →
 * unit-testable; cart/checkout call validate() before accepting a line.
 *
 * Rules (all optional; null/0 = no constraint):
 *   min_purchase_qty, max_purchase_qty, qty_step (must buy in multiples of).
 *
 * @see docs/architecture/24-ADMIN-PANEL.md (product module, section 11)
 */
final class PurchaseRules
{
    /**
     * @param array<string,mixed> $rules
     * @return array{ok:bool, message:string}
     */
    public static function validate(float $qty, array $rules): array
    {
        if ($qty <= 0) {
            return ['ok' => false, 'message' => 'Quantity must be at least 1.'];
        }
        $min  = self::num($rules['min_purchase_qty'] ?? null);
        $max  = self::num($rules['max_purchase_qty'] ?? null);
        $step = self::num($rules['qty_step'] ?? null);

        if ($min !== null && $qty < $min) {
            return ['ok' => false, 'message' => 'Minimum purchase quantity is ' . self::fmt($min) . '.'];
        }
        if ($max !== null && $max > 0 && $qty > $max) {
            return ['ok' => false, 'message' => 'Maximum purchase quantity is ' . self::fmt($max) . '.'];
        }
        if ($step !== null && $step > 0) {
            $base = $min !== null && $min > 0 ? $min : 0.0;
            $rem  = fmod($qty - $base, $step);
            if (abs($rem) > 1e-9 && abs($rem - $step) > 1e-9) {
                return ['ok' => false, 'message' => 'Must be bought in multiples of ' . self::fmt($step) . '.'];
            }
        }

        return ['ok' => true, 'message' => ''];
    }

    /** Payment-method permitted by the product's restriction (online|cod|both). */
    public static function paymentAllowed(string $method, string $restriction): bool
    {
        $restriction = in_array($restriction, ['online', 'cod', 'both'], true) ? $restriction : 'both';
        if ($restriction === 'both') {
            return true;
        }
        // 'online' restriction forbids cod; 'cod' restriction forbids online prepaid
        return $restriction === 'online' ? $method !== 'cod' : $method === 'cod';
    }

    private static function num(mixed $v): ?float
    {
        return ($v === null || $v === '' || ! is_numeric($v)) ? null : (float) $v;
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
    }
}
