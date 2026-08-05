<?php

declare(strict_types=1);

namespace App\Libraries\Store;

use Config\Database;

/**
 * FacetCache — the stale-while-revalidate read layer for browse-page COUNTS
 * (pagination total + category/brand/type/price facets). The live request NEVER
 * computes these aggregates; it reads the last cached *exact* value for its GPS
 * bucket and returns instantly. When the value is stale (older than a short TTL)
 * or missing, it enqueues a background refresh (facet_refresh_queue) and still
 * returns the last value — the heavy aggregation runs only in the facets:refresh
 * worker, never on the page request.
 *
 * Two payload kinds, both keyed by GeoBucket scope_key ('global' when no location):
 *   - tree   (scope-level)            : full category tree with counts
 *   - browse (scope + category-level) : { total, brand, type, price }
 *
 * Layers, fastest first: CI4 cache() (APCu/file) → category_facet_summary (durable
 * base, always holds 'global') → empty placeholder + enqueue.
 *
 * Product list + per-item availability are NOT handled here — they stay live/exact.
 */
final class FacetCache
{
    /** Seconds a cached payload is considered fresh; past this, SWR enqueues a refresh. */
    public const TTL_FRESH = 90;

    /** CI4 cache() retention — long, so we keep serving stale while the worker catches up. */
    private const CACHE_KEEP = 3600;

    /** Sentinel category_slug for the scope-level tree payload. */
    private const TREE_CAT = '__tree__';

    /** Full category tree with counts for the bucket (SWR). @return list<array<string,mixed>> */
    public function tree(array $opts): array
    {
        $scope   = $this->scopeKey($opts);
        $payload = $this->read($scope, self::TREE_CAT);
        if ($payload !== null) {
            return $payload['tree'] ?? [];
        }

        return $this->coldFallback($scope, self::TREE_CAT)['tree'] ?? [];
    }

    /** { total, brand, type, price } for the bucket + category (SWR). @return array<string,mixed> */
    public function browse(array $opts): array
    {
        $scope   = $this->scopeKey($opts);
        $cat     = (string) ($opts['category'] ?? '');
        $payload = $this->read($scope, $cat);
        if ($payload !== null) {
            return $payload;
        }

        return $this->coldFallback($scope, $cat);
    }

    /**
     * Cold (scope has no cached/durable payload): enqueue a background refresh and
     * serve the shared 'global' base instantly — a location bucket NEVER computes on
     * the live request. Only the 'global' scope may bootstrap synchronously, and even
     * that is pre-warmed by `facets:refresh --rebuild-global` at deploy + on cron.
     */
    private function coldFallback(string $scope, string $cat): array
    {
        $this->enqueue($scope, $cat, 'cold');

        if ($scope !== 'global') {
            return $this->read('global', $cat) ?? $this->refreshFor('global', $cat);
        }

        return $this->refreshFor('global', $cat);
    }

    /**
     * Compute + persist a single payload (tree or browse) for a scope/category.
     *
     * refresh() (the worker entry) has always taken a lock here; coldFallback() —
     * reached synchronously from a live request when a scope has no cached/durable
     * payload — called this directly with none. N concurrent requests for the same
     * novel key (e.g. a burst of hits on one never-seen ?category= before the slug
     * canonicalisation memo above is warm, or genuinely simultaneous first hits) all
     * computed the same ~960K-row aggregate at once. Single-flight-locked the same
     * way refresh() already is: a request that loses the race serves the durable
     * base — stale (possibly zeroed if even that is missing) but instant — rather
     * than joining the stampede.
     */
    private function refreshFor(string $scope, string $cat): array
    {
        $lock = 'facetlock_' . $scope . '_' . md5($cat);
        if (cache($lock) !== null) {
            return $this->loadSummary($scope, $cat) ?? [
                'tree' => [], 'total' => 0, 'brandFacets' => [],
                'typeFacets' => [], 'priceBounds' => ['lo' => 0.0, 'hi' => 0.0], 'computed_at' => 0,
            ];
        }
        cache()->save($lock, 1, 120);
        try {
            return $cat === self::TREE_CAT ? $this->refreshTree($scope) : $this->refreshBrowse($scope, $cat);
        } finally {
            cache()->delete($lock);
        }
    }

