<?php

declare(strict_types=1);

namespace App\Libraries\Inventory;

/**
 * StockValuation — pure inventory costing over a list of cost layers (batches).
 * Supports FIFO / LIFO consumption (COGS) and weighted-average + total on-hand
 * value. No DB access → fully unit-testable; InventoryService feeds it the open
 * stock_batches and persists the result.
 *
 * A "layer" is ['qty' => float, 'cost' => float] (oldest-first as given).
 *
 * @see docs/architecture/24-ADMIN-PANEL.md (product module, section 8)
 */
final class StockValuation
{
    /** Total value of all on-hand layers: Σ qty×cost. @param list<array{qty:float,cost:float}> $layers */
    public static function onHandValue(array $layers): float
    {
        $sum = 0.0;
        foreach ($layers as $l) {
            $sum += (float) $l['qty'] * (float) $l['cost'];
        }

        return round($sum, 4);
    }

    /** Total on-hand quantity. @param list<array{qty:float,cost:float}> $layers */
    public static function onHandQty(array $layers): float
    {
        $sum = 0.0;
        foreach ($layers as $l) {
            $sum += (float) $l['qty'];
        }

        return round($sum, 4);
    }

    /** Weighted-average unit cost (0 when empty). @param list<array{qty:float,cost:float}> $layers */
    public static function weightedAverageCost(array $layers): float
    {
        $qty = self::onHandQty($layers);

        return $qty > 0 ? round(self::onHandValue($layers) / $qty, 4) : 0.0;
    }

    /**
     * Consume $qty using FIFO or LIFO and return the cost of goods issued plus
     * the remaining layers. Caller decides what to do if stock is short (cogs is
     * computed only over what was actually available).
     *
     * @param list<array{qty:float,cost:float}> $layers
     * @return array{cogs:float, consumed:float, short:float, remaining:list<array{qty:float,cost:float}>}
     */
    public static function consume(array $layers, float $qty, string $method = 'fifo'): array
    {
        $order = array_values($layers);
        if (strtolower($method) === 'lifo') {
            $order = array_reverse($order);
        } elseif (strtolower($method) === 'wavg') {
            // average everything into one synthetic layer
            $q = self::onHandQty($layers);
            $order = $q > 0 ? [['qty' => $q, 'cost' => self::weightedAverageCost($layers)]] : [];
        }

        $need = max(0.0, $qty);
        $cogs = 0.0;
        $remaining = [];
        foreach ($order as $layer) {
            $available = (float) $layer['qty'];
            if ($need <= 0) {
                $remaining[] = $layer;
                continue;
            }
            $take = min($available, $need);
            $cogs += $take * (float) $layer['cost'];
            $need -= $take;
            $left = $available - $take;
            if ($left > 0) {
                $remaining[] = ['qty' => round($left, 4), 'cost' => (float) $layer['cost']];
            }
        }

        $consumed = round(max(0.0, $qty) - $need, 4);

        return [
            'cogs'      => round($cogs, 4),
            'consumed'  => $consumed,
            'short'     => round($need, 4),
            'remaining' => $remaining,
        ];
    }
}
