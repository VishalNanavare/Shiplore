<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * VendorInventoryRepository — stock + pricing for a vendor's variants across
 * its shops. Scoped by vendor_id (inventory via shop ownership; price via the
 * variant's vendor_id). Edits are scoped so a vendor can only change its own.
 */
final class VendorInventoryRepository
{
    /** @return list<array<string,mixed>> */
    public function list(int $vendorId, ?int $shopId = null, ?string $q = null, bool $lowStock = false, int $limit = 30, int $offset = 0): array
    {
        return $this->baseQuery($vendorId, $shopId, $q, $lowStock)
            ->limit($limit, $offset)
            ->get()->getResultArray();
    }

    public function count(int $vendorId, ?int $shopId = null, ?string $q = null, bool $lowStock = false): int
    {
        return $this->baseQuery($vendorId, $shopId, $q, $lowStock)->countAllResults();
    }

    /** Verify a given inventory row belongs to the vendor (via shop ownership). */
    public function ownsInventory(int $inventoryId, int $vendorId): bool
    {
        return (bool) Database::connect()->table('inventory i')
            ->join('shops s', 's.id = i.shop_id', 'inner')
            ->where('i.id', $inventoryId)->where('s.vendor_id', $vendorId)
            ->countAllResults();
    }

    public function updateStock(int $inventoryId, int $vendorId, float $onHand): bool
    {
        if (! $this->ownsInventory($inventoryId, $vendorId)) {
            return false;
        }

        // `available` is a generated column (on_hand - reserved) — set on_hand only.
        return Database::connect()->table('inventory')->where('id', $inventoryId)
            ->update(['on_hand' => $onHand]);
    }

    public function updatePrice(int $variantId, int $vendorId, float $basePrice, float $mrp): bool
    {
        return Database::connect()->table('product_variants')
            ->where('id', $variantId)->where('vendor_id', $vendorId)
            ->update(['base_price' => $basePrice, 'mrp' => $mrp]);
    }

    private function baseQuery(int $vendorId, ?int $shopId, ?string $q, bool $lowStock = false)
    {
        $b = Database::connect()->table('inventory i')
            ->select('i.id, i.shop_id, i.on_hand, i.reserved, i.available, i.reorder_level, p.id AS product_id, p.title, pv.sku, pv.id AS variant_id, pv.base_price, pv.mrp, s.name AS shop, c.name AS category')
            ->join('product_variants pv', 'pv.id = i.variant_id', 'left')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->join('shops s', 's.id = i.shop_id', 'left')
            ->where('s.vendor_id', $vendorId)->where('s.deleted_at', null)
            ->orderBy('p.title', 'ASC');
        if ($shopId !== null) {
            $b->where('i.shop_id', $shopId);
        }
        if ($q !== null && $q !== '') {
            $b->groupStart()->like('p.title', $q)->orLike('pv.sku', $q)->groupEnd();
        }
        if ($lowStock) {
            $b->where('i.reorder_level >', 0)->where('i.on_hand <= i.reorder_level');
        }

        return $b;
    }
}
