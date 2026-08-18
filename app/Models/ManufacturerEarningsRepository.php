<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * What a manufacturer has earned selling on monline.
 *
 * The gap this closes was the largest in the panel and the least visible: a manufacturer
 * could accept a purchase order, pack it, dispatch it and have the buyer confirm receipt,
 * and nowhere could they see a rupee of it. The vendor panel has six Finance screens; the
 * manufacturer panel had none, and PurchaseOrderRepository touches neither settlements,
 * commission nor any money ledger.
 *
 * Derived from mfg_purchase_orders rather than written into `settlements`, deliberately,
 * and this is a first phase not a shortcut:
 *
 *   - A settlement is a PAYOUT RUN — a period, a status, a bank reference. Creating them
 *     for manufacturers means deciding a payout cycle and a commission rate, and no
 *     commission configuration exists for B2B at all (there is no column to hang one on).
 *     Those are business decisions, not engineering ones.
 *   - The orders themselves are already the source of truth, so a derived view cannot
 *     drift from them. Nothing here can disagree with the purchase-order screens.
 *   - It ships now. A manufacturer seeing accurate gross earnings today is worth more
 *     than a perfect settlement engine later, and this does not block that engine —
 *     settlement_lines is already generic (ref_type + nullable ref_id, nullable
 *     sub_order_id), so purchase orders can feed it when the cycle is decided.
 *
 * @see \App\Models\SettlementRepository the vendor counterpart, which reads real payout runs
 */
final class ManufacturerEarningsRepository
{
    /**
     * Statuses where the BUYER HAS TAKEN DELIVERY, so the money is genuinely earned.
     *
     * 'dispatched' is deliberately absent. Stock in transit can still be refused at the
     * door, and showing it as earned would tell a manufacturer they have money that may
     * never arrive — the worst possible error in a screen whose whole purpose is trust.
     * 'closed' is included: an order reaches it only after receipt.
     */
    private const EARNED = ['received', 'partially_received', 'closed'];

    /** Accepted and on its way, but not yet earned. Reported separately, never summed in. */
    private const IN_TRANSIT = ['accepted', 'packed', 'dispatched'];

    /** Total value of orders this manufacturer has actually been paid for. */
    public function totalEarned(int $manufacturerId): float
    {
        if ($manufacturerId <= 0) {
            return 0.0;
        }

        $row = $this->base($manufacturerId)
            ->selectSum('grand_total', 'total')
            ->whereIn('status', self::EARNED)
            ->get()->getRowArray();

        return (float) ($row['total'] ?? 0);
    }

    /**
     * Earned, in-transit and a count, in one pass per bucket.
     *
     * @return array{earned:float,in_transit:float,earned_count:int}
     */
    public function summary(int $manufacturerId): array
    {
        if ($manufacturerId <= 0) {
            return ['earned' => 0.0, 'in_transit' => 0.0, 'earned_count' => 0];
        }

        $earned = $this->base($manufacturerId)
            ->selectSum('grand_total', 'total')->selectCount('id', 'n')
            ->whereIn('status', self::EARNED)
            ->get()->getRowArray();

        $transit = $this->base($manufacturerId)
            ->selectSum('grand_total', 'total')
            ->whereIn('status', self::IN_TRANSIT)
            ->get()->getRowArray();

        return [
            'earned'       => (float) ($earned['total'] ?? 0),
            'in_transit'   => (float) ($transit['total'] ?? 0),
            'earned_count' => (int) ($earned['n'] ?? 0),
        ];
    }

    /**
     * The earned orders themselves, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function earnedOrders(int $manufacturerId, int $limit = 200): array
    {
        if ($manufacturerId <= 0) {
            return [];
        }

        return $this->base($manufacturerId)
            ->select('id, po_no, buyer_vendor_id, grand_total, status, created_at')
            ->whereIn('status', self::EARNED)
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    /**
     * Scoped to this manufacturer AS THE SELLER.
     *
     * seller_vendor_id, never buyer_vendor_id — a manufacturer that also buys must not
     * see its own purchases counted as income, and both columns hold vendors ids so a
     * mistake here reads as plausible.
     */
    private function base(int $manufacturerId): object
    {
        return Database::connect()->table('mfg_purchase_orders')
            ->where('seller_vendor_id', $manufacturerId)
            ->where('deleted_at', null);
    }
}
