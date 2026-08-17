<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Catalog\ManufacturerPricing;
use Config\Database;

/**
 * ManufacturerProductRepository — products owned by a manufacturer.
 *
 * Products and product_variants are shared tables (a manufacturer IS a `vendors` row),
 * so `vendor_id` here holds the manufacturer's id. What differs is the price shape:
 *
 *   vendor        mrp + base_price   ("MRP" and "Selling price")
 *   manufacturer  making_price + base_price   ("Making price" and "Selling price")
 *
 * base_price is the selling price in BOTH cases — it is the column the whole platform
 * reads. mrp is left NULL for manufacturer products; making_price is theirs alone.
 *
 * The 0 < making < selling invariant is re-checked here, not just in the controller.
 * The equivalent vendor path (AdminProductRepository::autosave) writes prices with no
 * validation at all, which is exactly the gap this class must not reproduce: a repository
 * that trusts its caller is one forgotten guard away from bad data.
 */
final class ManufacturerProductRepository
{
    /** @return list<array<string,mixed>> */
    public function list(int $manufacturerId, ?string $status = null, ?int $mshopId = null): array
    {
        if ($manufacturerId <= 0) {
            return [];
        }

        // variant_count and image_uuid are correlated subqueries modelled on
        // VendorProductRepository::list() — both are party-agnostic (they key on
        // product_id alone), so the manufacturer list shows the same thumbnail and
        // variant count the vendor list does. `false` on select() keeps them from being
        // escaped as identifiers.
        //
        // Table names go through prefixTable(): raw SQL inside select() is NOT prefixed
        // by the query builder, unlike table()/join(). Production runs with an empty
        // DBPrefix so a bare name happens to work there, but it breaks anywhere one is
        // set — including the test database, which uses `db_`.
        $db = Database::connect();
        $b  = $db->table('products p')
            ->select(
                'p.id, p.title, p.slug, p.status, p.created_at, c.name AS category,
                 pv.id AS variant_id, pv.sku, pv.making_price, pv.base_price,
                 (SELECT COUNT(*) FROM ' . $db->prefixTable('product_variants') . ' pv2
                    WHERE pv2.product_id = p.id AND pv2.deleted_at IS NULL) AS variant_count,
                 (SELECT m.uuid FROM ' . $db->prefixTable('media_assets') . ' m
                    INNER JOIN ' . $db->prefixTable('product_media') . ' pm ON pm.media_id = m.id
                    WHERE pm.product_id = p.id ORDER BY pm.sort_order, pm.id LIMIT 1) AS image_uuid',
                false,
            )
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left')
            ->where('p.vendor_id', $manufacturerId)
            ->where('p.deleted_at', null);

        if ($status !== null && $status !== '') {
            $b->where('p.status', $status);
        }
        // Unit scope: a store keeper assigned to one unit sees only what that unit lists.
        if ($mshopId !== null) {
            $b->where("EXISTS (SELECT 1 FROM " . Database::connect()->prefixTable('product_mshops') . " pm WHERE pm.product_id = p.id AND pm.status = 'active' AND pm.mshop_id = " . (int) $mshopId . ')', null, false);
        }

        return $b->orderBy('p.id', 'DESC')->limit(200)->get()->getResultArray();
    }

