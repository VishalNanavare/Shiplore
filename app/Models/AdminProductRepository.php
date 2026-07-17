<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * AdminProductRepository — admin product catalog: list across vendors, create a
 * product + its default variant (atomic), update, and the masters/category-gating
 * the form needs. Enforces the business rule that a product's category must be
 * allowed for its vendor's business type, and writes an audit_logs row on
 * create/update (traceability).
 *
 * P1 (product module): also persists the rich product header (type/condition/
 * visibility/codes/origin/GST-type/rules/availability/search/channels/shipping),
 * product_content (long-form), product_seo (+OG/Twitter/JSON-LD), tags, labels,
 * FAQs, custom non-variant specs, relations and external videos.
 *
 * @see docs/architecture/37-GST-ENGINE.md, 31-BUSINESS-TYPE-COMMISSION.md
 */
final class AdminProductRepository
{
    private const TYPES      = ['simple', 'variant', 'bundle', 'combo', 'digital', 'service', 'subscription', 'giftcard', 'downloadable', 'rental'];
    private const CONDITIONS = ['new', 'refurbished', 'used'];
    private const VISIBILITY = ['public', 'logged_in', 'group', 'vendor', 'hidden'];
    private const GST_TYPES  = ['inclusive', 'exclusive'];
    private const INV_MODES  = ['managed', 'unlimited'];
    private const PAYMENTS   = ['online', 'cod', 'both'];
    private const OOS        = ['hide', 'disable', 'backorder'];
    private const BOOSTS     = ['normal', 'high', 'featured'];
    private const SHIPPING   = ['free', 'paid', 'vendor', 'pickup'];
    private const RELATIONS  = ['related', 'upsell', 'cross_sell', 'fbt', 'alternative', 'similar', 'accessory'];

    /** Pure: URL slug from a title (caller adds a uniqueness suffix). */
    public static function slugify(string $title): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');

