<?php

declare(strict_types=1);

namespace App\Libraries\Pricing;

/**
 * PriceResolver — pure effective-price selection. Given a variant's base selling
 * price and the applicable candidate prices (offer/deal/flash overlays, qty
 * tiers, customer-group prices), it picks the price that applies for a context
 * (channel, customer group, shop, quantity, date). No DB access → unit-testable;
 * PricingRepository feeds it the candidates and persists nothing here.
 *
 * Selection rule: among candidates that MATCH the context (channel, group, shop,
 * validity window, min-qty), the highest `priority` wins; ties break to the
 * lowest price (best for the customer). With no match, the base price stands.
 *
 * A candidate is:
 *   ['price'=>float,'priority'=>int,'kind'=>string,'channel'=>'online'|'pos'|'all',
 *    'customer_group_id'=>?int,'shop_id'=>?int,'valid_from'=>?string,
 *    'valid_to'=>?string,'min_qty'=>float]
 * Context is:
 *   ['now'=>string,'channel'=>'online'|'pos','customer_group_id'=>?int,
 *    'shop_id'=>?int,'qty'=>float]
 *
 * @see docs/architecture/24-ADMIN-PANEL.md (product module, section 10)
 */
final class PriceResolver
{
    /**
     * @param list<array<string,mixed>> $candidates
     * @param array<string,mixed>       $ctx
     * @return array{price:float, source:string, priority:int}
     */
    public static function resolve(float $basePrice, array $candidates, array $ctx): array
    {
        $now   = (string) ($ctx['now'] ?? '');
        $chan  = (string) ($ctx['channel'] ?? 'online');
        $group = isset($ctx['customer_group_id']) ? (int) $ctx['customer_group_id'] : null;
        $shop  = isset($ctx['shop_id']) ? (int) $ctx['shop_id'] : null;
        $qty   = (float) ($ctx['qty'] ?? 1);

        $winner = null;
        foreach ($candidates as $c) {
            if (! self::matches($c, $now, $chan, $group, $shop, $qty)) {
                continue;
            }
            if ($winner === null || self::beats($c, $winner)) {
                $winner = $c;
            }
        }

        if ($winner === null) {
            return ['price' => round($basePrice, 4), 'source' => 'base', 'priority' => 0];
        }

        return ['price' => round((float) $winner['price'], 4), 'source' => (string) ($winner['kind'] ?? 'list'), 'priority' => (int) ($winner['priority'] ?? 0)];
    }

    /** @param array<string,mixed> $c */
    private static function matches(array $c, string $now, string $chan, ?int $group, ?int $shop, float $qty): bool
    {
        $cChan = (string) ($c['channel'] ?? 'all');
        if ($cChan !== 'all' && $cChan !== $chan) {
            return false;
        }
        $cGroup = isset($c['customer_group_id']) && $c['customer_group_id'] !== null ? (int) $c['customer_group_id'] : null;
        if ($cGroup !== null && $cGroup !== $group) {
            return false;
        }
        $cShop = isset($c['shop_id']) && $c['shop_id'] !== null ? (int) $c['shop_id'] : null;
        if ($cShop !== null && $cShop !== $shop) {
            return false;
        }
        if ($qty < (float) ($c['min_qty'] ?? 0)) {
            return false;
        }
        $from = (string) ($c['valid_from'] ?? '');
        $to   = (string) ($c['valid_to'] ?? '');
        if ($now !== '' && $from !== '' && $from > $now) {
            return false;
        }
        if ($now !== '' && $to !== '' && $to < $now) {
            return false;
        }

        return true;
    }

    /**
     * Does candidate $a beat the current $b? Higher priority wins; tie → lower price.
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private static function beats(array $a, array $b): bool
    {
        $pa = (int) ($a['priority'] ?? 0);
        $pb = (int) ($b['priority'] ?? 0);
        if ($pa !== $pb) {
            return $pa > $pb;
        }

        return (float) $a['price'] < (float) $b['price'];
    }
}
