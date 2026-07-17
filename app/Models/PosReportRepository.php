<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * PosReportRepository — store-wise and vendor-wide POS sales reporting. Reads the
 * existing pos_sales / pos_sale_payments / pos_sale_items tables. All queries are
 * vendor-scoped; an optional shop filter narrows to one store.
 */
final class PosReportRepository
{
    /** @return array<string,mixed> headline totals for the period */
    public function summary(int $vendorId, ?int $shopId, string $from, string $to): array
    {
        $row = $this->scope($vendorId, $shopId, $from, $to)
            ->select('COUNT(*) AS bills, COALESCE(SUM(grand_total),0) AS sales, COALESCE(SUM(cgst+sgst+igst),0) AS tax, COALESCE(SUM(discount_total),0) AS discount')
            ->where('status !=', 'void')->get()->getRowArray();

        return [
            'bills' => (int) ($row['bills'] ?? 0), 'sales' => (float) ($row['sales'] ?? 0),
            'tax' => (float) ($row['tax'] ?? 0), 'discount' => (float) ($row['discount'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> sales grouped by tender type */
    public function byMethod(int $vendorId, ?int $shopId, string $from, string $to): array
    {
        $b = Database::connect()->table('pos_sale_payments psp')
            ->select('psp.tender_type, COUNT(*) AS txns, COALESCE(SUM(psp.amount),0) AS amount')
            ->join('pos_sales ps', 'ps.id = psp.pos_sale_id')
            ->where('ps.vendor_id', $vendorId)->where('ps.status !=', 'void')
            ->where('DATE(ps.sold_at) >=', $from)->where('DATE(ps.sold_at) <=', $to);
        if ($shopId) {
            $b->where('ps.shop_id', $shopId);
        }

        return $b->groupBy('psp.tender_type')->orderBy('amount', 'DESC')->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> daily sales for a trend */
    public function byDay(int $vendorId, ?int $shopId, string $from, string $to): array
    {
        return $this->scope($vendorId, $shopId, $from, $to)
            ->select('DATE(sold_at) AS day, COUNT(*) AS bills, COALESCE(SUM(grand_total),0) AS sales')
            ->where('status !=', 'void')->groupBy('DATE(sold_at)')->orderBy('day', 'ASC')
            ->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> best sellers by quantity */
    public function topProducts(int $vendorId, ?int $shopId, string $from, string $to, int $limit = 10): array
    {
        $b = Database::connect()->table('pos_sale_items psi')
            ->select('psi.product_title_snapshot AS title, psi.sku_snapshot AS sku, SUM(psi.qty) AS qty, COALESCE(SUM(psi.line_total),0) AS amount')
            ->join('pos_sales ps', 'ps.id = psi.pos_sale_id')
            ->where('ps.vendor_id', $vendorId)->where('ps.status !=', 'void')->where('psi.status', 'active')
            ->where('DATE(ps.sold_at) >=', $from)->where('DATE(ps.sold_at) <=', $to);
        if ($shopId) {
            $b->where('ps.shop_id', $shopId);
        }

        return $b->groupBy('psi.product_title_snapshot, psi.sku_snapshot')->orderBy('qty', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /** Base pos_sales query, vendor + optional shop + date scoped. */
    private function scope(int $vendorId, ?int $shopId, string $from, string $to): object
    {
        $b = Database::connect()->table('pos_sales')
            ->where('vendor_id', $vendorId)->where('DATE(sold_at) >=', $from)->where('DATE(sold_at) <=', $to);
        if ($shopId) {
            $b->where('shop_id', $shopId);
        }

        return $b;
    }
}
