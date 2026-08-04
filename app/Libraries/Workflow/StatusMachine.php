<?php

declare(strict_types=1);

namespace App\Libraries\Workflow;

/**
 * StatusMachine — allowed status transitions for orders, sub-orders, deliveries
 * and refunds. Pure: callers check canX($from,$to) before persisting, so illegal
 * moves (e.g. delivered → confirmed) are rejected. Closes the
 * "status transitions not validated" edge case.
 *
 * @see docs/architecture/41-SECURITY-PERFORMANCE.md
 */
final class StatusMachine
{
    private const ORDER = [
        'created' => ['confirmed', 'cancelled'],
        'confirmed' => ['partially_fulfilled', 'completed', 'cancelled'],
        'partially_fulfilled' => ['completed', 'cancelled'],
        'completed' => [], 'cancelled' => [],
    ];

    private const SUB_ORDER = [
        'confirmed' => ['accepted', 'cancelled'],
        'accepted' => ['packed', 'cancelled'],
        'packed' => ['ready', 'cancelled'],
        'ready' => ['out_for_delivery', 'cancelled'],
        'out_for_delivery' => ['delivered', 'cancelled'],
        'delivered' => [], 'cancelled' => [],
    ];

    /**
     * monline B2B purchase orders. Modelled on TRANSFER — it already encodes
     * "goods move between two locations, with an approval and a receipt" — but the
     * two parties are different tenants (a vendor buying from a manufacturer), and
     * the seller may reject a placed order outright.
     */
    private const PURCHASE_ORDER = [
        'draft'              => ['placed', 'cancelled'],
        'placed'             => ['accepted', 'rejected', 'cancelled'],
        'accepted'           => ['packed', 'dispatched', 'cancelled'],
        'packed'             => ['dispatched', 'cancelled'],
        'dispatched'         => ['received', 'partially_received'],
        'partially_received' => ['received', 'closed'],
        'received'           => ['closed'],
        'rejected'           => [], 'cancelled' => [], 'closed' => [],
    ];

    private const DELIVERY = [
        'pending' => ['assigned', 'failed'],
        'assigned' => ['picked_up', 'failed', 'reassigned'],
        'picked_up' => ['arrived', 'out_for_delivery', 'failed', 'returned'],
        'arrived' => ['out_for_delivery', 'failed', 'returned'],
        'out_for_delivery' => ['delivered', 'failed', 'returned'],
        'delivered' => [], 'failed' => ['assigned', 'returned'], 'returned' => [], 'reassigned' => ['assigned'],
    ];

    private const REFUND = [
        'initiated' => ['processing', 'completed', 'failed'],
        'processing' => ['completed', 'failed'],
        'completed' => [], 'failed' => [],
    ];

    // Product approval lifecycle (section 23): vendor submits -> admin reviews ->
    // approve/reject/request-changes -> publish/unpublish.
    private const PRODUCT = [
        'draft' => ['submitted'],
        'submitted' => ['under_review', 'approved', 'rejected', 'draft'],
        'under_review' => ['approved', 'rejected', 'draft'],
        'approved' => ['published', 'unpublished'],
        'rejected' => ['submitted', 'draft'],
        'published' => ['unpublished'],
        'unpublished' => ['published', 'submitted'],
    ];

    /** @return list<string> Valid next statuses for a sub-order in the given state. */
    public static function allowedNextSubOrder(string $from): array
    {
        return self::SUB_ORDER[$from] ?? [];
    }

    public static function canProduct(string $from, string $to): bool
    {
        return self::allowed(self::PRODUCT, $from, $to);
    }

    // Inter-store stock transfer lifecycle (T1). Approve reserves source stock;
    // dispatch ships it (source -); receive books it in (dest +); partial when
    // received < dispatched. cancel before dispatch releases the reservation.
    private const TRANSFER = [
        'requested' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['packed', 'dispatched', 'cancelled'],
        'packed' => ['dispatched', 'cancelled'],
        'dispatched' => ['received', 'partially_received'],
        'partially_received' => ['received', 'closed'],
        'received' => ['closed'],
        'rejected' => [], 'cancelled' => [], 'closed' => [], 'reconciled' => [],
    ];

    public static function canTransfer(string $from, string $to): bool
    {
        return self::allowed(self::TRANSFER, $from, $to);
    }

    public static function canOrder(string $from, string $to): bool
    {
        return self::allowed(self::ORDER, $from, $to);
    }

    /** monline B2B purchase orders. */
    public static function canPurchaseOrder(string $from, string $to): bool
    {
        return self::allowed(self::PURCHASE_ORDER, $from, $to);
    }

    public static function canSubOrder(string $from, string $to): bool
    {
        return self::allowed(self::SUB_ORDER, $from, $to);
    }

    public static function canDelivery(string $from, string $to): bool
    {
        return self::allowed(self::DELIVERY, $from, $to);
    }

    public static function canRefund(string $from, string $to): bool
    {
        return self::allowed(self::REFUND, $from, $to);
    }

    /** @param array<string,list<string>> $map */
    private static function allowed(array $map, string $from, string $to): bool
    {
        if ($from === $to) {
            return true; // idempotent no-op
        }

        return in_array($to, $map[$from] ?? [], true);
    }
}
