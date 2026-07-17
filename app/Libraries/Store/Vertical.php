<?php

declare(strict_types=1);

namespace App\Libraries\Store;

/**
 * Vertical — order lifecycle rules for the (now single) quick-commerce vertical.
 *
 * The storefront is one unified quick-commerce catalog: every product is "goods".
 * The food/restaurant vertical and hotels have been removed from the storefront
 * and order process. forBusinessType() therefore always returns GOODS; the class
 * is retained as the single home for cancel/return timing rules.
 */
final class Vertical
{
    public const GOODS = 'goods';

    /** Single quick-commerce vertical — everything is goods. */
    public static function forBusinessType(?string $code): string
    {
        return self::GOODS;
    }

    /** Human label for messages/UI. */
    public static function label(string $v): string
    {
        return 'Shopping';
    }

    /** Delivered items can be returned (within the window). */
    public static function canReturn(string $v): bool
    {
        return true;
    }

    /** Days after delivery within which a return may be requested. */
    public static function returnWindowDays(string $v): int
    {
        return 7;
    }

    /**
     * Sub-order statuses at/after which a customer may NO LONGER cancel
     * (once the order is on its way out for delivery).
     * @return list<string>
     */
    public static function cancelLockStatuses(string $v): array
    {
        return ['out_for_delivery', 'delivered', 'completed', 'cancelled', 'returned'];
    }
}
