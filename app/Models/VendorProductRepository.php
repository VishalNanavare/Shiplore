<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * VendorProductRepository — a vendor's own products (with category + default
 * variant pricing). Scoped by vendor_id.
 */
final class VendorProductRepository
{
    /** @return list<array<string,mixed>> */
    /** @param int|null $shopId when given, only products listed in that shop (branch-manager scope). */
    public function list(int $vendorId, ?string $status = null, ?int $shopId = null): array
    {
        $builder = Database::connect()->table('products p')
            ->select('p.id, p.title, p.slug, p.status, p.description, p.category_id, c.name AS category, pv.sku, pv.base_price, pv.mrp, (SELECT COUNT(*) FROM product_variants pv2 WHERE pv2.product_id = p.id AND pv2.deleted_at IS NULL) AS variant_count, (SELECT m.uuid FROM media_assets m INNER JOIN product_media pm ON pm.media_id = m.id WHERE pm.product_id = p.id ORDER BY pm.sort_order, pm.id LIMIT 1) AS image_uuid', false)
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left')
            ->where('p.vendor_id', $vendorId)->where('p.deleted_at', null)
            ->orderBy('p.created_at', 'DESC')->limit(200);

        if ($shopId !== null) {
            $builder->join('product_shops ps', 'ps.product_id = p.id AND ps.shop_id = ' . (int) $shopId . " AND ps.status = 'active'", 'inner');
        }
        if ($status !== null && $status !== '') {
            $builder->where('p.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    public function setStatus(int $productId, int $vendorId, string $status): bool
    {
        return Database::connect()->table('products')
            ->where('id', $productId)->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->update(['status' => $status]) > 0;
    }

    public function updateVariantPrice(int $variantId, int $productId, int $vendorId, float $price, ?float $mrp = null): bool
    {
        $db      = Database::connect();
        $isOwned = $db->table('products')
            ->where('id', $productId)->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->countAllResults() > 0;
        if (! $isOwned) {
            return false;
        }
        $data = ['base_price' => $price];
        if ($mrp !== null) {
            $data['mrp'] = $mrp;
        }

        return $db->table('product_variants')
            ->where('id', $variantId)->where('product_id', $productId)->where('deleted_at', null)
            ->update($data) > 0;
    }

    /**
     * Create a new product with a single default variant. Returns the new product id,
     * or null if the category doesn't exist or the insert fails.
     * @param array<string,mixed> $data
     */
    public function create(int $vendorId, array $data): ?int
    {
        $db = Database::connect();
        // Verify category exists and is active; fetch its default tax class.
        $cat = $db->table('categories')
            ->select('id, default_tax_class_id')
            ->where('id', (int) $data['category_id'])->where('status', 'active')
            ->get()->getRowArray();
        if ($cat === null) {
            return null;
        }
        $taxClassId = (int) ($cat['default_tax_class_id'] ?? 1);

        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', (string) $data['title']));
        $slug .= '-' . substr(uniqid('', true), -6);

        $db->transStart();
        $db->table('products')->insert([
            'uuid'         => bin2hex(random_bytes(18)),
            'vendor_id'    => $vendorId,
            'category_id'  => (int) $data['category_id'],
            'tax_class_id' => $taxClassId,
            'base_unit_id' => (int) ($data['base_unit_id'] ?? 1), // 1 = pcs default
            'title'        => (string) $data['title'],
            'slug'         => $slug,
            'description'  => $data['description'] ?? null,
            'status'       => $data['status'] ?? 'draft',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $productId = (int) $db->insertID();
        if ($productId === 0) {
            $db->transRollback();

            return null;
        }

        $titleCode = (string) preg_replace('/[^A-Z0-9]/i', '', strtoupper((string) $data['title']));
        $sku       = substr($titleCode, 0, 8) . '-' . $productId;

        $db->table('product_variants')->insert([
            'uuid'       => bin2hex(random_bytes(18)),
            'product_id' => $productId,
            'vendor_id'  => $vendorId,
            'sku'        => $sku,
            'unit_id'    => (int) ($data['base_unit_id'] ?? 1),
            'base_price' => (float) $data['base_price'],
            'mrp'        => isset($data['mrp']) ? (float) $data['mrp'] : (float) $data['base_price'],
            'is_default' => 1,
            'status'     => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $db->transComplete();

        return $db->transStatus() ? $productId : null;
    }

    /**
     * Add a new variant to an existing product owned by the vendor.
     * Returns the new variant id, or null if ownership check fails.
     * @param array<string,mixed> $data
     */
    public function addVariant(int $productId, int $vendorId, array $data): ?int
    {
        $db    = Database::connect();
        $owns  = $db->table('products')
            ->where('id', $productId)->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->countAllResults() > 0;
        if (! $owns) {
            return null;
        }
        $db->table('product_variants')->insert([
            'uuid'       => bin2hex(random_bytes(18)),
            'product_id' => $productId,
            'vendor_id'  => $vendorId,
            'sku'        => (string) $data['sku'],
            'unit_id'    => (int) ($db->table('products')->select('base_unit_id')->where('id', $productId)->get()->getRowArray()['base_unit_id'] ?? 1),
            'base_price' => (float) $data['base_price'],
            'mrp'        => isset($data['mrp']) ? (float) $data['mrp'] : (float) $data['base_price'],
            'is_default' => 0,
            'status'     => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();

        return $id > 0 ? $id : null;
    }

    /**
     * Vendor-scoped variants for one of the vendor's own products (all statuses,
     * including inactive, with stock). Returns null when the product isn't owned.
     * @return list<array<string,mixed>>|null
     */
    public function variants(int $productId, int $vendorId): ?array
    {
        $db   = Database::connect();
        $owns = $db->table('products')->where('id', $productId)->where('vendor_id', $vendorId)->where('deleted_at', null)->countAllResults();
        if ($owns === 0) {
            return null;
        }

        return $db->table('product_variants pv')
            ->select('pv.id AS variant_id, pv.sku, pv.barcode, pv.base_price, pv.mrp, pv.status, pv.is_default', false)
            ->select('(SELECT COALESCE(SUM(i.available), 0) FROM inventory i WHERE i.variant_id = pv.id) AS stock', false)
            ->select("GROUP_CONCAT(CONCAT(a.name, ': ', av.value) ORDER BY a.name SEPARATOR ' / ') AS label", false)
            ->join('variant_attribute_values vav', 'vav.variant_id = pv.id', 'left')
            ->join('attribute_values av', 'av.id = vav.attribute_value_id', 'left')
            ->join('attributes a', 'a.id = vav.attribute_id', 'left')
            ->where('pv.product_id', $productId)->where('pv.deleted_at', null)
            ->groupBy('pv.id')->orderBy('pv.is_default', 'DESC')->orderBy('pv.id')
            ->get()->getResultArray();
    }
}
