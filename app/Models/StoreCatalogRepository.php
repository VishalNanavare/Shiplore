<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Store\LocationService;
use Config\Database;

/**
 * StoreCatalogRepository — public catalog reads for the storefront: categories,
 * published products (browse / filter / sort / search) and product detail with
 * its default variant, vendor and published reviews.
 */
final class StoreCatalogRepository
{
    /** @return list<array<string,mixed>> Active top-level categories. */
    public function categories(int $limit = 12): array
    {
        return Database::connect()->table('categories')
            ->select('id, name, slug, parent_id, level')
            ->where('status', 'active')->where('deleted_at', null)
            ->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')->limit($limit)
            ->get()->getResultArray();
    }

    /**
     * Full active category tree with a whole-subtree product count (`cnt`) on each
     * node, so the app can hide categories with no products. Hotel products are
     * excluded from the count (so hotel categories surface as cnt=0 → hidden).
     * @param array{shop_ids?:list<int>} $opts location/shop scope (optional)
     * @return list<array{id:int,name:string,slug:string,parent_id:?int,level:int,cnt:int}>
     */
    public function categoryTreeWithCounts(array $opts = []): array
    {
        // Global / GPS-bucket browse → the cached tree (computed off-request by the
        // facets:refresh worker). A single-shop scope is computed live & exact so a
        // shop page/rail never inherits the global tree's categories.
        return $this->_cacheableScope($opts)
            ? service('facetCache')->tree($opts)
            : $this->computeTree($opts);
    }

    /**
     * Cacheable = the global or GPS-bucket browse (high volume, shared cache). A
     * single-shop scope (shop_ids present without a bucket) is NOT cacheable — it is
     * computed live & exact so shop-scoped category/count/facet reads reflect that
     * shop's real inventory instead of falling back to the global tree.
     *
     * @param array<string,mixed> $opts
     */
    private function _cacheableScope(array $opts): bool
    {
        return empty($opts['shop_ids']) || ! empty($opts['bucket']);
    }

    /**
     * Raw category tree with whole-subtree product counts (UNCACHED). Invoked by the
     * background worker / FacetCache only — scans the catalog once per GPS bucket.
     * @param array{shop_ids?:list<int>} $opts location/shop scope (optional)
     * @return list<array{id:int,name:string,slug:string,parent_id:?int,level:int,cnt:int}>
     */
    public function computeTree(array $opts = []): array
    {
        $cats = Database::connect()->table('categories')
            ->select('id, name, slug, parent_id, level')
            ->where('status', 'active')->where('deleted_at', null)
            ->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')
            ->get()->getResultArray();

        // Covering index scan: idx_products_cat_status_del(category_id,status,deleted_at)
        // lets MySQL count by category without touching the heap.  Hotel categories are
        // excluded by their IDs (fetched once from the 168-row categories table) instead of
        // a per-row path LIKE join, which prevented covering-index usage (2.1s → 0.25s).
        $hotelIds = array_column(
            Database::connect()->table('categories')->select('id')
                ->groupStart()->where('path', 'hotels-hospitality')->orLike('path', 'hotels-hospitality/', 'after')->groupEnd()
                ->where('deleted_at', null)->get()->getResultArray(),
            'id'
        );
        $notHotelSql = $hotelIds !== []
            ? 'p.category_id NOT IN (' . implode(',', array_map('intval', $hotelIds)) . ')'
            : '1=1';

        // For the global/bucket count the covering index is fastest. For a single-shop
        // scope, DON'T pin it — let the optimizer start from product_shops (idx_ps_shop)
        // and join the shop's few hundred rows instead of scanning all ~960K products.
        $singleShop = array_key_exists('shop_ids', $opts) && count((array) $opts['shop_ids']) <= 1;
        $from       = $singleShop ? 'products p' : 'products p USE INDEX (idx_products_cat_status_del)';
        $cntQ       = Database::connect()->table($from)
            ->select('p.category_id AS cid, COUNT(*) AS cnt')
            ->where('p.status', 'published')
            ->where('p.deleted_at', null)
            ->where($notHotelSql, null, false)
            // No `vendors` join here — this query stays join-free so the covering
            // index can serve it — so exclude manufacturers with the EXISTS form.
            ->where(self::NOT_MANUFACTURER_EXISTS, null, false);
        if (array_key_exists('shop_ids', $opts)) {
            $ids = array_values(array_filter(array_map('intval', (array) $opts['shop_ids'])));
            $in  = implode(',', $ids ?: [0]);
            $cntQ->where("EXISTS (SELECT 1 FROM product_shops ps WHERE ps.product_id = p.id AND ps.status = 'active' AND ps.shop_id IN ($in))", null, false);
        }
        $cnt = [];
        foreach ($cntQ->groupBy('p.category_id')->get()->getResultArray() as $r) {
            $cnt[(int) $r['cid']] = (int) $r['cnt'];
        }

        // Deepest level first: each node's count is final (includes its descendants)
        // by the time we fold it into its parent.
        $ordered = $cats;
        usort($ordered, static fn ($a, $b) => ((int) $b['level']) <=> ((int) $a['level']));
        foreach ($ordered as $c) {
            $id  = (int) $c['id'];
            $cnt[$id] ??= 0;
            $pid = $c['parent_id'] !== null ? (int) $c['parent_id'] : 0;
            if ($pid > 0) {
                $cnt[$pid] = ($cnt[$pid] ?? 0) + $cnt[$id];
            }
        }
        foreach ($cats as &$c) {
            $c['parent_id'] = $c['parent_id'] !== null ? (int) $c['parent_id'] : null;
            $c['level']     = (int) $c['level'];
            $c['cnt']       = $cnt[(int) $c['id']] ?? 0;
        }
        unset($c);

        return $cats;
    }

