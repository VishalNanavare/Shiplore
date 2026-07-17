<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * StoreWishlistRepository — the customer app's account-synced wishlist over the
 * existing `wishlists` / `wishlist_items` tables (variant-keyed). Returns items in
 * the same shape as the catalog product list so the app reuses its ProductCard.
 *
 * @see app/Controllers/Api/V1/CustomerApiController.php (wishlist/addWishlist/removeWishlist)
 */
final class StoreWishlistRepository
{
    private const IMG = "(SELECT ma.uuid FROM product_media pm JOIN media_assets ma ON ma.id = pm.media_id WHERE pm.product_id = p.id AND pm.deleted_at IS NULL AND ma.deleted_at IS NULL AND ma.status = 'active' ORDER BY pm.is_primary DESC, pm.sort_order ASC LIMIT 1) AS image_uuid";

    /** Find (or create) this customer's default wishlist; returns its id. */
    public function defaultWishlistId(int $customerId): int
    {
        $db  = Database::connect();
        $row = $db->table('wishlists')->select('id')
            ->where('customer_id', $customerId)->where('deleted_at', null)
            ->orderBy('is_default', 'DESC')->orderBy('id', 'ASC')
            ->get()->getRowArray();
        if ($row !== null) {
            return (int) $row['id'];
        }
        $db->table('wishlists')->insert(['customer_id' => $customerId, 'name' => 'My Wishlist', 'is_default' => 1]);

        return (int) $db->insertID();
    }

    /** Wishlisted products (Product-shaped rows, newest first). @return list<array<string,mixed>> */
    public function forCustomer(int $customerId): array
    {
        return Database::connect()->table('wishlist_items wi')
            ->select('p.id, p.title, p.slug, p.product_type, c.name AS category, c.slug AS category_slug, v.display_name AS vendor, p.vendor_id, pv.base_price, pv.mrp, pv.id AS variant_id')
            ->select('(SELECT COUNT(*) FROM product_variants pvc WHERE pvc.product_id = p.id AND pvc.deleted_at IS NULL) AS variant_count', false)
            ->select(self::IMG, false)
            ->join('wishlists w', 'w.id = wi.wishlist_id', 'inner')
            ->join('product_variants pv', 'pv.id = wi.variant_id', 'inner')
            ->join('products p', 'p.id = pv.product_id', 'inner')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->join('vendors v', 'v.id = p.vendor_id', 'left')
            ->where('w.customer_id', $customerId)->where('w.deleted_at', null)
            ->where('p.deleted_at', null)->where('p.status', 'published')
            ->orderBy('wi.added_at', 'DESC')
            ->get()->getResultArray();
    }

    /** Add a variant to the default wishlist (idempotent). False = variant not found. */
    public function add(int $customerId, int $variantId): bool
    {
        $db = Database::connect();
        if ($db->table('product_variants')->where('id', $variantId)->where('deleted_at', null)->countAllResults() === 0) {
            return false;
        }
        $wid = $this->defaultWishlistId($customerId);
        if ($db->table('wishlist_items')->where('wishlist_id', $wid)->where('variant_id', $variantId)->countAllResults() > 0) {
            return true; // already wishlisted (UNIQUE) → no-op
        }
        $db->table('wishlist_items')->insert(['wishlist_id' => $wid, 'variant_id' => $variantId, 'added_at' => date('Y-m-d H:i:s')]);

        return true;
    }

    /** Remove a variant from all of this customer's wishlists (idempotent). */
    public function remove(int $customerId, int $variantId): bool
    {
        $db  = Database::connect();
        $ids = array_map(static fn ($r) => (int) $r['id'], $db->table('wishlists')->select('id')->where('customer_id', $customerId)->get()->getResultArray());
        if ($ids !== []) {
            $db->table('wishlist_items')->whereIn('wishlist_id', $ids)->where('variant_id', $variantId)->delete();
        }

        return true;
    }
}
