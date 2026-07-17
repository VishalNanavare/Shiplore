<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * VendorRefundRepository — refunds on orders that include this vendor's
 * sub-orders. Scoped by vendor_id through the order -> sub_order chain.
 */
final class VendorRefundRepository
{
    /** @return list<array<string,mixed>> */
    public function list(int $vendorId, ?int $shopId = null, int $limit = 30, int $offset = 0): array
    {
        $b = Database::connect()->table('refunds r')
            ->select('r.id, r.amount, r.destination, r.status, r.created_at, o.order_no, p.method')
            ->join('payments p', 'p.id = r.payment_id', 'left')
            ->join('orders o', 'o.id = p.order_id', 'left')
            ->join('sub_orders so', 'so.order_id = o.id', 'inner')
            ->where('so.vendor_id', $vendorId);
        // S0 shop scope: null = all the vendor's shops (owner); an id = that shop;
        // -1 = a staff member with no shop → an empty result, never all.
        if ($shopId !== null) {
            $b->where('so.shop_id', $shopId);
        }

        return $b->groupBy('r.id')
            ->orderBy('r.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->getResultArray();
    }

    public function approve(int $refundId, int $vendorId): bool
    {
        $db = Database::connect();
        // Verify this refund belongs to the vendor via the sub_order chain
        $exists = $db->table('refunds r')
            ->join('payments p', 'p.id = r.payment_id', 'left')
            ->join('orders o', 'o.id = p.order_id', 'left')
            ->join('sub_orders so', 'so.order_id = o.id', 'inner')
            ->where('r.id', $refundId)->where('so.vendor_id', $vendorId)->where('r.status', 'pending')
            ->countAllResults() > 0;

        if (! $exists) {
            return false;
        }

        $db->table('refunds')->where('id', $refundId)->update(['status' => 'approved']);
        return true;
    }

    public function reject(int $refundId, int $vendorId, ?string $reason = null): bool
    {
        $db = Database::connect();
        $exists = $db->table('refunds r')
            ->join('payments p', 'p.id = r.payment_id', 'left')
            ->join('orders o', 'o.id = p.order_id', 'left')
            ->join('sub_orders so', 'so.order_id = o.id', 'inner')
            ->where('r.id', $refundId)->where('so.vendor_id', $vendorId)->where('r.status', 'pending')
            ->countAllResults() > 0;

        if (! $exists) {
            return false;
        }

        $update = ['status' => 'rejected'];
        if ($reason !== null) {
            $update['notes'] = $reason;
        }
        $db->table('refunds')->where('id', $refundId)->update($update);
        return true;
    }

    public function count(int $vendorId, ?int $shopId = null): int
    {
        $sql    = 'SELECT COUNT(DISTINCT r.id) AS cnt FROM refunds r
                   LEFT JOIN payments p ON p.id = r.payment_id
                   LEFT JOIN orders o ON o.id = p.order_id
                   INNER JOIN sub_orders so ON so.order_id = o.id
                   WHERE so.vendor_id = ?';
        $params = [$vendorId];
        if ($shopId !== null) {
            $sql   .= ' AND so.shop_id = ?';
            $params[] = $shopId;
        }

        return (int) Database::connect()->query($sql, $params)->getRow()->cnt;
    }
}
