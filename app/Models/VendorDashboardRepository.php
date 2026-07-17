<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * VendorDashboardRepository — fail-safe KPIs + recent orders for ONE vendor.
 * Every query is scoped by $vendorId.
 */
final class VendorDashboardRepository
{
    private const OPEN_SUB = ['pending', 'confirmed', 'accepted', 'packed', 'ready', 'out_for_delivery'];
    private const DONE_SUB = ['delivered', 'completed'];

    /** @return array<string,int|float> */
    public function metrics(int $vendorId): array
    {
        $today = date('Y-m-d');

        return [
            'shops'           => $this->safe(fn () => $this->db()->table('shops')->where('vendor_id', $vendorId)->where('deleted_at', null)->countAllResults()),
            'products'        => $this->safe(fn () => $this->db()->table('products')->where('vendor_id', $vendorId)->where('deleted_at', null)->countAllResults()),
            'open_orders'     => $this->safe(fn () => $this->db()->table('sub_orders')->where('vendor_id', $vendorId)->whereIn('status', self::OPEN_SUB)->where('deleted_at', null)->countAllResults()),
            'sales'           => $this->safe(function () use ($vendorId) {
                $row = $this->db()->table('sub_orders')->selectSum('grand_total', 't')
                    ->where('vendor_id', $vendorId)->whereIn('status', self::DONE_SUB)->where('deleted_at', null)
                    ->get()->getRowArray();

                return (float) ($row['t'] ?? 0);
            }, 0.0),
            'today_orders'    => $this->safe(fn () => $this->db()->table('sub_orders')
                ->where('vendor_id', $vendorId)->where('deleted_at', null)
                ->where("DATE(created_at) =", $today)->countAllResults()),
            'today_revenue'   => $this->safe(function () use ($vendorId, $today) {
                $row = $this->db()->table('sub_orders')->selectSum('grand_total', 't')
                    ->where('vendor_id', $vendorId)->whereIn('status', self::DONE_SUB)
                    ->where('deleted_at', null)->where("DATE(created_at) =", $today)
                    ->get()->getRowArray();

                return (float) ($row['t'] ?? 0);
            }, 0.0),
            'pending_actions' => $this->safe(fn () => $this->db()->table('sub_orders')
                ->where('vendor_id', $vendorId)->where('status', 'confirmed')->where('deleted_at', null)->countAllResults()),
            'low_stock_count'      => $this->safe(fn () => $this->db()->table('inventory i')
                ->join('product_variants pv', 'pv.id = i.variant_id', 'left')
                ->where('pv.vendor_id', $vendorId)->where('i.reorder_level >', 0)
                ->where('i.available <= i.reorder_level')->countAllResults()),
            'today_refunds_count'  => $this->safe(fn () => $this->db()->table('refunds r')
                ->join('payments p', 'p.id = r.payment_id', 'left')
                ->join('sub_orders so', 'so.id = p.sub_order_id', 'left')
                ->where('so.vendor_id', $vendorId)
                ->where("DATE(r.created_at) =", $today)->countAllResults()),
            'open_shops'           => $this->safe(fn () => $this->db()->table('shops')
                ->where('vendor_id', $vendorId)->where('status', 'open')->where('deleted_at', null)->countAllResults()),
        ];
    }

    /** 7-day order counts for the mini bar chart. @return list<array{date:string,cnt:int}> */
    public function chart(int $vendorId): array
    {
        return $this->safe(fn () => $this->db()->query(
            'SELECT DATE(created_at) AS `date`, COUNT(*) AS cnt
             FROM sub_orders
             WHERE vendor_id = ? AND deleted_at IS NULL
               AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             GROUP BY DATE(created_at)
             ORDER BY `date` ASC',
            [$vendorId]
        )->getResultArray(), []);
    }

    /**
     * Per-shop breakdown (open orders + sales) for the given shops. @param list<int> $shopIds
     * @return list<array<string,mixed>>
     */
    public function perShop(int $vendorId, array $shopIds): array
    {
        if ($shopIds === []) {
            return [];
        }
        $open = "'" . implode("','", self::OPEN_SUB) . "'";
        $done = "'" . implode("','", self::DONE_SUB) . "'";

        return $this->safe(fn () => $this->db()->table('shops s')
            ->select("s.id, s.name, s.status,
                COUNT(CASE WHEN so.status IN ({$open}) THEN 1 END) AS open_orders,
                COALESCE(SUM(CASE WHEN so.status IN ({$done}) THEN so.grand_total ELSE 0 END),0) AS sales", false)
            ->join('sub_orders so', 'so.shop_id = s.id AND so.deleted_at IS NULL', 'left')
            ->whereIn('s.id', $shopIds)->where('s.vendor_id', $vendorId)->where('s.deleted_at', null)
            ->groupBy('s.id')->orderBy('s.name', 'ASC')
            ->get()->getResultArray(), []);
    }

    /** @return list<array<string,mixed>> */
    public function recentOrders(int $vendorId, int $limit = 8): array
    {
        return $this->safe(fn () => $this->db()->table('sub_orders so')
            ->select('so.id, so.sub_order_no, so.grand_total, so.status, so.created_at, o.order_no, u.name AS customer')
            ->join('orders o', 'o.id = so.order_id', 'left')
            ->join('customers c', 'c.id = o.customer_id', 'left')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->where('so.vendor_id', $vendorId)->where('so.deleted_at', null)
            ->orderBy('so.created_at', 'DESC')->limit($limit)
            ->get()->getResultArray(), []);
    }

    private function db()
    {
        return Database::connect();
    }

    /** @param callable():mixed $fn */
    private function safe(callable $fn, mixed $default = 0): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return $default;
        }
    }
}