    /** Correlated subquery for a product's primary image uuid (served via /media/{uuid}). */
    private const IMG_SUBQUERY = "(SELECT ma.uuid FROM product_media pm JOIN media_assets ma ON ma.id = pm.media_id WHERE pm.product_id = p.id AND pm.deleted_at IS NULL AND ma.deleted_at IS NULL AND ma.status = 'active' ORDER BY pm.is_primary DESC, pm.sort_order ASC LIMIT 1) AS image_uuid";

    /** Hotels are excluded from the customer storefront (separate future vertical). */
    private const NOT_HOTEL = "COALESCE(c.path,'') NOT LIKE 'hotels-hospitality%'";

    /**
     * Manufacturers sell B2B only — their products must never reach the consumer
     * storefront or the customer apps. Requires the `vendors v` join (every query
     * using this already has it, except publishedTitle() where it was added).
     *
     * Written as COALESCE(...) so a product whose vendor row is missing (LEFT JOIN
     * miss) is still treated as a normal vendor product rather than silently dropped.
     */
    private const NOT_MANUFACTURER = "COALESCE(v.party_type,'vendor') <> 'manufacturer'";

    /**
     * Same rule for queries that cannot join `vendors` — e.g. computeTree(), which
     * stays join-free so it can be served by the covering index.
     */
    private const NOT_MANUFACTURER_EXISTS = "NOT EXISTS (SELECT 1 FROM vendors mv WHERE mv.id = p.vendor_id AND mv.party_type = 'manufacturer')";

    /**
     * Browse published products.
     * @param array{q?:string,category?:string,sort?:string,limit?:int,shop_ids?:list<int>} $opts
     *   shop_ids — when the KEY is present, scope to products listed in those shops
     *   (location-based delivery visibility). An empty list yields no products
     *   ("nothing delivers to your area"). Absent key = browse the full catalog.
     * @return list<array<string,mixed>>
     */
    public function products(array $opts = []): array
    {
        // When a category filter is active, force the covering composite index so MySQL
        // doesn't prefer the PRIMARY key scan (which needs to scan ~480K rows to find 12).
        $idxHint = ! empty($opts['category']) ? 'FORCE INDEX (idx_products_cat_status_del)' : '';
        $b       = $this->baseQuery($idxHint)
            ->select('p.id, p.title, p.slug, p.product_type, c.name AS category, c.slug AS category_slug, v.id AS vendor_id, v.display_name AS vendor, pv.base_price, pv.mrp, pv.id AS variant_id')
            ->select('(SELECT COUNT(*) FROM product_variants pvc WHERE pvc.product_id = p.id AND pvc.deleted_at IS NULL) AS variant_count', false)
            ->select(self::IMG_SUBQUERY, false);
        $this->applyFilters($b, $opts);

        switch ($opts['sort'] ?? '') {
            case 'price_asc':  $b->orderBy('pv.base_price', 'ASC'); break;
            case 'price_desc': $b->orderBy('pv.base_price', 'DESC'); break;
            case 'name':       $b->orderBy('p.title', 'ASC'); break;
            case 'featured':
                // featured/high search-boost first, then most recent (explicit opt-in only;
                // avoids FIELD() filesort on 960K rows as the implicit default).
                $b->orderBy("FIELD(p.search_boost,'featured','high','normal')", '', false)->orderBy('p.created_at', 'DESC');
                break;
            case 'none': break; // no ORDER BY — lets MySQL use covering index (0.015s) vs PK scan (0.38s)
            default: // 'newest' or unrecognised; also the empty-string default from Flutter's "Featured" option
                if (empty($opts['category'])) {
                    // No category filter: PRIMARY KEY reverse scan is instant (0.001s).
                    $b->orderBy('p.id', 'DESC');
                }
                // Category active: omit ORDER BY so the covering index scan (0.003s) is used
                // instead of a filesort on the matched set (~200K rows = 0.5s).
        }

        $limit = max(1, (int) ($opts['limit'] ?? 24));
        $page  = max(1, (int) ($opts['page'] ?? 1));

        return $b->limit($limit, ($page - 1) * $limit)->get()->getResultArray();
    }

