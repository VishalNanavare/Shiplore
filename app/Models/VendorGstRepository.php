<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * VendorGstRepository — GST collected by a vendor (from its sub-orders). Scoped
 * by vendor_id. Fail-safe.
 */
final class VendorGstRepository
{
    /** @return array<string,float|int> */
    public function summary(int $vendorId): array
    {
        try {
            $row = Database::connect()->table('sub_orders')
                ->select('COUNT(*) AS orders, COALESCE(SUM(taxable_value),0) AS taxable, COALESCE(SUM(cgst),0) AS cgst, COALESCE(SUM(sgst),0) AS sgst, COALESCE(SUM(igst),0) AS igst, COALESCE(SUM(cess),0) AS cess')
                ->where('vendor_id', $vendorId)->where('deleted_at', null)
                ->get()->getRowArray();
        } catch (Throwable) {
            $row = null;
        }

        return [
            'orders'  => (int) ($row['orders'] ?? 0),
            'taxable' => (float) ($row['taxable'] ?? 0),
            'cgst'    => (float) ($row['cgst'] ?? 0),
            'sgst'    => (float) ($row['sgst'] ?? 0),
            'igst'    => (float) ($row['igst'] ?? 0),
            'cess'    => (float) ($row['cess'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> Per sub-order GST breakdown. */
    public function lines(int $vendorId, int $limit = 50): array
    {
        try {
            return Database::connect()->table('sub_orders')
                ->select('sub_order_no, place_of_supply, taxable_value, cgst, sgst, igst, grand_total, created_at')
                ->where('vendor_id', $vendorId)->where('deleted_at', null)
                ->orderBy('created_at', 'DESC')->limit($limit)
                ->get()->getResultArray();
        } catch (Throwable) {
            return [];
        }
    }
}