    /**
     * Soft-deleted drafts, tenant- AND unit-scoped.
     *
     * AdminProductRepository::listDeleted() scopes by vendor only, so a store keeper
     * would see every unit's trash rather than their own. Rather than widen that shared
     * method — three panels call it — the manufacturer gets its own, applying the same
     * product_mshops EXISTS predicate list() uses.
     *
     * @return list<array<string,mixed>>
     */
    public function listTrashed(int $manufacturerId, ?int $mshopId = null, int $limit = 200): array
    {
        if ($manufacturerId <= 0) {
            return [];
        }

        $b = Database::connect()->table('products p')
            ->select('p.id, p.title, p.slug, p.deleted_at, c.name AS category, pv.sku')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left')
            ->where('p.vendor_id', $manufacturerId)
            ->where('p.deleted_at IS NOT NULL', null, false)
            // Only drafts are ever deletable, so the trash only ever holds drafts.
            ->where('p.status', 'draft');

        if ($mshopId !== null) {
            $b->where("EXISTS (SELECT 1 FROM " . Database::connect()->prefixTable('product_mshops') . " pm WHERE pm.product_id = p.id AND pm.status = 'active' AND pm.mshop_id = " . (int) $mshopId . ')', null, false);
        }

        return $b->orderBy('p.deleted_at', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /**
     * Soft-delete one of THIS manufacturer's drafts.
     *
     * The tenant predicate is in the UPDATE itself rather than in a prior lookup, so
     * there is no window between checking ownership and acting on it — and a product
     * belonging to someone else simply matches zero rows.
     */
    public function softDeleteDraft(int $id, int $manufacturerId, ?int $actorId = null): bool
    {
        $db = Database::connect();
        $db->table('products')
            ->where('id', $id)->where('vendor_id', $manufacturerId)
            ->where('status', 'draft')->where('deleted_at', null)
            ->update(['deleted_at' => date('Y-m-d H:i:s'), 'updated_by' => $actorId]);

        return $db->affectedRows() > 0;
    }

    /** Restore one of THIS manufacturer's trashed drafts. Same reasoning as above. */
    public function restoreDraft(int $id, int $manufacturerId, ?int $actorId = null): bool
    {
        $db = Database::connect();
        $db->table('products')
            ->where('id', $id)->where('vendor_id', $manufacturerId)
            ->where('status', 'draft')->where('deleted_at IS NOT NULL', null, false)
            ->update(['deleted_at' => null, 'updated_by' => $actorId]);

        return $db->affectedRows() > 0;
    }

    /** @return array<string,mixed>|null Null when the product is not this manufacturer's. */
    public function findById(int $id, int $manufacturerId): ?array
    {
        if ($id <= 0 || $manufacturerId <= 0) {
            return null;
        }

        return Database::connect()->table('products p')
            ->select('p.*, pv.id AS variant_id, pv.sku, pv.making_price, pv.base_price, pm.mshop_id')
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left')
            ->join('product_mshops pm', "pm.product_id = p.id AND pm.status = 'active'", 'left')
            ->where('p.id', $id)
            ->where('p.vendor_id', $manufacturerId)
            ->where('p.deleted_at', null)
            ->get()->getRowArray();
    }

    /**
     * Create a product plus its default variant.
     *
     * @param array<string,mixed> $d
     * @param int|null $mshopId The manufacturing unit this product is made at. Required:
     *        `product_mshops` is the join every reader uses to find a manufacturer's
     *        products — the unit-scoped list (`list()` above) and the monline B2B
     *        catalogue both go through it. A product created with no unit is silently
     *        unreachable from either, which is exactly the bug this parameter closes.
     * @return array{ok:bool,id:?int,error:string}
     */
    public function create(int $manufacturerId, array $d, ?int $actorId = null, ?int $mshopId = null): array
    {
        $title = trim((string) ($d['title'] ?? ''));
        if ($manufacturerId <= 0 || $title === '') {
            return ['ok' => false, 'id' => null, 'error' => 'Title is required.'];
        }
        if (($categoryId = (int) ($d['category_id'] ?? 0)) <= 0) {
            return ['ok' => false, 'id' => null, 'error' => 'Category is required.'];
        }
        if ($mshopId === null || $mshopId <= 0) {
            return ['ok' => false, 'id' => null, 'error' => 'Select a manufacturing unit for this product.'];
        }
        if (($err = ManufacturerPricing::validate($d)) !== '') {
            return ['ok' => false, 'id' => null, 'error' => $err];
        }

        $db = Database::connect();
        $db->transStart();

        $db->table('products')->insert([
            'uuid'          => $this->uuid(),
            'vendor_id'     => $manufacturerId,
            'category_id'   => $categoryId,
            'tax_class_id'  => (int) ($d['tax_class_id'] ?? 0) ?: null,
            'base_unit_id'  => (int) ($d['unit_id'] ?? 0) ?: null,
            'title'         => $title,
            'slug'          => $this->uniqueSlug($db, $title),
            'description'   => (string) ($d['description'] ?? ''),
            // Manufacturer products are B2B-only. is_online_enabled is left OFF and
            // visibility is 'vendor' so that even if a storefront query were ever added
            // without the party_type exclusion, these would still not surface.
            'is_online_enabled' => 0,
            'is_pos_enabled'    => 0,
            'visibility'        => 'vendor',
            'status'            => 'draft',
            'created_by'        => $actorId,
            'updated_by'        => $actorId,
        ]);
        $productId = (int) $db->insertID();

        $db->table('product_variants')->insert([
            'uuid'         => $this->uuid(),
            'product_id'   => $productId,
            'vendor_id'    => $manufacturerId,
            'sku'          => $this->uniqueSku($db, $title, $productId),
            'unit_id'      => (int) ($d['unit_id'] ?? 0) ?: null,
            'mrp'          => 0,           // manufacturers have no MRP concept
            'making_price' => $this->money($d, 'making_price'),
            'base_price'   => $this->money($d, 'base_price', 'selling_price'),
            'is_default'   => 1,
            'status'       => 'active',
            'created_by'   => $actorId,
            'updated_by'   => $actorId,
        ]);

        $now = date('Y-m-d H:i:s');
        $db->table('product_mshops')->insert([
            'product_id' => $productId,
            'mshop_id'   => $mshopId,
            'status'     => 'active',
            'listed_at'  => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $db->transComplete();

        return $db->transStatus()
            ? ['ok' => true, 'id' => $productId, 'error' => '']
            : ['ok' => false, 'id' => null, 'error' => 'Could not save the product.'];
    }

    /**
     * Update a product and/or its default variant's prices.
     *
     * @param array<string,mixed> $d
     * @param int|null $mshopId Reassign the product to a different manufacturing unit.
     *        Null means "leave the current assignment alone" — a section-only autosave
     *        (e.g. pricing) must not have the side effect of clearing which unit the
     *        product belongs to just because that call never mentioned one.
     * @return array{ok:bool,error:string}
     */
    public function update(int $id, int $manufacturerId, array $d, ?int $actorId = null, ?int $mshopId = null): array
    {
        $existing = $this->findById($id, $manufacturerId);
        if ($existing === null) {
            return ['ok' => false, 'error' => 'Product not found.'];
        }

        // Merge with what is already stored before validating: a form that posts only
        // the selling price must still be checked against the SAVED making price, or
        // the invariant can be walked past one field at a time.
        $merged = [
            'making_price' => $d['making_price'] ?? $existing['making_price'],
            'base_price'   => $d['base_price'] ?? ($d['selling_price'] ?? $existing['base_price']),
        ];
        if (($err = ManufacturerPricing::validate($merged)) !== '') {
            return ['ok' => false, 'error' => $err];
        }

        $db  = Database::connect();
        $set = ['updated_by' => $actorId];
        foreach (['title', 'description'] as $k) {
            if (isset($d[$k]) && trim((string) $d[$k]) !== '') {
                $set[$k] = trim((string) $d[$k]);
            }
        }
        if (($cat = (int) ($d['category_id'] ?? 0)) > 0) {
            $set['category_id'] = $cat;
        }

        $db->transStart();
        $db->table('products')->where('id', $id)->where('vendor_id', $manufacturerId)->update($set);
        $db->table('product_variants')
            ->where('product_id', $id)->where('vendor_id', $manufacturerId)->where('is_default', 1)
            ->update([
                'making_price' => $merged['making_price'],
                'base_price'   => $merged['base_price'],
                'updated_by'   => $actorId,
            ]);
        if ($mshopId !== null && $mshopId > 0) {
            // One unit per product on this flow, same simplification the vendor create/edit
            // flow uses for shops — reassignment replaces the row rather than accumulating
            // one per unit ever assigned.
            $db->table('product_mshops')->where('product_id', $id)->delete();
            $now = date('Y-m-d H:i:s');
            $db->table('product_mshops')->insert([
                'product_id' => $id,
                'mshop_id'   => $mshopId,
                'status'     => 'active',
                'listed_at'  => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $db->transComplete();

        return $db->transStatus() ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => 'Could not save the product.'];
    }

    /**
     * Section autosave. Unlike the vendor equivalent this VALIDATES before writing —
     * the pricing section is otherwise a hole straight through the invariant.
     *
     * @param array<string,mixed> $d
     * @return array{ok:bool,error:string}
     */
    public function autosave(int $id, int $manufacturerId, string $section, array $d, ?int $actorId = null): array
    {
        $existing = $this->findById($id, $manufacturerId);
        if ($existing === null) {
            return ['ok' => false, 'error' => 'Product not found.'];
        }
        if (! in_array($section, ['basics', 'pricing', 'content'], true)) {
            return ['ok' => false, 'error' => 'Unknown section.'];
        }

        if ($section === 'pricing') {
            return $this->update($id, $manufacturerId, $d, $actorId);
        }

        $db  = Database::connect();
        $set = ['updated_by' => $actorId];
        foreach (['title', 'description'] as $k) {
            if (isset($d[$k]) && trim((string) $d[$k]) !== '') {
                $set[$k] = trim((string) $d[$k]);
            }
        }
        if (count($set) > 1) {
            $db->table('products')->where('id', $id)->where('vendor_id', $manufacturerId)->update($set);
        }

        return ['ok' => true, 'error' => ''];
    }

    public function setStatus(int $id, int $manufacturerId, string $status, ?int $actorId = null): bool
    {
        if (! in_array($status, ['draft', 'submitted', 'published', 'unpublished'], true)) {
            return false;
        }
        if ($this->findById($id, $manufacturerId) === null) {
            return false;
        }

        Database::connect()->table('products')
            ->where('id', $id)->where('vendor_id', $manufacturerId)
            ->update(['status' => $status, 'updated_by' => $actorId]);

        return true;
    }

    /** Canonical 4dp string for a money column, or null when absent. */
    private function money(array $d, string ...$keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($d[$k]) && trim((string) $d[$k]) !== '') {
                return \App\Libraries\Money::of(trim((string) $d[$k]))->amount();
            }
        }

        return null;
    }

    private function uniqueSlug(object $db, string $title): string
    {
        $stem = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? '', '-') ?: 'product';
        $slug = $stem;
        for ($i = 2; $i < 500; $i++) {
            if ($db->table('products')->where('slug', $slug)->countAllResults() === 0) {
                return $slug;
            }
            $slug = $stem . '-' . $i;
        }

        return $stem . '-' . bin2hex(random_bytes(4));
    }

    /** product_variants.sku is GLOBALLY unique, not per tenant. */
    private function uniqueSku(object $db, string $title, int $productId): string
    {
        $stem = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $title) ?: 'MFG', 0, 8)) ?: 'MFG';
        $sku  = 'M-' . $stem . '-' . $productId;
        if ($db->table('product_variants')->where('sku', $sku)->countAllResults() === 0) {
            return $sku;
        }

        return $sku . '-' . bin2hex(random_bytes(3));
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0F) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