        return $slug === '' ? 'product' : $slug;
    }

    /** Pure: is the chosen category within the vendor's allowed set? */
    public static function isCategoryAllowed(array $allowedCatIds, int $catId): bool
    {
        return in_array($catId, array_map('intval', $allowedCatIds), true);
    }

    /** Pure: coerce a value to one of $allowed, else $default. */
    public static function enum(mixed $val, array $allowed, string $default): string
    {
        $v = is_string($val) ? $val : '';

        return in_array($v, $allowed, true) ? $v : $default;
    }

    /**
     * Admin product list with filters + pagination.
     * @param array<string,mixed> $f keys: vendor_id, status, q, category_id,
     *        product_type, limit (0 = no limit / all), offset
     * @return list<array<string,mixed>>
     */
    public function list(array $f = []): array
    {
        $b = Database::connect()->table('products p')
            ->select('p.id, p.title, p.slug, p.status, p.product_type, c.name AS category, v.display_name AS vendor, pv.sku, pv.base_price, pv.mrp')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->join('vendors v', 'v.id = p.vendor_id', 'left')
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left');
        $this->applyListFilters($b, $f);
        $b->orderBy('p.created_at', 'DESC');

        $limit = (int) ($f['limit'] ?? 50);
        if ($limit > 0) {
            $b->limit($limit, (int) ($f['offset'] ?? 0));
        }

        return $b->get()->getResultArray();
    }

    /** Total products matching the same filters (for pagination). @param array<string,mixed> $f */
    public function countList(array $f = []): int
    {
        $b = Database::connect()->table('products p')
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left');
        $this->applyListFilters($b, $f);

        return $b->countAllResults();
    }

    /** Shared WHERE builder for list()/countList(). @param array<string,mixed> $f */
    private function applyListFilters(object $b, array $f): void
    {
        $b->where('p.deleted_at', null);
        if (! empty($f['vendor_id'])) {
            $b->where('p.vendor_id', (int) $f['vendor_id']);
        }
        if (! empty($f['status'])) {
            $b->where('p.status', (string) $f['status']);
        }
        if (! empty($f['product_type'])) {
            $b->where('p.product_type', (string) $f['product_type']);
        }
        if (! empty($f['category_id'])) {
            $b->where('p.category_id', (int) $f['category_id']);
        }
        if (isset($f['q']) && $f['q'] !== '') {
            $q = (string) $f['q'];
            $b->groupStart()->like('p.title', $q)->orLike('pv.sku', $q)->orLike('p.slug', $q)->groupEnd();
        }
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('products p')
            ->select('p.*, pv.id AS variant_id, pv.sku, pv.barcode, pv.mrp, pv.base_price, pv.unit_id AS variant_unit_id')
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left')
            ->where('p.id', $id)->where('p.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** Categories allowed for the vendor's business type (the gating rule). @return list<array<string,mixed>> */
    public function allowedCategories(int $vendorId): array
    {
        $db = Database::connect();
        $v  = $db->table('vendors')->select('business_type_id')->where('id', $vendorId)->get()->getRowArray();
        $btype = $v['business_type_id'] ?? null;

        if ($btype === null) {
            return $db->table('categories')->select('id, name, slug')->where('status', 'active')->where('deleted_at', null)->orderBy('name')->get()->getResultArray();
        }

        return $db->table('business_type_category_map m')
            ->select('c.id, c.name, c.slug')
            ->join('categories c', 'c.id = m.category_id', 'left')
            ->where('m.business_type_id', $btype)->where('c.status', 'active')->where('c.deleted_at', null)
            ->orderBy('c.name')->get()->getResultArray();
    }

    /** @return array{tax:list,units:list,brands:list,labels:list,attributes:list,countries:list} */
    public function formMasters(): array
    {
        $db = Database::connect();

        return [
            'tax'        => $db->table('tax_classes')->select('id, code, name')->where('deleted_at', null)->get()->getResultArray(),
            'units'      => $db->table('units')->select('id, code, name')->where('deleted_at', null)->get()->getResultArray(),
            'brands'     => $db->table('brands')->select('id, name')->where('status', 'active')->where('deleted_at', null)->orderBy('name')->get()->getResultArray(),
            'labels'     => $db->table('product_labels')->select('id, code, name, color')->where('status', 'active')->where('deleted_at', null)->orderBy('sort_order')->get()->getResultArray(),
            'attributes' => $db->table('attributes')->select('id, code, name, type')->where('is_variant_defining', 0)->where('deleted_at', null)->orderBy('name')->get()->getResultArray(),
            'countries'  => config('Countries')->list,
        ];
    }

    public function skuExists(string $sku): bool
    {
        return (bool) Database::connect()->table('product_variants')->where('sku', $sku)->where('deleted_at', null)->countAllResults();
    }

    /** Soft-delete a DRAFT product only (a live/in-review product is never deletable). @return bool deleted */
    public function softDeleteDraft(int $id, ?int $actorId = null): bool
    {
        $db = Database::connect();
        $db->table('products')->where('id', $id)->where('status', 'draft')->where('deleted_at', null)
            ->update(['deleted_at' => date('Y-m-d H:i:s'), 'updated_by' => $actorId]);

        return $db->affectedRows() > 0;
    }

    /**
     * Soft-deleted drafts (the "trash"), optionally scoped to one vendor. Only
     * drafts are ever deletable, so the trash only ever contains drafts.
     * @return list<array<string,mixed>>
     */
    public function listDeleted(?int $vendorId = null, int $limit = 200): array
    {
        $b = Database::connect()->table('products p')
            ->select('p.id, p.title, p.slug, p.deleted_at, c.name AS category, v.display_name AS vendor, pv.sku')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->join('vendors v', 'v.id = p.vendor_id', 'left')
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left')
            ->where('p.deleted_at IS NOT NULL', null, false)->where('p.status', 'draft')
            ->orderBy('p.deleted_at', 'DESC')->limit($limit);
        if ($vendorId !== null) {
            $b->where('p.vendor_id', $vendorId);
        }

        return $b->get()->getResultArray();
    }

    /**
     * Invariant for going live: a published product MUST be listed in at least one
     * shop, else the location-scoped storefront (EXISTS product_shops) hides it.
     * If it has no active shop listing, list it in ALL its vendor's shops.
     */
    public function ensureListedInVendorShops(int $productId): void
    {
        $db  = Database::connect();
        $has = $db->table('product_shops')->where('product_id', $productId)->where('status', 'active')->countAllResults();
        if ($has > 0) {
            return;
        }
        $p = $db->table('products')->select('vendor_id')->where('id', $productId)->get()->getRowArray();
        if ($p === null) {
            return;
        }
        $shopIds = array_map(static fn ($s) => (int) $s['id'], service('productShopRepository')->shopsForVendor((int) $p['vendor_id']));
        if ($shopIds !== []) {
            service('productShopRepository')->sync($productId, $shopIds);
        }
    }

    /** Restore a soft-deleted draft back into the active list. @return bool restored */
    public function restoreDraft(int $id, ?int $actorId = null): bool
    {
        $db = Database::connect();
        $db->table('products')->where('id', $id)->where('status', 'draft')->where('deleted_at IS NOT NULL', null, false)
            ->update(['deleted_at' => null, 'updated_by' => $actorId]);

        return $db->affectedRows() > 0;
    }

    /** Find a soft-deleted (trashed) product row, ignoring the deleted_at filter. @return array<string,mixed>|null */
    public function findTrashed(int $id): ?array
    {
        $row = Database::connect()->table('products')
            ->select('id, vendor_id, title, status, deleted_at')
            ->where('id', $id)->where('deleted_at IS NOT NULL', null, false)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** Revert a product to draft (after an edit to a non-draft, non-published product). @return bool */
    public function revertToDraft(int $id, ?int $actorId = null): bool
    {
        return (bool) Database::connect()->table('products')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => 'draft', 'approval_id' => null, 'updated_by' => $actorId]);
    }

    /** Soft-delete a product's generated (non-default) variants + their attribute values. */
    private function clearGeneratedVariants(int $productId, $db): void
    {
        $ids = array_column(
            $db->table('product_variants')->select('id')
                ->where('product_id', $productId)->where('is_default', 0)->where('deleted_at', null)
                ->get()->getResultArray(),
            'id'
        );
        if ($ids === []) {
            return;
        }
        $db->table('variant_attribute_values')->whereIn('variant_id', $ids)->delete();
        $db->table('product_variants')->whereIn('id', $ids)->update(['deleted_at' => date('Y-m-d H:i:s')]);
    }

    /** The product's default variant id — price/stock for a simple product live here. */
    public function defaultVariantId(int $productId): ?int
    {
        $r = Database::connect()->table('product_variants')->select('id')
            ->where('product_id', $productId)->where('is_default', 1)->where('deleted_at', null)
            ->get()->getRowArray();

        return $r ? (int) $r['id'] : null;
    }

    /**
     * Default-variant on-hand per shop, for the form's "Shops & Stock" panel.
     * @param list<int> $shopIds @return array<int,float> shopId => on_hand
     */
    public function shopStockLevels(int $productId, array $shopIds): array
    {
        $vid = $this->defaultVariantId($productId);
        if ($vid === null || $shopIds === []) {
            return [];
        }
        $inv = service('inventoryService');
        $out = [];
        foreach ($shopIds as $sid) {
            $out[(int) $sid] = (float) ($inv->levels($vid, (int) $sid)['on_hand'] ?? 0);
        }

        return $out;
    }

    /**
     * Map rich input → `products` header columns (enum-guarded, additive over
     * the base title/category/brand/tax/unit/description already handled).
     * @param array<string,mixed> $d @return array<string,mixed>
     */
    private function headerColumns(array $d): array
    {
        $int = static fn (string $k): ?int => isset($d[$k]) && $d[$k] !== '' ? (int) $d[$k] : null;
        $str = static fn (string $k, int $max = 191): ?string => isset($d[$k]) && trim((string) $d[$k]) !== '' ? mb_substr(trim((string) $d[$k]), 0, $max) : null;

        return [
            'print_short_name'      => $str('print_short_name', 20),
            'product_type'          => self::enum($d['product_type'] ?? '', self::TYPES, 'simple'),
            'product_condition'     => self::enum($d['product_condition'] ?? '', self::CONDITIONS, 'new'),
            'visibility'            => self::enum($d['visibility'] ?? '', self::VISIBILITY, 'public'),
            'manufacturer'          => $str('manufacturer'),
            'country_of_origin'     => $str('country_of_origin', 80),
            'made_in'               => $str('made_in', 80),
            'vendor_product_code'   => $str('vendor_product_code', 64),
            'internal_product_code' => $str('internal_product_code', 64),
            'gst_type'              => self::enum($d['gst_type'] ?? '', self::GST_TYPES, 'inclusive'),
            'inventory_mode'        => self::enum($d['inventory_mode'] ?? '', self::INV_MODES, 'managed'),
            'min_purchase_qty'      => $d['min_purchase_qty'] ?? null ?: null,
            'max_purchase_qty'      => $d['max_purchase_qty'] ?? null ?: null,
            'qty_step'              => ($d['qty_step'] ?? '') !== '' ? $d['qty_step'] : 1,
            'daily_purchase_limit'  => $int('daily_purchase_limit'),
            'monthly_purchase_limit' => $int('monthly_purchase_limit'),
            'payment_restriction'   => self::enum($d['payment_restriction'] ?? '', self::PAYMENTS, 'both'),
            'refund_allowed'        => ! empty($d['refund_allowed']) ? 1 : 0,
            'refund_window_days'    => $int('refund_window_days'),
            'return_window_days'    => $int('return_window_days'),
            'replacement_window_days' => $int('replacement_window_days'),
            'available_from'        => $str('available_from', 10),
            'available_to'          => $str('available_to', 10),
            'preorder_enabled'      => ! empty($d['preorder_enabled']) ? 1 : 0,
            'backorder_enabled'     => ! empty($d['backorder_enabled']) ? 1 : 0,
            'out_of_stock_handling' => self::enum($d['out_of_stock_handling'] ?? '', self::OOS, 'disable'),
            'search_boost'          => self::enum($d['search_boost'] ?? '', self::BOOSTS, 'normal'),
            'is_app_enabled'        => ! empty($d['is_app_enabled']) ? 1 : 0,
            'is_marketplace_enabled' => ! empty($d['is_marketplace_enabled']) ? 1 : 0,
            'is_pos_enabled'        => ! empty($d['is_pos_enabled']) ? 1 : 0,
            'is_online_enabled'     => ! empty($d['is_online_enabled']) ? 1 : 0,
            'shipping_type'         => self::enum($d['shipping_type'] ?? '', self::SHIPPING, 'paid'),
            'ships_local'           => ! empty($d['ships_local']) ? 1 : 0,
            'ships_national'        => ! empty($d['ships_national']) ? 1 : 0,
            'ships_international'    => ! empty($d['ships_international']) ? 1 : 0,
        ];
    }

    /** @param array<string,mixed> $d @return int|null new product id */
    public function createWithVariant(array $d, ?int $actorId = null): ?int
    {
        $db = Database::connect();
        $db->transBegin();
        try {
            $uuid = $this->uuid();
            $slug = ($d['slug'] ?? '') !== '' ? self::slugify((string) $d['slug']) : self::slugify((string) $d['title']);
            $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);

            $row = array_merge($this->headerColumns($d), [
                'uuid' => $uuid, 'vendor_id' => (int) $d['vendor_id'], 'category_id' => (int) $d['category_id'],
                'brand_id' => ($d['brand_id'] ?? null) ?: null, 'tax_class_id' => (int) $d['tax_class_id'],
                'hsn_sac_id' => ($d['hsn_sac_id'] ?? null) ?: null, 'base_unit_id' => (int) $d['unit_id'],
                'title' => mb_substr((string) $d['title'], 0, 191), 'slug' => $slug,
                'description' => $d['description'] ?? null, 'status' => 'draft', 'created_by' => $actorId,
            ]);
            $db->table('products')->insert($row);
            $productId = (int) $db->insertID();

            // SKU/price are set per item on the Variants page; auto-assign a unique
            // base SKU here when none was supplied so the default variant is valid.
            $sku = trim((string) ($d['sku'] ?? ''));
            while ($sku === '' || $db->table('product_variants')->where('sku', $sku)->countAllResults() > 0) {
                $sku = 'SKU-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
            }
            $db->table('product_variants')->insert([
                'uuid' => $this->uuid(), 'product_id' => $productId, 'vendor_id' => (int) $d['vendor_id'],
                'sku' => mb_substr($sku, 0, 64), 'barcode' => ($d['barcode'] ?? null) ?: null,
                'unit_id' => (int) $d['unit_id'], 'mrp' => $d['mrp'] ?? 0, 'base_price' => $d['base_price'] ?? 0,
                'is_default' => 1, 'status' => 'active', 'created_by' => $actorId,
            ]);

            $this->saveContent($productId, $d, $actorId, $db);
            $this->saveSeo($productId, $d, (string) $d['title'], $actorId, $db);

            $db->transComplete();
            if (! $db->transStatus()) {
                return null;
            }
            // satellite saves outside the variant txn (own error handling)
            $this->saveTags($productId, (int) $d['vendor_id'], $d['tags'] ?? '', $actorId);
            $this->saveLabels($productId, (array) ($d['labels'] ?? []));
            $this->saveFaqs($productId, (array) ($d['faq_q'] ?? []), (array) ($d['faq_a'] ?? []), $actorId);
            $this->saveCustomAttributes($productId, (array) ($d['cattr'] ?? []), $actorId);
            $this->saveRelations($productId, (array) ($d['relations'] ?? []), $actorId);
            $this->saveVideos($productId, (array) ($d['video_url'] ?? []), (array) ($d['video_provider'] ?? []), $actorId);
            $this->applyShopAssignment($productId, $d);

            $this->audit('product.create', 'product', $productId, $uuid, $actorId, null, $row);

            return $productId;
        } catch (Throwable) {
            $db->transRollback();

            return null;
        }
    }

    /** @param array<string,mixed> $d */
    public function update(int $id, array $d, ?int $actorId = null): bool
    {
        $db     = Database::connect();
        $before = $this->findById($id);

        $row = array_merge($this->headerColumns($d), [
            'category_id' => (int) $d['category_id'], 'brand_id' => ($d['brand_id'] ?? null) ?: null,
            'tax_class_id' => (int) $d['tax_class_id'], 'hsn_sac_id' => ($d['hsn_sac_id'] ?? null) ?: null,
            'title' => mb_substr((string) $d['title'], 0, 191), 'description' => $d['description'] ?? null,
            'updated_by' => $actorId,
        ]);
        $ok = $db->table('products')->where('id', $id)->where('deleted_at', null)->update($row);

        $db->table('product_variants')->where('product_id', $id)->where('is_default', 1)->update([
            'mrp' => $d['mrp'] ?? 0, 'base_price' => $d['base_price'] ?? 0, 'updated_by' => $actorId,
        ]);

        // Item 4: leaving the 'variant' type discards the generated variants (the
        // user is warned + confirms in the UI first); keep only the default variant.
        // Also: changing CATEGORY on a variant product invalidates its variant-
        // defining attributes, so the generated variants are cleared the same way.
        $newType    = self::enum($d['product_type'] ?? '', self::TYPES, 'simple');
        $wasVariant = ($before['product_type'] ?? '') === 'variant';
        $catChanged = (int) ($before['category_id'] ?? 0) !== (int) $d['category_id'];
        if ($wasVariant && ($newType !== 'variant' || $catChanged)) {
            $this->clearGeneratedVariants($id, $db);
        }

        $this->saveContent($id, $d, $actorId);
        $this->saveSeo($id, $d, (string) $d['title'], $actorId);
        $this->saveTags($id, (int) ($before['vendor_id'] ?? 0), $d['tags'] ?? '', $actorId);
        $this->saveLabels($id, (array) ($d['labels'] ?? []));
        $this->saveFaqs($id, (array) ($d['faq_q'] ?? []), (array) ($d['faq_a'] ?? []), $actorId);
        $this->saveCustomAttributes($id, (array) ($d['cattr'] ?? []), $actorId);
        $this->saveRelations($id, (array) ($d['relations'] ?? []), $actorId);
        $this->saveVideos($id, (array) ($d['video_url'] ?? []), (array) ($d['video_provider'] ?? []), $actorId);

        $this->applyShopAssignment($id, $d);

        $this->audit('product.update', 'product', $id, (string) ($d['uuid'] ?? ''), $actorId, $before, $row);

        return $ok;
    }

    /**
     * S2 — list the product in the chosen shop(s). Runs only when the form actually
     * submitted a shop list (`shop_ids` key present), so the draft/autosave path
     * that omits it never wipes assignments. Stock/price/SKU/barcode are set per
     * item on the Variants page, not here.
     *
     * @param array<string,mixed> $d  shop_ids: list<int>
     */
    private function applyShopAssignment(int $productId, array $d): void
    {
        if (! array_key_exists('shop_ids', $d)) {
            return;
        }
        $shopIds = array_values(array_unique(array_filter(array_map('intval', (array) $d['shop_ids']))));
        service('productShopRepository')->sync($productId, $shopIds);
    }

    /**
     * Deep-clone a product into a new draft: header + content + SEO + all
     * variants (fresh SKUs) + their attribute values, tags, labels, custom specs,
     * FAQs, videos and relations. Barcodes are NOT copied (values are globally
     * unique). When $targetVendorId is given (admin cross-vendor copy), the new
     * product and its variants are re-owned by that vendor. @return int|null
     */
    public function duplicate(int $sourceId, ?int $actorId = null, ?int $targetVendorId = null): ?int
    {
        $db = Database::connect();
        $src = $db->table('products')->where('id', $sourceId)->where('deleted_at', null)->get()->getRowArray();
        if ($src === null) {
            return null;
        }
        $vendorId = $targetVendorId !== null && $targetVendorId > 0 ? $targetVendorId : (int) $src['vendor_id'];
        $db->transBegin();
        try {
            $row = $src;
            unset($row['id'], $row['created_at'], $row['updated_at'], $row['deleted_at']);
            $row['uuid'] = $this->uuid();
            $row['vendor_id'] = $vendorId;
            $row['title'] = mb_substr((string) $src['title'] . ' (Copy)', 0, 191);
            $row['slug'] = self::slugify((string) $src['title'] . '-copy') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $row['status'] = 'draft';
            $row['approval_id'] = null;
            $row['autosaved_at'] = null;
            $row['created_by'] = $actorId;
            $row['updated_by'] = $actorId;
            $db->table('products')->insert($row);
            $newId = (int) $db->insertID();

            foreach (['product_content', 'product_seo'] as $t) {
                $one = $db->table($t)->where('product_id', $sourceId)->get()->getRowArray();
                if ($one !== null) {
                    unset($one['id'], $one['created_at'], $one['updated_at']);
                    $one['product_id'] = $newId;
                    $db->table($t)->insert($one);
                }
            }

            // variants + their attribute values (map old->new ids)
            $map = [];
            foreach ($db->table('product_variants')->where('product_id', $sourceId)->where('deleted_at', null)->get()->getResultArray() as $v) {
                $oldVid = (int) $v['id'];
                unset($v['id'], $v['created_at'], $v['updated_at'], $v['deleted_at']);
                $v['uuid'] = $this->uuid();
                $v['product_id'] = $newId;
                $v['vendor_id'] = $vendorId;
                $v['sku'] = mb_substr($v['sku'] . '-C' . substr(bin2hex(random_bytes(2)), 0, 4), 0, 64);
                $v['created_by'] = $actorId;
                $db->table('product_variants')->insert($v);
                $map[$oldVid] = (int) $db->insertID();
            }
            if ($map !== []) {
                foreach ($db->table('variant_attribute_values')->whereIn('variant_id', array_keys($map))->get()->getResultArray() as $vav) {
                    $db->table('variant_attribute_values')->insert(['variant_id' => $map[(int) $vav['variant_id']], 'attribute_id' => $vav['attribute_id'], 'attribute_value_id' => $vav['attribute_value_id']]);
                }
            }

            // simple child maps / lists keyed by product_id
            foreach (['product_tag_map' => ['product_id', 'tag_id'], 'product_label_map' => ['product_id', 'label_id']] as $t => $cols) {
                foreach ($db->table($t)->where('product_id', $sourceId)->get()->getResultArray() as $r) {
                    $db->table($t)->ignore(true)->insert([$cols[0] => $newId, $cols[1] => $r[$cols[1]]]);
                }
            }
            foreach (['product_attribute_values', 'product_faqs', 'product_videos', 'product_relations'] as $t) {
                $hasUuid = in_array('uuid', $db->getFieldNames($t), true);
                foreach ($db->table($t)->where('product_id', $sourceId)->get()->getResultArray() as $r) {
                    unset($r['id'], $r['uuid'], $r['created_at'], $r['updated_at'], $r['deleted_at']);
                    $r['product_id'] = $newId;
                    if ($hasUuid) {
                        $r['uuid'] = $this->uuid();
                    }
                    $db->table($t)->insert($r);
                }
            }

            // S2 — carry shop assignment so the copy is visible in POS like the source.
            // Cross-vendor copy can't reuse the source's shops, so list it in the
            // target vendor's first shop instead.
            $copyShops = $vendorId === (int) $src['vendor_id']
                ? service('productShopRepository')->forProduct($sourceId)
                : array_map(static fn ($s) => (int) $s['id'], array_slice(service('productShopRepository')->shopsForVendor($vendorId), 0, 1));
            // Fallback: the source had no shop listing → list the copy in ALL the
            // vendor's shops so it isn't born invisible to the storefront.
            if ($copyShops === []) {
                $copyShops = array_map(static fn ($s) => (int) $s['id'], service('productShopRepository')->shopsForVendor($vendorId));
            }
            if ($copyShops !== []) {
                service('productShopRepository')->sync($newId, $copyShops);
            }

            $db->transComplete();
            if (! $db->transStatus()) {
                return null;
            }
            $this->audit('product.duplicate', 'product', $newId, $row['uuid'], $actorId, ['source' => $sourceId], null);

            return $newId;
        } catch (Throwable) {
            $db->transRollback();

            return null;
        }
    }

    // ---- draft + autosave (RB3) ---------------------------------------------

    /**
     * Create a minimal draft (status='draft') from just vendor + category + name;
     * tax/unit fall back to the category default or the first master. The
     * redesigned form's first autosave returns this id, then PATCHes sections.
     * @param array<string,mixed> $d @return int|null
     */
    public function draftCreate(array $d, ?int $actorId = null): ?int
    {
        $db   = Database::connect();
        $tax  = (int) ($d['tax_class_id'] ?? 0) ?: (int) ($db->table('tax_classes')->select('id')->where('deleted_at', null)->orderBy('id')->get()->getRowArray()['id'] ?? 0);
        $unit = (int) ($d['unit_id'] ?? 0) ?: (int) ($db->table('units')->select('id')->where('deleted_at', null)->orderBy('id')->get()->getRowArray()['id'] ?? 0);

        return $this->createWithVariant([
            'vendor_id' => (int) $d['vendor_id'], 'category_id' => (int) $d['category_id'],
            'tax_class_id' => $tax, 'unit_id' => $unit,
            'title' => trim((string) ($d['title'] ?? '')) !== '' ? $d['title'] : 'Untitled draft',
            'sku' => 'DRAFT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'mrp' => 0, 'base_price' => 0, 'is_online_enabled' => 1, 'is_pos_enabled' => 1, 'is_app_enabled' => 1,
        ], $actorId);
    }

    /**
     * Autosave one section of a draft (only touches that section's fields, so it
     * never clobbers others), then stamps autosaved_at.
     * @param array<string,mixed> $d
     */
    public function autosave(int $id, string $section, array $d, ?int $actorId = null): bool
    {
        $db = Database::connect();
        switch ($section) {
            case 'basics':
                $db->table('products')->where('id', $id)->update($this->basicsColumns($d) + ['updated_by' => $actorId]);
                break;
            case 'pricing':
                if (isset($d['mrp']) || isset($d['base_price'])) {
                    $db->table('product_variants')->where('product_id', $id)->where('is_default', 1)->update([
                        'mrp' => $d['mrp'] ?? 0, 'base_price' => $d['base_price'] ?? 0, 'updated_by' => $actorId,
                    ]);
                }
                $db->table('products')->where('id', $id)->update($this->pricingColumns($d) + ['updated_by' => $actorId]);
                break;
            case 'rules':
                $db->table('products')->where('id', $id)->update($this->rulesColumns($d) + ['updated_by' => $actorId]);
                break;
            case 'content':
                $this->saveContent($id, $d, $actorId);
                break;
            case 'seo':
                $this->saveSeo($id, $d, (string) ($d['title'] ?? ''), $actorId);
                break;
            case 'tags':
                $this->saveTags($id, (int) ($d['vendor_id'] ?? 0), $d['tags'] ?? '', $actorId);
                break;
            case 'labels':
                $this->saveLabels($id, (array) ($d['labels'] ?? []));
                break;
            default:
                return false;
        }
        $db->table('products')->where('id', $id)->update(['autosaved_at' => date('Y-m-d H:i:s')]);

        return true;
    }

    /** @param array<string,mixed> $d @return array<string,mixed> only the provided basics fields */
    private function basicsColumns(array $d): array
    {
        $out = [];
        foreach (['title' => 191, 'print_short_name' => 20, 'manufacturer' => 191, 'country_of_origin' => 80, 'made_in' => 80, 'vendor_product_code' => 64, 'internal_product_code' => 64] as $k => $max) {
            if (isset($d[$k])) {
                $out[$k] = mb_substr(trim((string) $d[$k]), 0, $max) ?: null;
            }
        }
        if (isset($d['brand_id'])) {
            $out['brand_id'] = ((int) $d['brand_id']) ?: null;
        }
        if (isset($d['product_type'])) {
            $out['product_type'] = self::enum((string) $d['product_type'], self::TYPES, 'simple');
        }
        if (isset($d['product_condition'])) {
            $out['product_condition'] = self::enum((string) $d['product_condition'], self::CONDITIONS, 'new');
        }
        if (isset($d['visibility'])) {
            $out['visibility'] = self::enum((string) $d['visibility'], self::VISIBILITY, 'public');
        }

        return $out;
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function pricingColumns(array $d): array
    {
        $out = [];
        if (isset($d['gst_type'])) {
            $out['gst_type'] = self::enum((string) $d['gst_type'], self::GST_TYPES, 'inclusive');
        }
        if (isset($d['inventory_mode'])) {
            $out['inventory_mode'] = self::enum((string) $d['inventory_mode'], self::INV_MODES, 'managed');
        }
        if (isset($d['tax_class_id']) && (int) $d['tax_class_id'] > 0) {
            $out['tax_class_id'] = (int) $d['tax_class_id'];
        }
        if (isset($d['hsn_sac_id'])) {
            $out['hsn_sac_id'] = ((int) $d['hsn_sac_id']) ?: null;
        }

        return $out;
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function rulesColumns(array $d): array
    {
        $out = [];
        foreach (['min_purchase_qty', 'max_purchase_qty', 'qty_step', 'daily_purchase_limit', 'monthly_purchase_limit', 'refund_window_days', 'return_window_days', 'replacement_window_days', 'available_from', 'available_to'] as $k) {
            if (isset($d[$k])) {
                $out[$k] = ($d[$k] === '' ? null : $d[$k]);
            }
        }
        if (isset($d['payment_restriction'])) {
            $out['payment_restriction'] = self::enum((string) $d['payment_restriction'], self::PAYMENTS, 'both');
        }
        if (isset($d['out_of_stock_handling'])) {
            $out['out_of_stock_handling'] = self::enum((string) $d['out_of_stock_handling'], self::OOS, 'disable');
        }
        foreach (['refund_allowed', 'preorder_enabled', 'backorder_enabled'] as $k) {
            if (isset($d[$k])) {
                $out[$k] = ! empty($d[$k]) ? 1 : 0;
            }
        }

        return $out;
    }

    // ---- satellite writers --------------------------------------------------

    /** @param array<string,mixed> $d */
    private function saveContent(int $productId, array $d, ?int $actorId, ?object $db = null): void
    {
        $db ??= Database::connect();
        $highlights = array_values(array_filter(array_map('trim', (array) ($d['highlights'] ?? []))));
        $payload = [
            'short_description'    => ($d['short_description'] ?? '') !== '' ? mb_substr((string) $d['short_description'], 0, 500) : null,
            'full_description'     => $d['full_description'] ?? ($d['description'] ?? null),
            'additional_info'      => $d['additional_info'] ?? null,
            'ingredients'          => $d['ingredients'] ?? null,
            'usage_instructions'   => $d['usage_instructions'] ?? null,
            'safety_instructions'  => $d['safety_instructions'] ?? null,
            'storage_instructions' => $d['storage_instructions'] ?? null,
            'highlights_json'      => $highlights === [] ? null : json_encode($highlights),
            'updated_by'           => $actorId,
        ];
        $exists = $db->table('product_content')->where('product_id', $productId)->countAllResults();
        if ($exists) {
            $db->table('product_content')->where('product_id', $productId)->update($payload);
        } else {
            $db->table('product_content')->insert($payload + ['product_id' => $productId, 'created_by' => $actorId]);
        }
    }

    /** @param array<string,mixed> $d */
    private function saveSeo(int $productId, array $d, string $title, ?int $actorId, ?object $db = null): void
    {
        $db ??= Database::connect();
        $payload = [
            'meta_title'       => ($d['meta_title'] ?? '') !== '' ? mb_substr((string) $d['meta_title'], 0, 191) : mb_substr($title, 0, 191),
            'meta_description' => ($d['meta_description'] ?? '') !== '' ? mb_substr((string) $d['meta_description'], 0, 300) : null,
            'meta_keywords'    => ($d['meta_keywords'] ?? '') !== '' ? mb_substr((string) $d['meta_keywords'], 0, 255) : null,
            'canonical_url'    => ($d['canonical_url'] ?? '') !== '' ? mb_substr((string) $d['canonical_url'], 0, 255) : null,
            'updated_by'       => $actorId,
        ];
        $exists = $db->table('product_seo')->where('product_id', $productId)->countAllResults();
        if ($exists) {
            $db->table('product_seo')->where('product_id', $productId)->update($payload);
        } else {
            $db->table('product_seo')->insert($payload + ['product_id' => $productId, 'created_by' => $actorId]);
        }
    }

    /** Tags from a comma-separated string or array; find-or-create then remap. */
    private function saveTags(int $productId, int $vendorId, mixed $tags, ?int $actorId): void
    {
        $names = is_array($tags) ? $tags : explode(',', (string) $tags);
        $names = array_values(array_unique(array_filter(array_map(static fn ($t) => mb_substr(trim((string) $t), 0, 80), $names))));
        $db = Database::connect();
        $db->table('product_tag_map')->where('product_id', $productId)->delete();
        foreach ($names as $name) {
            $slug = self::slugify($name);
            $tag  = $db->table('product_tags')->select('id')->where('slug', $slug)->where('deleted_at', null)->get()->getRowArray();
            if ($tag === null) {
                $db->table('product_tags')->insert(['uuid' => $this->uuid(), 'vendor_id' => $vendorId ?: null, 'name' => $name, 'slug' => $slug, 'created_by' => $actorId]);
                $tagId = (int) $db->insertID();
            } else {
                $tagId = (int) $tag['id'];
            }
            $db->table('product_tag_map')->ignore(true)->insert(['product_id' => $productId, 'tag_id' => $tagId]);
        }
    }

    /** @param list<int|string> $labelIds */
    private function saveLabels(int $productId, array $labelIds): void
    {
        $db = Database::connect();
        $db->table('product_label_map')->where('product_id', $productId)->delete();
        foreach (array_unique(array_map('intval', $labelIds)) as $lid) {
            if ($lid > 0) {
                $db->table('product_label_map')->ignore(true)->insert(['product_id' => $productId, 'label_id' => $lid]);
            }
        }
    }

    /** @param list<string> $q @param list<string> $a */
    private function saveFaqs(int $productId, array $q, array $a, ?int $actorId): void
    {
        $db = Database::connect();
        $db->table('product_faqs')->where('product_id', $productId)->delete();
        foreach (array_values($q) as $i => $question) {
            $question = trim((string) $question);
            $answer   = trim((string) ($a[$i] ?? ''));
            if ($question !== '' && $answer !== '') {
                $db->table('product_faqs')->insert(['uuid' => $this->uuid(), 'product_id' => $productId, 'question' => mb_substr($question, 0, 500), 'answer' => $answer, 'sort_order' => $i, 'created_by' => $actorId]);
            }
        }
    }

    /** @param array<int|string,mixed> $map attribute_id => value (text or value_id) */
    private function saveCustomAttributes(int $productId, array $map, ?int $actorId): void
    {
        $db = Database::connect();
        $db->table('product_attribute_values')->where('product_id', $productId)->delete();
        foreach ($map as $attrId => $val) {
            $attrId = (int) $attrId;
            $val    = trim((string) $val);
            if ($attrId > 0 && $val !== '') {
                $db->table('product_attribute_values')->insert(['product_id' => $productId, 'attribute_id' => $attrId, 'value_text' => mb_substr($val, 0, 500), 'created_by' => $actorId]);
            }
        }
    }

    /** @param array<string,mixed> $map relation_type => comma-ids or array */
    private function saveRelations(int $productId, array $map, ?int $actorId): void
    {
        $db = Database::connect();
        $db->table('product_relations')->where('product_id', $productId)->delete();
        foreach ($map as $type => $ids) {
            $type = self::enum((string) $type, self::RELATIONS, '');
            if ($type === '') {
                continue;
            }
            $ids = is_array($ids) ? $ids : explode(',', (string) $ids);
            foreach (array_unique(array_map('intval', $ids)) as $i => $rid) {
                if ($rid > 0 && $rid !== $productId) {
                    $db->table('product_relations')->ignore(true)->insert(['product_id' => $productId, 'related_product_id' => $rid, 'relation_type' => $type, 'sort_order' => $i, 'created_by' => $actorId]);
                }
            }
        }
    }

    /** @param list<string> $urls @param list<string> $providers */
    private function saveVideos(int $productId, array $urls, array $providers, ?int $actorId): void
    {
        $db = Database::connect();
        $db->table('product_videos')->where('product_id', $productId)->delete();
        foreach (array_values($urls) as $i => $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                $db->table('product_videos')->insert([
                    'uuid' => $this->uuid(), 'product_id' => $productId,
                    'provider' => self::enum($providers[$i] ?? 'youtube', ['youtube', 'vimeo', 'direct'], 'youtube'),
                    'url' => mb_substr($url, 0, 500), 'sort_order' => $i, 'created_by' => $actorId,
                ]);
            }
        }
    }

    // ---- edit-form loaders --------------------------------------------------

    /** @return array<string,mixed> */
    public function content(int $productId): array
    {
        $row = Database::connect()->table('product_content')->where('product_id', $productId)->get()->getRowArray() ?: [];
        $row['highlights'] = isset($row['highlights_json']) ? (json_decode((string) $row['highlights_json'], true) ?: []) : [];

        return $row;
    }

    /** @return array<string,mixed> */
    public function seo(int $productId): array
    {
        return Database::connect()->table('product_seo')->where('product_id', $productId)->get()->getRowArray() ?: [];
    }

    public function tagsCsv(int $productId): string
    {
        $rows = Database::connect()->table('product_tag_map m')->select('t.name')
            ->join('product_tags t', 't.id = m.tag_id')->where('m.product_id', $productId)->get()->getResultArray();

        return implode(', ', array_column($rows, 'name'));
    }

    /** @return list<int> */
    public function labelIds(int $productId): array
    {
        return array_map('intval', array_column(Database::connect()->table('product_label_map')->select('label_id')->where('product_id', $productId)->get()->getResultArray(), 'label_id'));
    }

    /** @return list<array<string,mixed>> */
    public function faqs(int $productId): array
    {
        return Database::connect()->table('product_faqs')->where('product_id', $productId)->where('deleted_at', null)->orderBy('sort_order')->get()->getResultArray();
    }

    /** @return array<int,string> attribute_id => value_text */
    public function customAttributes(int $productId): array
    {
        $out = [];
        foreach (Database::connect()->table('product_attribute_values')->where('product_id', $productId)->get()->getResultArray() as $r) {
            $out[(int) $r['attribute_id']] = (string) ($r['value_text'] ?? '');
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function videos(int $productId): array
    {
        return Database::connect()->table('product_videos')->where('product_id', $productId)->where('deleted_at', null)->orderBy('sort_order')->get()->getResultArray();
    }

    /** @return array<string,list<int>> relation_type => related ids */
    public function relations(int $productId): array
    {
        $out = [];
        foreach (Database::connect()->table('product_relations')->where('product_id', $productId)->orderBy('sort_order')->get()->getResultArray() as $r) {
            $out[(string) $r['relation_type']][] = (int) $r['related_product_id'];
        }

        return $out;
    }

    private function audit(string $action, string $entityType, int $entityId, string $entityUuid, ?int $actorId, ?array $before = null, ?array $after = null): void
    {
        try {
            $payload = $action . '|' . $entityType . '|' . $entityId . '|' . date('c');
            $cols = [
                'uuid' => $this->uuid(), 'actor_user_id' => $actorId, 'action' => $action,
                'entity_type' => $entityType, 'entity_id' => $entityId, 'entity_uuid' => $entityUuid ?: null,
                'ip' => service('request')->getIPAddress(), 'result' => 'success', 'row_hash' => hash('sha256', $payload),
            ];
            // before/after snapshots if the (extended) audit_logs carries them
            $has = Database::connect()->getFieldNames('audit_logs');
            if (in_array('before_json', $has, true)) {
                $cols['before_json'] = $before ? json_encode($before) : null;
            }
            if (in_array('after_json', $has, true)) {
                $cols['after_json'] = $after ? json_encode($after) : null;
            }
            Database::connect()->table('audit_logs')->insert($cols);
        } catch (Throwable) {
        }
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
