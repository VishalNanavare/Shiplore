<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * ProductShopRepository — which of a vendor's shops carry/sell a product.
 * A product is owned by a vendor (products.vendor_id); this mapping says which
 * shops list it, so POS can scope a shop's catalog. Unticking a shop sets the
 * row inactive (kept for history) rather than hard-deleting.
 *
 * @see database/sql/43_product_shops.sql
 */
final class ProductShopRepository
{
    /** @return list<int> active shop ids carrying the product */
    public function forProduct(int $productId): array
    {
        $rows = Database::connect()->table('product_shops')
            ->select('shop_id')
            ->where('product_id', $productId)->where('status', 'active')
            ->get()->getResultArray();

        return array_map(static fn ($r) => (int) $r['shop_id'], $rows);
    }

    /**
     * Set the product's active shop set to exactly $shopIds: re-activate/insert
     * the chosen shops, mark the rest inactive. Idempotent.
     *
     * @param list<int> $shopIds
     */
    public function sync(int $productId, array $shopIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $shopIds))));
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');

        // Deactivate any shop no longer selected (all of them when $ids is empty).
        $off = $db->table('product_shops')->where('product_id', $productId);
        if ($ids !== []) {
            $off->whereNotIn('shop_id', $ids);
        }
        $off->update(['status' => 'inactive']);

        // Re-activate or insert each chosen shop.
        foreach ($ids as $sid) {
            $exists = $db->table('product_shops')
                ->where('product_id', $productId)->where('shop_id', $sid)
                ->countAllResults() > 0;
            if ($exists) {
                $db->table('product_shops')
                    ->where('product_id', $productId)->where('shop_id', $sid)
                    ->update(['status' => 'active', 'listed_at' => $now]);
            } else {
                $db->table('product_shops')->insert([
                    'product_id' => $productId, 'shop_id' => $sid,
                    'status' => 'active', 'listed_at' => $now,
                ]);
            }
        }
    }

    /**
     * Active, non-deleted shops for a vendor — the option list for the picker.
     * @return list<array{id:int,name:string}>
     */
    public function shopsForVendor(int $vendorId): array
    {
        $rows = Database::connect()->table('shops')
            ->select('id, name')
            ->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->whereIn('status', ['active', 'inactive'])
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);
    }
}
