<?php

declare(strict_types=1);

namespace App\Libraries\Store;

/**
 * DeliveryMessages — the single source of customer-facing copy for delivery-area
 * validation, keyed by the canonical reason vocabulary shared with the mobile app
 * (location_required | outside_service_area | shop_no_delivery | no_serving_shop).
 * Keeping the wording here means the web cart flash, the checkout warning box and
 * the order-blocked message all stay consistent.
 */
final class DeliveryMessages
{
    /** Short phrase used when summarising removed cart items (a "{n} items removed — {phrase}"). */
    public static function removalPhrase(string $reason): string
    {
        return match ($reason) {
            'outside_service_area' => 'outside our delivery area',
            'shop_no_delivery'     => "the store doesn't deliver to this location",
            'no_serving_shop'      => 'no store delivers them to this location',
            'location_required'    => 'no delivery address is pinned',
            default                => 'not available at this location',
        };
    }

    /** Full sentence used in the checkout undeliverable box / blocked-order message. */
    public static function reasonText(string $reason): string
    {
        return match ($reason) {
            'outside_service_area' => 'Outside our delivery area.',
            'shop_no_delivery'     => "This store doesn't deliver to your location.",
            'no_serving_shop'      => 'No store delivers this to your location yet.',
            'location_required'    => 'Pin your delivery address on the map.',
            default                => "Can't be delivered to your location.",
        };
    }

    /**
     * Build a grouped "N item(s) removed — reason." summary from removeUndeliverable()
     * output, one clause per distinct reason so multi-shop carts read accurately.
     *
     * @param list<array{variant_id:int, shop_id:?int, title:string, reason:string}> $removed
     */
    public static function removedSummary(array $removed): string
    {
        $counts = [];
        foreach ($removed as $r) {
            $reason          = (string) ($r['reason'] ?? '');
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }
        $parts = [];
        foreach ($counts as $reason => $n) {
            $parts[] = $n . ' item' . ($n === 1 ? '' : 's') . ' removed — ' . self::removalPhrase($reason) . '.';
        }

        return implode(' ', $parts);
    }
}