    /**
     * Worker entry: recompute + persist a queued (scope, category). category===TREE_CAT
     * (or '') refreshes the scope tree; any other value refreshes that category's browse
     * payload. Single-flight-locked so two workers never recompute the same key.
     */
    public function refresh(string $scope, string $cat): void
    {
        $lock = 'facetlock_' . $scope . '_' . md5($cat);
        if (cache($lock) !== null) {
            return; // another worker owns this key
        }
        cache()->save($lock, 1, 120);
        try {
            if ($cat === self::TREE_CAT || $cat === '') {
                $this->refreshTree($scope);
            } else {
                $this->refreshTree($scope);   // browse facets need a fresh tree for category counts
                $this->refreshBrowse($scope, $cat);
            }
        } finally {
            cache()->delete($lock);
        }
    }

    /**
     * Worker: recompute the durable 'global' base — the tree + every populated
     * category's browse payload. Pre-warms the shared base so cold location buckets
     * serve it instantly. Locked so overlapping cron ticks don't double-compute.
     *
     * @return array{skipped?:bool,tree?:int,categories?:int}
     */
    public function rebuildGlobal(int $limitCats = 1000): array
    {
        if (cache('facetlock_rebuild_global') !== null) {
            return ['skipped' => true];
        }
        cache()->save('facetlock_rebuild_global', 1, 900);
        try {
            $tree = $this->refreshTree('global');
            $this->refreshBrowse('global', '');   // the all-products (no category) browse
            $n = 0;
            foreach ($tree['tree'] as $c) {
                if ((int) ($c['cnt'] ?? 0) <= 0) {
                    continue;
                }
                $this->refreshBrowse('global', (string) $c['slug']);
                if (++$n >= $limitCats) {
                    break;
                }
            }

            return ['tree' => 1, 'categories' => $n];
        } finally {
            cache()->delete('facetlock_rebuild_global');
        }
    }

    /**
     * Worker: drain dirty markers — recompute each queued (scope, category) exactly.
     * The tree for a scope is recomputed once per run. store() clears processed rows.
     *
     * @return array{skipped?:bool,processed?:int}
     */
    public function drainQueue(int $limit = 200): array
    {
        if (cache('facetlock_drain') !== null) {
            return ['skipped' => true];
        }
        cache()->save('facetlock_drain', 1, 120);
        try {
            $rows = Database::connect()->query(
                'SELECT scope_key, category_slug FROM facet_refresh_queue ORDER BY enqueued_at ASC LIMIT ' . max(1, $limit)
            )->getResultArray();

            $treeDone = [];
            $count    = 0;
            foreach ($rows as $r) {
                $scope = (string) $r['scope_key'];
                $cat   = (string) $r['category_slug'];
                if (! isset($treeDone[$scope])) {
                    $this->refreshTree($scope);
                    $treeDone[$scope] = true;
                }
                if ($cat !== self::TREE_CAT) {
                    $this->refreshBrowse($scope, $cat);
                }
                $count++;
            }

            return ['processed' => $count];
        } finally {
            cache()->delete('facetlock_drain');
        }
    }

    /**
     * Catalog/zone change hook — mark the global base dirty so the next worker tick
     * recomputes it (the tree + the affected category, or all-products when unknown).
     * Per-bucket counts self-heal via the SWR TTL on their next read. Cheap: two
     * deduped INSERT IGNOREs; never recomputes inline.
     *
     * Stock-driven freshness is intentionally NOT hooked here (too hot a path) — the
     * 90 s SWR TTL refreshes availability counts on the next read instead.
     */
    public function invalidate(?string $categorySlug = null): void
    {
        $this->enqueue('global', self::TREE_CAT, 'catalog');
        $this->enqueue('global', $categorySlug ?? '', 'catalog');
    }

    /** Insert a dirty marker (deduped by the unique key). Cheap; safe to call often. */
    public function enqueue(string $scope, string $cat, string $reason = 'stale'): void
    {
        $db = Database::connect();
        $db->query(
            'INSERT IGNORE INTO facet_refresh_queue (scope_key, category_slug, reason, enqueued_at)
             VALUES (?, ?, ?, ?)',
            [$scope, $cat, substr($reason, 0, 24), time()]
        );
    }

    // ---- internals ------------------------------------------------------------

