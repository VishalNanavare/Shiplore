<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * ReportRepository — aggregate read-only metrics for the admin reports page.
 * Every query is wrapped fail-safe so a missing/empty table never breaks the
 * page.
 *
 * @see docs/architecture/13-REPORTING-ARCHITECTURE.md
 */
final class ReportRepository
{
    /** @return array<string,mixed> */
    public function summary(): array
    {
        return [
            'orders_total'      => $this->safe(fn () => $this->db()->table('orders')->where('deleted_at', null)->countAllResults()),
            'gross_sales'       => $this->safeSum('orders', 'grand_total', ['payment_status' => 'paid']),
            'commission_earned' => $this->safeSum('sub_orders', 'commission_amount'),
            'refunds_total'     => $this->safeSum('refunds', 'amount', ['status' => 'completed']),
            'settlements_due'   => $this->safeSum('settlements', 'net_payable', ['status' => 'approved']),
            'active_vendors'    => $this->safe(fn () => $this->db()->table('vendors')->where('status', 'active')->where('deleted_at', null)->countAllResults()),
            'customers'         => $this->safe(fn () => $this->db()->table('customers')->where('deleted_at', null)->countAllResults()),
            'pending_reviews'   => $this->safe(fn () => $this->db()->table('products')->whereIn('status', ['submitted', 'under_review'])->where('deleted_at', null)->countAllResults()),
        ];
    }

    /** @return list<array<string,mixed>> Sales grouped by order channel. */
    public function salesByChannel(): array
    {
        return $this->safe(fn () => $this->db()->table('orders')
            ->select('channel, COUNT(*) AS orders, COALESCE(SUM(grand_total),0) AS revenue')
            ->where('deleted_at', null)->groupBy('channel')->get()->getResultArray(), []);
    }

    /** @return list<array<string,mixed>> Top vendors by sub-order revenue. */
    public function topVendors(int $limit = 5): array
    {
        return $this->safe(fn () => $this->db()->table('sub_orders so')
            ->select('v.display_name AS vendor, COUNT(*) AS orders, COALESCE(SUM(so.grand_total),0) AS revenue')
            ->join('vendors v', 'v.id = so.vendor_id', 'left')
            ->where('so.deleted_at', null)->groupBy('so.vendor_id')
            ->orderBy('revenue', 'DESC')->limit($limit)->get()->getResultArray(), []);
    }

    /** @return array<string,mixed> GST collected in a period (GSTR summary). */
    public function gstSummary(string $start, string $end): array
    {
        return $this->safe(function () use ($start, $end) {
            $row = $this->db()->table('sub_orders')
                ->select('COALESCE(SUM(taxable_value),0) AS taxable, COALESCE(SUM(cgst),0) AS cgst, COALESCE(SUM(sgst),0) AS sgst, COALESCE(SUM(igst),0) AS igst, COALESCE(SUM(grand_total),0) AS grand, COUNT(*) AS orders')
                ->where('deleted_at', null)->where('created_at >=', $start . ' 00:00:00')->where('created_at <=', $end . ' 23:59:59')
                ->get()->getRowArray();

            return $row ?: ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0, 'grand' => 0, 'orders' => 0];
        }, ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0, 'grand' => 0, 'orders' => 0]);
    }

    /** @return list<array<string,mixed>> rows for the sales/GST CSV export. */
    public function exportRows(string $start, string $end): array
    {
        return $this->safe(fn () => $this->db()->table('sub_orders so')
            ->select('o.order_no, so.sub_order_no, v.display_name AS vendor, so.taxable_value, so.cgst, so.sgst, so.igst, so.grand_total, so.status, so.created_at')
            ->join('orders o', 'o.id = so.order_id', 'left')
            ->join('vendors v', 'v.id = so.vendor_id', 'left')
            ->where('so.deleted_at', null)->where('so.created_at >=', $start . ' 00:00:00')->where('so.created_at <=', $end . ' 23:59:59')
            ->orderBy('so.created_at', 'ASC')->limit(5000)->get()->getResultArray(), []);
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

    /** @param array<string,mixed> $where */
    private function safeSum(string $table, string $column, array $where = []): float
    {
        return $this->safe(function () use ($table, $column, $where) {
            $b = $this->db()->table($table)->selectSum($column, 'total');
            foreach ($where as $k => $v) {
                $b->where($k, $v);
            }
            $row = $b->get()->getRowArray();

            return (float) ($row['total'] ?? 0);
        }, 0.0);
    }
}
