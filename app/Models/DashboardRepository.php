<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * DashboardRepository — real KPI counts + recent activity for the admin
 * dashboard. Every query is fail-safe (returns 0 / [] on any DB error) so a
 * dashboard widget never takes the page down.
 */
final class DashboardRepository
{
    /**
     * Vendors and manufacturers share the `vendors` table, split by party_type. The
     * vendor counts are filtered so manufacturers are not silently reported as vendors.
     *
     * @return array{vendors:int,pending_vendors:int,orders:int,customers:int,manufacturers:int,pending_manufacturers:int,open_purchase_orders:int}
     */
    public function metrics(): array
    {
        return [
            'vendors'         => $this->safeCount(static fn ($db) => $db->table('vendors')->where('deleted_at', null)->where('party_type', 'vendor')),
            'pending_vendors' => $this->safeCount(static fn ($db) => $db->table('vendors')->where('deleted_at', null)->where('party_type', 'vendor')->whereIn('status', ['submitted', 'under_review'])),
            'orders'          => $this->safeCount(static fn ($db) => $db->table('orders')->where('deleted_at', null)),
            'customers'       => $this->safeCount(static fn ($db) => $db->table('customers')->where('deleted_at', null)),

            'manufacturers'         => $this->safeCount(static fn ($db) => $db->table('vendors')->where('deleted_at', null)->where('party_type', 'manufacturer')),
            'pending_manufacturers' => $this->safeCount(static fn ($db) => $db->table('vendors')->where('deleted_at', null)->where('party_type', 'manufacturer')->whereIn('status', ['submitted', 'under_review'])),
            'open_purchase_orders'  => $this->safeCount(static fn ($db) => $db->table('mfg_purchase_orders')->where('deleted_at', null)->whereNotIn('status', ['received', 'closed', 'cancelled', 'rejected'])),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function recentOrders(int $limit = 8): array
    {
        try {
            return Database::connect()->table('sub_orders so')
                ->select('so.sub_order_no, v.display_name AS vendor, so.grand_total, so.status, so.created_at')
                ->join('vendors v', 'v.id = so.vendor_id', 'left')
                ->where('so.deleted_at', null)
                ->orderBy('so.created_at', 'DESC')
                ->limit($limit)
                ->get()->getResultArray();
        } catch (Throwable) {
            return [];
        }
    }

    private function safeCount(callable $build): int
    {
        try {
            return $build(Database::connect())->countAllResults();
        } catch (Throwable) {
            return 0;
        }
    }
}