    /** Bucket scope key: explicit 'bucket' (lat/lng cell) → per-shop key → 'global'. */
    private function scopeKey(array $opts): string
    {
        if (! empty($opts['bucket'])) {
            return (string) $opts['bucket'];
        }
        if (isset($opts['shop_ids'])) {
            $ids = array_values(array_filter(array_map('intval', (array) $opts['shop_ids'])));

            return $ids === [] ? 'none' : 'shops_' . md5(implode(',', $ids));
        }

        return 'global';
    }

    /**
     * SWR read: CI4 cache → durable summary row → null. Returns the payload (with its
     * 'computed_at') instantly. When stale, reloads from the summary table (the shared
     * source of truth — so an APCu-isolated fpm worker still picks up the CLI worker's
     * fresh computation) and, if still stale, enqueues a background refresh.
     */
    private function read(string $scope, string $cat): ?array
    {
        $key     = $this->cacheKey($scope, $cat);
        $payload = cache($key);
        $stale   = $payload === null || (time() - (int) ($payload['computed_at'] ?? 0)) > self::TTL_FRESH;

        if ($stale) {
            $fresh = $this->loadSummary($scope, $cat);
            if ($fresh !== null
                && ($payload === null || (int) ($fresh['computed_at'] ?? 0) >= (int) ($payload['computed_at'] ?? 0))) {
                $payload = $fresh;
                cache()->save($key, $payload, self::CACHE_KEEP);
            }
            if ($payload === null) {
                return null; // truly cold — caller falls back to the global base + enqueues
            }
            if ((time() - (int) ($payload['computed_at'] ?? 0)) > self::TTL_FRESH) {
                $this->enqueue($scope, $cat, 'stale');
            }
        }

        return $payload;
    }

    private function refreshTree(string $scope): array
    {
        $repo    = service('storeCatalogRepository');
        $tree    = $repo->computeTree($this->scopeOpts($scope));
        $payload = ['tree' => $tree, 'computed_at' => time()];
        $this->store($scope, self::TREE_CAT, $payload);

        return $payload;
    }

    private function refreshBrowse(string $scope, string $cat): array
    {
        $repo    = service('storeCatalogRepository');
        $opts    = $this->scopeOpts($scope);
        $opts['category'] = $cat;
        $payload = [
            'total'       => $repo->computeCount($opts),
            'brandFacets' => $repo->computeBrandFacets($opts),
            'typeFacets'  => $repo->computeTypeFacets($opts),
            'priceBounds' => $repo->computePriceBounds($opts),
            'computed_at' => time(),
        ];
        $this->store($scope, $cat, $payload);

        return $payload;
    }

    /** Build compute opts from a scope key: resolve the cell's canonical shop set. */
    private function scopeOpts(string $scope): array
    {
        if ($scope === 'global') {
            return [];
        }
        $centroid = GeoBucket::centroidFromKey($scope);
        if ($centroid === null) {
            return []; // per-shop / unknown scope: treat as global base
        }
        $ids = service('storeShopRepository')->nearbyShopIds($centroid[0], $centroid[1]);

        return ['shop_ids' => $ids];
    }

    /** Write both layers: durable summary row (upsert) + hot CI4 cache. */
    private function store(string $scope, string $cat, array $payload): void
    {
        Database::connect()->query(
            'INSERT INTO category_facet_summary (scope_key, category_slug, payload, computed_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), computed_at = VALUES(computed_at)',
            [$scope, $cat, json_encode($payload), (int) $payload['computed_at']]
        );
        cache()->save($this->cacheKey($scope, $cat), $payload, self::CACHE_KEEP);

        // This key is now fresh — drop any pending dirty marker.
        Database::connect()->query(
            'DELETE FROM facet_refresh_queue WHERE scope_key = ? AND category_slug = ?',
            [$scope, $cat]
        );
    }

    private function loadSummary(string $scope, string $cat): ?array
    {
        $row = Database::connect()->query(
            'SELECT payload FROM category_facet_summary WHERE scope_key = ? AND category_slug = ? LIMIT 1',
            [$scope, $cat]
        )->getRowArray();
        if ($row === null) {
            return null;
        }
        $payload = json_decode((string) $row['payload'], true);

        return is_array($payload) ? $payload : null;
    }

    private function cacheKey(string $scope, string $cat): string
    {
        return 'facet_' . $scope . '_' . ($cat === '' ? '_all' : md5($cat));
    }
}