    /** Total products matching the filters (for pagination). */
    public function countProducts(array $opts = []): int
    {
        // Unfiltered category/all browse → read the cached exact count for the GPS
        // bucket (SWR; never computed on the live request). Filtered counts (brand/
        // price/search) are a deliberate, lower-volume action → computed live.
        [$noOther] = $this->_noOtherFilters($opts);
        if ($noOther && $this->_cacheableScope($opts)) {
            return (int) (service('facetCache')->browse($opts)['total'] ?? 0);
        }

        return $this->computeCount($opts);
    }

    /** Raw product count for the filters (UNCACHED). Worker + live filtered path. */
    public function computeCount(array $opts = []): int
    {
        $idxHint = ! empty($opts['category']) ? 'FORCE INDEX (idx_products_cat_status_del)' : '';
        $b       = $this->baseQuery($idxHint);
        $this->applyFilters($b, $opts);

        return (int) $b->countAllResults();
    }

    /**
     * Base published-product builder shared by products()/countProducts().
     *
     * @param string $idxHint Optional MySQL index hint appended to the FROM clause
     *                        (e.g. 'FORCE INDEX (idx_products_cat_status_del)').
     *                        MySQL's optimizer consistently picks the wrong index when
     *                        category_id IN(...) is combined with ORDER BY / LIMIT —
     *                        use this to pin the covering index for category-filtered queries.
     */
    private function baseQuery(string $idxHint = ''): object
    {
        $from = $idxHint !== '' ? "products p $idxHint" : 'products p';

        return Database::connect()->table($from)
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->join('vendors v', 'v.id = p.vendor_id', 'left')
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left')
            ->where('p.status', 'published')->where('p.deleted_at', null)
            ->where(self::NOT_HOTEL, null, false)    // hotels are out of the storefront/order process
            ->where(self::NOT_MANUFACTURER, null, false); // manufacturers are B2B-only (msonline)
    }

