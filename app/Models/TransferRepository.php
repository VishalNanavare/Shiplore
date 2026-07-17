<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * TransferRepository — admin approval of stock transfers between shops (future
 * expansion). Lists transfers with from/to shop + variant, and transitions
 * status (approve / cancel). Fail-safe.
 *
 * @see docs/architecture/32-OPS-EXPANSION-SEO-DR.md
 */
final class TransferRepository
{
    /** Admin view: transfers across ALL vendors, with the vendor + lifecycle fields. @return list<array<string,mixed>> */
    public function list(?string $status = null, ?int $vendorId = null): array
    {
        $builder = Database::connect()->table('stock_transfers t')
            ->select('t.id, t.transfer_no, t.qty, t.qty_dispatched, t.qty_received, t.status, t.vendor_id, t.created_at, v.display_name AS vendor, fs.name AS from_shop, ts.name AS to_shop, pv.sku, p.title')
            ->join('shops fs', 'fs.id = t.from_shop_id', 'left')
            ->join('shops ts', 'ts.id = t.to_shop_id', 'left')
            ->join('product_variants pv', 'pv.id = t.variant_id', 'left')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->join('vendors v', 'v.id = t.vendor_id', 'left')
            ->where('t.deleted_at', null)
            ->orderBy('t.created_at', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('t.status', $status);
        }
        if ($vendorId !== null && $vendorId > 0) {
            $builder->where('t.vendor_id', $vendorId);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('stock_transfers')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('stock_transfers')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }
}