    /**
     * Apply storefront WHERE filters: visibility, nearby-shop scope, category,
     * brand, product type, search, price, in-stock, on-offer.
     * @param list<string> $skip dimensions to NOT apply (used to build facet
     *        counts that reflect every OTHER active filter)
     */
    private function applyFilters(object $b, array $opts, array $skip = []): void
    {
        $this->visibleScope($b);

        if (! in_array('shop', $skip, true) && array_key_exists('shop_ids', $opts)) {
            $ids = array_values(array_filter(array_map('intval', (array) $opts['shop_ids'])));
            $in  = implode(',', $ids ?: [0]);
            $b->where("EXISTS (SELECT 1 FROM product_shops ps WHERE ps.product_id = p.id AND ps.status = 'active' AND ps.shop_id IN ($in))", null, false);
        }
        if (! in_array('category', $skip, true) && ! empty($opts['category'])) {
            // Resolve slug → subtree category IDs once (168 category rows), then filter
            // on p.category_id IN (...) so the composite index idx_products_cat_status_del
            // is used instead of a LIKE on c.path that forces a full scan.
            $cat = Database::connect()->table('categories')->select('id, path')
                ->where('slug', (string) $opts['category'])->where('deleted_at', null)->get()->getRowArray();
            if ($cat !== null && ($cat['path'] ?? '') !== '') {
                $subIds = Database::connect()->table('categories')->select('id')
                    ->groupStart()->where('path', $cat['path'])->orLike('path', $cat['path'] . '/', 'after')->groupEnd()
                    ->where('deleted_at', null)->get()->getResultArray();
                $b->whereIn('p.category_id', array_column($subIds, 'id') ?: [(int) $cat['id']]);
            } elseif ($cat !== null) {
                $b->where('p.category_id', (int) $cat['id']);
            }
        }
        if (! in_array('brand', $skip, true) && ! empty($opts['brand'])) {
            $b->where('p.brand_id', (int) $opts['brand']);
        }
        if (! in_array('vendor', $skip, true) && ! empty($opts['vendor_id'])) {
            $b->where('p.vendor_id', (int) $opts['vendor_id']);
        }
        if (! in_array('type', $skip, true) && ! empty($opts['product_type'])) {
            $b->where('p.product_type', (string) $opts['product_type']);
        }
        if (! in_array('q', $skip, true) && ! empty($opts['q'])) {
            $b->groupStart()->like('p.title', $opts['q'])->orLike('v.display_name', $opts['q'])->orLike('pv.sku', $opts['q'])->orLike('pv.barcode', $opts['q'])->groupEnd();
        }
        if (! in_array('price', $skip, true)) {
            if (isset($opts['min_price']) && $opts['min_price'] !== '') {
                $b->where('pv.base_price >=', (float) $opts['min_price']);
            }
            if (isset($opts['max_price']) && $opts['max_price'] !== '') {
                $b->where('pv.base_price <=', (float) $opts['max_price']);
            }
        }
        if (! empty($opts['on_offer'])) {
            $b->where('pv.mrp > pv.base_price');
        }
        if (! empty($opts['in_stock'])) {
            $shopSql = '';
            if (array_key_exists('shop_ids', $opts)) {
                $ids = array_values(array_filter(array_map('intval', (array) $opts['shop_ids'])));
                $shopSql = ' AND i.shop_id IN (' . implode(',', $ids ?: [0]) . ')';
            }
            $b->where("EXISTS (SELECT 1 FROM inventory i JOIN product_variants pvx ON pvx.id = i.variant_id WHERE pvx.product_id = p.id AND i.available > 0$shopSql)", null, false);
        }
        if (! empty($opts['label'])) {
            // Sanitize to alphanumeric + underscore (label codes are lowercase_snake).
            $label = mb_substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string) $opts['label'])), 0, 50);
            if ($label !== '') {
                $b->where("EXISTS (SELECT 1 FROM product_label_map plm JOIN product_labels pl ON pl.id = plm.label_id WHERE plm.product_id = p.id AND pl.code = '$label' AND pl.status = 'active')", null, false);
            }
        }
    }

    /**
     * Returns [bool $noOther] — true when no dimension filters beyond category +
     * shop scope are active (the cacheable, high-volume browse). When false, the
     * caller computes live (a deliberate, lower-volume filtered action).
     *
     * Wrapped in a single-element array so callers read it as `[$noOther] = …`,
     * leaving room to return more context later without touching call sites.
     *
     * @param array<string,mixed> $opts
     * @return array{0:bool}
     */
    private function _noOtherFilters(array $opts): array
    {
        $noOther = empty($opts['q']) && empty($opts['brand']) && empty($opts['product_type'])
            && empty($opts['min_price']) && empty($opts['max_price'])
            && empty($opts['in_stock']) && empty($opts['on_offer']) && empty($opts['label']);

        return [$noOther];
    }

    /** Fresh builder with the joins facet queries need (categories, vendors, default variant). */
    private function facetBase(string $idxHint = ''): object
    {
        $from = $idxHint !== '' ? "products p $idxHint" : 'products p';

        return Database::connect()->table($from)
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->join('vendors v', 'v.id = p.vendor_id', 'left')
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left')
            ->where('p.status', 'published')->where('p.deleted_at', null)
            ->where(self::NOT_HOTEL, null, false)    // hotels excluded from storefront
            ->where(self::NOT_MANUFACTURER, null, false); // manufacturers are B2B-only (msonline)
    }

    /**
     * Categories that have matching products in the current scope, with counts,
     * most-stocked first. Reflects every active filter except category itself.
     * @return list<array{id:int,name:string,slug:string,level:int,cnt:int}>
     */
    public function categoryFacets(array $opts, int $limit = 60): array
    {
        // Unfiltered browse: slice the cached, bucket-scoped category tree (computed
        // off-request) instead of a fresh ~960K-row GROUP BY — same data, ~0 cost.
        // Filtered browse (brand/price/search) falls back to the live scan.
        [$noOther] = $this->_noOtherFilters($opts);
        if ($noOther) {
            $rows = array_values(array_filter(array_map(
                static fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'slug' => $c['slug'], 'level' => $c['level'], 'cnt' => (int) $c['cnt']],
                $this->categoryTreeWithCounts($opts)
            ), static fn ($c) => $c['cnt'] > 0));
            usort($rows, static fn ($a, $b) => ($b['cnt'] <=> $a['cnt']) ?: strcmp((string) $a['name'], (string) $b['name']));

            return array_slice($rows, 0, $limit);
        }

        return $this->computeCategoryFacets($opts, $limit);
    }

    /** Raw category facets for the filters (UNCACHED). Worker + live filtered path. @return list<array<string,mixed>> */
    public function computeCategoryFacets(array $opts, int $limit = 60): array
    {
        $hint = ! empty($opts['category']) ? 'FORCE INDEX (idx_products_cat_status_del)' : '';
        $b    = $this->facetBase($hint)->select('c.id, c.name, c.slug, c.level, COUNT(DISTINCT p.id) AS cnt');
        $this->applyFilters($b, $opts, ['category']);

        return $b->groupBy('c.id')->having('cnt > 0')
            ->orderBy('cnt', 'DESC')->orderBy('c.name', 'ASC')->limit($limit)
            ->get()->getResultArray();
    }

    /**
     * Top-level (root) categories with their whole-subtree product counts, most
     * stocked first — for the home category grid + rails. Reflects active filters
     * except category. @return list<array{id:int,name:string,slug:string,cnt:int}>
     */
    public function rootCategoryFacets(array $opts, int $limit = 8): array
    {
        // Reuse the cached categoryTreeWithCounts() result — avoids a separate
        // 4-table facetBase() query and benefits from the 5-min CI4 cache.
        $tree  = $this->categoryTreeWithCounts($opts);
        $roots = array_values(array_filter($tree, static fn ($c) => $c['parent_id'] === null && (int) $c['cnt'] > 0));
        usort($roots, static fn ($a, $b) => (int) $b['cnt'] <=> (int) $a['cnt']);

        return array_slice(
            array_map(static fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'slug' => $c['slug'], 'cnt' => $c['cnt']], $roots),
            0,
            $limit
        );
    }

    /** Brands with product counts in scope (excludes the brand filter). @return list<array<string,mixed>> */
    public function brandFacets(array $opts, int $limit = 25): array
    {
        [$noOther] = $this->_noOtherFilters($opts);
        if ($noOther && $this->_cacheableScope($opts)) {
            return service('facetCache')->browse($opts)['brandFacets'] ?? [];
        }

        return $this->computeBrandFacets($opts, $limit);
    }

    /** Raw brand facets (UNCACHED). Worker + live filtered path. @return list<array<string,mixed>> */
    public function computeBrandFacets(array $opts, int $limit = 25): array
    {
        $hint = ! empty($opts['category']) ? 'FORCE INDEX (idx_products_cat_status_del)' : '';
        $b    = $this->facetBase($hint)->select('b.id, b.name, COUNT(DISTINCT p.id) AS cnt')
            ->join('brands b', 'b.id = p.brand_id', 'inner');
        $this->applyFilters($b, $opts, ['brand']);

        return $b->groupBy('b.id')->having('cnt > 0')
            ->orderBy('cnt', 'DESC')->orderBy('b.name', 'ASC')->limit($limit)
            ->get()->getResultArray();
    }

    /** Product types with counts in scope (excludes the type filter). @return list<array<string,mixed>> */
    public function typeFacets(array $opts): array
    {
        [$noOther] = $this->_noOtherFilters($opts);
        if ($noOther && $this->_cacheableScope($opts)) {
            return service('facetCache')->browse($opts)['typeFacets'] ?? [];
        }

        return $this->computeTypeFacets($opts);
    }

    /** Raw type facets (UNCACHED). Worker + live filtered path. @return list<array<string,mixed>> */
    public function computeTypeFacets(array $opts): array
    {
        $hint = ! empty($opts['category']) ? 'FORCE INDEX (idx_products_cat_status_del)' : '';
        $b    = $this->facetBase($hint)->select('p.product_type AS type, COUNT(DISTINCT p.id) AS cnt');
        $this->applyFilters($b, $opts, ['type']);

        return $b->groupBy('p.product_type')->having('cnt > 0')->orderBy('cnt', 'DESC')->get()->getResultArray();
    }

    /** Min/max selling price across the scope (for the price filter hints). @return array{lo:float,hi:float} */
    public function priceBounds(array $opts): array
    {
        [$noOther] = $this->_noOtherFilters($opts);
        if ($noOther && $this->_cacheableScope($opts)) {
            return service('facetCache')->browse($opts)['priceBounds'] ?? ['lo' => 0.0, 'hi' => 0.0];
        }

        return $this->computePriceBounds($opts);
    }

    /** Raw price bounds (UNCACHED). Worker + live filtered path. @return array{lo:float,hi:float} */
    public function computePriceBounds(array $opts): array
    {
        $hint = ! empty($opts['category']) ? 'FORCE INDEX (idx_products_cat_status_del)' : '';
        $b    = $this->facetBase($hint)->select('MIN(pv.base_price) AS lo, MAX(pv.base_price) AS hi');
        $this->applyFilters($b, $opts, ['price']);
        $row = $b->get()->getRowArray();

        return ['lo' => (float) ($row['lo'] ?? 0), 'hi' => (float) ($row['hi'] ?? 0)];
    }

    /** Discount % tier counts for admin banner filter builder. @return list<array{tier:int,label:string,count:int}> */
    public function computeDiscountTierFacets(array $opts): array
    {
        $hint = ! empty($opts['category']) ? 'FORCE INDEX (idx_products_cat_status_del)' : '';
        $b    = $this->facetBase($hint)
            ->select('FLOOR((1 - pv.base_price / pv.mrp) * 100 / 10) * 10 AS tier_floor, COUNT(DISTINCT p.id) AS cnt', false)
            ->where('pv.mrp > pv.base_price');
        $this->applyFilters($b, $opts, ['on_offer']);

        $rows = $b->groupBy('tier_floor')->having('tier_floor > 0')->having('cnt > 0')
            ->orderBy('tier_floor', 'ASC')->get()->getResultArray();

        return array_map(static function (array $r): array {
            $lo = (int) $r['tier_floor'];
            return ['tier' => $lo, 'label' => "{$lo}%–" . ($lo + 9) . '% off', 'count' => (int) $r['cnt']];
        }, $rows);
    }

    /** @return array<string,mixed>|null Product detail by slug. */
    public function findBySlug(string $slug): ?array
    {
        $row = Database::connect()->table('products p')
            ->select('p.id, p.title, p.slug, p.description, p.product_type, c.name AS category, c.slug AS category_slug, v.id AS vendor_id, v.display_name AS vendor, b.name AS brand, pv.id AS variant_id, pv.sku, pv.base_price, pv.mrp, tc.code AS tax_code')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->join('vendors v', 'v.id = p.vendor_id', 'left')
            ->join('brands b', 'b.id = p.brand_id', 'left')
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left')
            ->join('tax_classes tc', 'tc.id = p.tax_class_id', 'left')
            ->where('p.slug', $slug)->where('p.status', 'published')->where('p.deleted_at', null)
            ->where(self::NOT_HOTEL, null, false)    // hotel product pages are 404 (vertical removed)
            ->where(self::NOT_MANUFACTURER, null, false); // manufacturer product pages are 404 on the storefront
        $this->visibleScope($row);
        $row = $row->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * Storefront visibility + availability scope: only public/logged-in products
     * that are online-enabled and inside their availability window.
     */
    private function visibleScope(object $b): object
    {
        $today = date('Y-m-d');

        return $b->whereIn('p.visibility', ['public', 'logged_in'])->where('p.is_online_enabled', 1)
            ->groupStart()->where('p.available_from', null)->orWhere('p.available_from <=', $today)->groupEnd()
            ->groupStart()->where('p.available_to', null)->orWhere('p.available_to >=', $today)->groupEnd();
    }

    /** Long-form content (descriptions, ingredients, usage/safety/storage). @return array<string,mixed> */
    public function content(int $productId): array
    {
        return Database::connect()->table('product_content')->where('product_id', $productId)->get()->getRowArray() ?: [];
    }

    /** Non-variant specifications (attribute → value) for the spec table. @return list<array{name:string,value:string}> */
    public function specifications(int $productId): array
    {
        return Database::connect()->table('product_attribute_values pav')
            ->select("a.name, COALESCE(NULLIF(pav.value_text, ''), av.value) AS value", false)
            ->join('attributes a', 'a.id = pav.attribute_id', 'left')
            ->join('attribute_values av', 'av.id = pav.attribute_value_id', 'left')
            ->where('pav.product_id', $productId)
            ->orderBy('a.name')
            ->get()->getResultArray();
    }

    /**
     * Title of a published, publicly-visible (non-hotel) product by id, or null
     * when it isn't purchasable — guards the quick-add variant sheet endpoint.
     */
    public function publishedTitle(int $productId): ?string
    {
        $row = Database::connect()->table('products p')
            ->select('p.title')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->where('p.id', $productId)->where('p.status', 'published')->where('p.deleted_at', null)
            ->where('p.is_online_enabled', 1)
            ->whereIn('p.visibility', ['public', 'logged_in'])
            ->where(self::NOT_HOTEL, null, false)
            ->where(self::NOT_MANUFACTURER_EXISTS, null, false)
            ->get()->getRowArray();

        return $row['title'] ?? null;
    }

    /**
     * Purchasable variants with a human label (e.g. "Color: Black / Size: 9") for
     * the on-page variant selector. @return list<array<string,mixed>>
     */
    public function variants(int $productId): array
    {
        return Database::connect()->table('product_variants pv')
            ->select("pv.id AS variant_id, pv.sku, pv.base_price, pv.mrp, pv.is_default, GROUP_CONCAT(CONCAT(a.name, ': ', av.value) ORDER BY a.name SEPARATOR ' / ') AS label", false)
            ->join('variant_attribute_values vav', 'vav.variant_id = pv.id', 'left')
            ->join('attribute_values av', 'av.id = vav.attribute_value_id', 'left')
            ->join('attributes a', 'a.id = vav.attribute_id', 'left')
            ->where('pv.product_id', $productId)->where('pv.deleted_at', null)->where('pv.status', 'active')
            ->groupBy('pv.id')->orderBy('pv.is_default', 'DESC')->orderBy('pv.id')
            ->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> active labels for the storefront badges */
    public function labels(int $productId): array
    {
        return Database::connect()->table('product_label_map m')
            ->select('l.code, l.name, l.color')->join('product_labels l', 'l.id = m.label_id', 'left')
            ->where('m.product_id', $productId)->where('l.status', 'active')->where('l.deleted_at', null)
            ->orderBy('l.sort_order')->get()->getResultArray();
    }

    /** @return list<string> highlight bullets from product_content */
    public function highlights(int $productId): array
    {
        $row = Database::connect()->table('product_content')->select('highlights_json')->where('product_id', $productId)->get()->getRowArray();

        return $row && $row['highlights_json'] ? (json_decode((string) $row['highlights_json'], true) ?: []) : [];
    }

    /** @return list<array<string,mixed>> product-specific FAQs */
    public function faqs(int $productId): array
    {
        return Database::connect()->table('product_faqs')->select('question, answer')
            ->where('product_id', $productId)->where('status', 'active')->where('deleted_at', null)
            ->orderBy('sort_order')->get()->getResultArray();
    }

    /**
     * Stock position for a variant: inventory mode + total available across the
     * vendor's shops, used to cap cart/checkout quantities for managed products.
     * @return array{mode:string,available:float,backorder:bool,tracked:bool}
     */
    public function variantStock(int $variantId): array
    {
        $row = Database::connect()->table('product_variants pv')
            ->select('p.inventory_mode, p.backorder_enabled, COALESCE(SUM(i.available),0) AS available, COUNT(i.id) AS inv_rows')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->join('inventory i', 'i.variant_id = pv.id', 'left')
            ->where('pv.id', $variantId)
            ->groupBy('pv.id')
            ->get()->getRowArray();

        if ($row === null) {
            return ['mode' => 'managed', 'available' => 0.0, 'backorder' => false, 'tracked' => false];
        }

        return [
            'mode'      => (string) ($row['inventory_mode'] ?? 'managed'),
            'available' => (float) ($row['available'] ?? 0),
            'backorder' => (bool) ($row['backorder_enabled'] ?? false),
            'tracked'   => ((int) ($row['inv_rows'] ?? 0)) > 0,
        ];
    }

    /**
     * Can this variant be delivered to (lat,lng)? True if the product is carried
     * (product_shops active) by at least one ACTIVE shop of its vendor whose
     * delivery radius covers the location. Returns true when location is unknown
     * (nothing to check against) so it never blocks a locationless BROWSE session
     * (order-time coordinate validation is done by the callers — fail closed).
     */
    public function variantDeliverable(int $variantId, ?float $lat, ?float $lng): bool
    {
        return $this->variantDeliverability($variantId, $lat, $lng)['deliverable'];
    }

    /**
     * Detailed deliverability for a variant at (lat,lng). The effective radius for
     * each carrying shop is min(shop_radius, admin_max); a NULL shop radius means
     * "use the admin max" (the admin "Max delivery radius (km)" is always the outer
     * cap), and radius=0 means delivery disabled. Shops without coordinates can
     * never deliver (skipped). Returns the failure reason + the nearest carrying
     * shop so callers can show distinct, accurate messages for multi-shop carts.
     *
     * Reasons when not deliverable:
     *  - 'no_serving_shop'      no active shop with coords carries the variant here
     *  - 'outside_service_area' nearest carrying shop is beyond the admin max radius
     *  - 'shop_no_delivery'     within admin radius but beyond the shop's own radius
     *
     * @return array{deliverable:bool, reason:?string, shop_id:?int}
     */
    public function variantDeliverability(int $variantId, ?float $lat, ?float $lng, ?float $adminMax = null): array
    {
        // Unknown location: nothing to check against (browse). Callers enforce
        // coordinate presence at order time (fail closed) — see location_required.
        if ($lat === null || $lng === null) {
            return ['deliverable' => true, 'reason' => null, 'shop_id' => null];
        }
        $adminMax ??= service('settingsRepository')->deliveryMaxRadiusKm();

        $rows = Database::connect()->table('product_shops ps')
            ->select('s.id AS shop_id, s.latitude, s.longitude, s.delivery_radius_km')
            ->join('product_variants pv', 'pv.product_id = ps.product_id', 'inner')
            ->join('shops s', 's.id = ps.shop_id', 'inner')
            ->where('pv.id', $variantId)
            ->where('ps.status', 'active')->where('s.status', 'active')->where('s.deleted_at', null)
            // NULL radius = capped at admin max (no own restriction); radius=0 means disabled.
            ->groupStart()->where('s.delivery_radius_km >', 0)->orWhere('s.delivery_radius_km', null)->groupEnd()
            ->limit(200)
            ->get()->getResultArray();

        $nearestShopId = null;
        $nearestDist   = null;
        foreach ($rows as $s) {
            if ($s['latitude'] === null || $s['longitude'] === null) {
                continue; // a shop with no pin can never deliver
            }
            $dist      = LocationService::distanceKm($lat, $lng, (float) $s['latitude'], (float) $s['longitude']);
            $radius    = $s['delivery_radius_km'] === null ? null : (float) $s['delivery_radius_km'];
            $effective = LocationService::effectiveRadiusKm($radius, $adminMax);
            if ($dist <= $effective) {
                return ['deliverable' => true, 'reason' => null, 'shop_id' => (int) $s['shop_id']];
            }
            if ($nearestDist === null || $dist < $nearestDist) {
                $nearestDist   = $dist;
                $nearestShopId = (int) $s['shop_id'];
            }
        }

        return [
            'deliverable' => false,
            'reason'      => LocationService::undeliverableReason($nearestDist, $adminMax),
            'shop_id'     => $nearestShopId,
        ];
    }

    /** Purchase + order rules for the variant's product. @return array<string,mixed> */
    public function purchaseRulesForVariant(int $variantId): array
    {
        $row = Database::connect()->table('product_variants pv')
            ->select('p.min_purchase_qty, p.max_purchase_qty, p.qty_step, p.payment_restriction')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->where('pv.id', $variantId)->get()->getRowArray();

        return $row ?: [];
    }

    /** Purchase + order rules enforced by cart/checkout. @return array<string,mixed> */
    public function purchaseRules(int $productId): array
    {
        $row = Database::connect()->table('products')
            ->select('min_purchase_qty, max_purchase_qty, qty_step, payment_restriction, out_of_stock_handling, backorder_enabled, preorder_enabled')
            ->where('id', $productId)->get()->getRowArray();

        return $row ?: [];
    }

    /** Explicitly-curated related products (falls back handled by the caller). @return list<array<string,mixed>> */
    public function relatedProducts(int $productId, array $types = ['related', 'similar', 'fbt'], int $limit = 8): array
    {
        $b = Database::connect()->table('product_relations pr')
            ->select('p.id, p.title, p.slug, c.name AS category, c.slug AS category_slug, v.id AS vendor_id, v.display_name AS vendor, pv.base_price, pv.mrp, pv.id AS variant_id')
            ->select(self::IMG_SUBQUERY, false)
            ->join('products p', 'p.id = pr.related_product_id', 'left')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->join('vendors v', 'v.id = p.vendor_id', 'left')
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left')
            ->where('pr.product_id', $productId)->whereIn('pr.relation_type', $types)
            ->where('p.status', 'published')->where('p.deleted_at', null)
            // A curated relation must not become a back door onto the storefront:
            // without this a vendor product could link to a manufacturer's.
            ->where(self::NOT_HOTEL, null, false)
            ->where(self::NOT_MANUFACTURER, null, false);
        $this->visibleScope($b);

        return $b->orderBy('pr.sort_order')->limit($limit)->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> Published reviews for a product. */
    public function reviews(int $productId, int $limit = 10): array
    {
        return Database::connect()->table('reviews r')
            ->select('r.rating, r.title, r.body, r.created_at, u.name AS author')
            ->join('customers c', 'c.id = r.customer_id', 'left')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->where('r.product_id', $productId)->where('r.status', 'published')->where('r.deleted_at', null)
            ->orderBy('r.created_at', 'DESC')->limit($limit)
            ->get()->getResultArray();
    }

    public function variantForProduct(int $productId): ?int
    {
        $row = Database::connect()->table('product_variants')
            ->select('id')->where('product_id', $productId)->where('is_default', 1)
            ->get()->getRowArray();

        return $row ? (int) $row['id'] : null;
    }
}
