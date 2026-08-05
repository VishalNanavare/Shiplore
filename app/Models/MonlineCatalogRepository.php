<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * MonlineCatalogRepository — the manufacturer catalogue shown on monline.shiplore.in.
 *
 * The mirror image of StoreCatalogRepository: that one EXCLUDES manufacturers, this one
 * shows ONLY manufacturers. Kept separate rather than parameterising the consumer
 * repository, so a change to consumer browsing can never widen what B2B exposes.
 *
 * Two rules are enforced by construction, not by the caller remembering:
 *
 *   1. `$withPrices` defaults to FALSE. A logged-out visitor gets rows with no price
 *      keys at all — not zeroed, not hidden in the view. If a template forgets to check,
 *      the data simply is not there. The caller must positively opt in by passing true,
 *      which the controller does only for a resolved buyer.
 *
 *   2. `making_price` is never in a SELECT list here. It is the manufacturer's internal
 *      production cost; a buyer must not see it, and the safest way to guarantee that is
 *      for the query never to fetch it.
 */
final class MonlineCatalogRepository
{
    /** Mirrors StoreCatalogRepository::IMG_SUBQUERY — a product's primary image, if any. */
    private const IMG_SUBQUERY = "(SELECT ma.uuid FROM product_media pm JOIN media_assets ma ON ma.id = pm.media_id WHERE pm.product_id = p.id AND pm.deleted_at IS NULL AND ma.deleted_at IS NULL AND ma.status = 'active' ORDER BY pm.is_primary DESC, pm.sort_order ASC LIMIT 1) AS image_uuid";

    /**
     * Distance in km from the buyer's point to the nearest active manufacturer unit
     * carrying this product. A product may be carried by more than one mshop, so this
     * is a MIN() over its carrying units, not a plain join (which would duplicate rows
     * and need a GROUP BY — the same reason IMG_SUBQUERY is a subquery, not a join).
     *
     * The GREATEST(-1, LEAST(1, ...)) clamp is required, not decorative: floating-point
     * rounding can push ACOS's argument fractionally outside [-1,1] for a near-identical
     * point, which makes MySQL return NULL for what should be the NEAREST result.
     */
    private function distanceSubquery(float $lat, float $lng): string
    {
        $latSql = sprintf('%.7f', $lat);
        $lngSql = sprintf('%.7f', $lng);

        return "(SELECT MIN(
                    6371 * ACOS(GREATEST(-1, LEAST(1,
                        COS(RADIANS($latSql)) * COS(RADIANS(ms.latitude)) * COS(RADIANS(ms.longitude) - RADIANS($lngSql))
                        + SIN(RADIANS($latSql)) * SIN(RADIANS(ms.latitude))
                    )))
                 )
                 FROM product_mshops pms
                 JOIN mshops ms ON ms.id = pms.mshop_id
                 WHERE pms.product_id = p.id AND pms.status = 'active'
                   AND ms.status = 'active' AND ms.deleted_at IS NULL
                ) AS distance_km";
    }

    /** Only published products belonging to an active manufacturer. */
    private function base(): object
    {
        return Database::connect()->table('products p')
            ->join('vendors v', 'v.id = p.vendor_id')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left')
            ->where('v.party_type', 'manufacturer')
            ->where('v.deleted_at', null)
            ->whereIn('v.status', ['approved', 'active'])
            ->where('p.status', 'published')
            ->where('p.deleted_at', null);
    }

    /**
     * Browse the wholesale catalogue.
     *
     * @param array{q?:string,category?:string,manufacturer_id?:int,limit?:int,offset?:int,sort_lat?:float,sort_lng?:float} $opts
     * @param bool $withPrices opt-in; only ever true for a signed-in buyer
     * @return list<array<string,mixed>>
     */
    public function products(array $opts = [], bool $withPrices = false): array
    {
        $cols = 'p.id, p.title, p.slug, p.min_purchase_qty, p.qty_step,
                 c.name AS category, v.id AS manufacturer_id, v.display_name AS manufacturer, pv.id AS variant_id, pv.sku';
        if ($withPrices) {
            // Selling price only. making_price is deliberately absent.
            $cols .= ', pv.base_price';
        }

        $b = $this->base()->select($cols)->select(self::IMG_SUBQUERY, false);

        if (! empty($opts['q'])) {
            $b->groupStart()->like('p.title', $opts['q'])->orLike('v.display_name', $opts['q'])->orLike('pv.sku', $opts['q'])->groupEnd();
        }
        if (! empty($opts['category'])) {
            $b->where('c.slug', $opts['category']);
        }
        if (! empty($opts['manufacturer_id'])) {
            $b->where('v.id', (int) $opts['manufacturer_id']);
        }

        $limit     = min(max((int) ($opts['limit'] ?? 48), 1), 100);
        $hasPoint  = isset($opts['sort_lat'], $opts['sort_lng']);

        if ($hasPoint) {
            $b->select($this->distanceSubquery((float) $opts['sort_lat'], (float) $opts['sort_lng']), false);
            // NULL distance (a product with no active carrying unit — should be rare)
            // sorts LAST regardless of direction; MySQL otherwise treats NULL as
            // smallest and would put "unknown distance" ahead of the true nearest.
            // p.id is a required tiebreaker: without one, LIMIT/OFFSET pagination is
            // not stable across pages for rows tied on distance.
            $b->orderBy('(distance_km IS NULL)', 'ASC', false)
              ->orderBy('distance_km', 'ASC')
              ->orderBy('p.id', 'DESC');
        } else {
            $b->orderBy('p.id', 'DESC');
        }

        return $b->limit($limit, max(0, (int) ($opts['offset'] ?? 0)))->get()->getResultArray();
    }

    public function countProducts(array $opts = []): int
    {
        $b = $this->base();
        if (! empty($opts['q'])) {
            $b->groupStart()->like('p.title', $opts['q'])->orLike('v.display_name', $opts['q'])->orLike('pv.sku', $opts['q'])->groupEnd();
        }
        if (! empty($opts['category'])) {
            $b->where('c.slug', $opts['category']);
        }
        if (! empty($opts['manufacturer_id'])) {
            $b->where('v.id', (int) $opts['manufacturer_id']);
        }

        return $b->countAllResults();
    }

    /**
     * A single product for the detail view.
     *
     * @return array<string,mixed>|null
     */
    public function findBySlug(string $slug, bool $withPrices = false): ?array
    {
        if ($slug === '') {
            return null;
        }
        $cols = 'p.id, p.title, p.slug, p.description, p.min_purchase_qty, p.max_purchase_qty, p.qty_step,
                 c.name AS category, v.id AS manufacturer_id, v.display_name AS manufacturer, pv.id AS variant_id, pv.sku';
        if ($withPrices) {
            $cols .= ', pv.base_price';
        }

        return $this->base()->select($cols)->select(self::IMG_SUBQUERY, false)->where('p.slug', $slug)->get()->getRowArray();
    }

    /**
     * Priced cart lines for a buyer. Only ever called for a resolved buyer, so prices
     * are unconditional here — but making_price is still never selected.
     *
     * @param array<int,float> $cart variantId => qty
     * @return list<array<string,mixed>>
     */
    public function cartLines(array $cart): array
    {
        $ids = array_values(array_filter(array_map('intval', array_keys($cart))));
        if ($ids === []) {
            return [];
        }

        $rows = $this->base()
            ->select('pv.id AS variant_id, pv.base_price, pv.sku, p.title, p.min_purchase_qty, p.max_purchase_qty, p.qty_step,
                      v.id AS manufacturer_id, v.display_name AS manufacturer')
            ->select(self::IMG_SUBQUERY, false)
            ->whereIn('pv.id', $ids)
            ->where('pv.status', 'active')
            ->where('pv.deleted_at', null)
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $qty          = (float) ($cart[(int) $r['variant_id']] ?? 0);
            $r['qty']     = $qty;
            $r['line_total'] = round((float) $r['base_price'] * $qty, 2);
            $out[]        = $r;
        }

        return $out;
    }

    /** Manufacturers with at least one published product, for the browse filter. */
    public function manufacturers(): array
    {
        return $this->base()
            ->select('v.id, v.display_name, COUNT(DISTINCT p.id) AS product_count', false)
            ->groupBy('v.id, v.display_name')
            ->orderBy('v.display_name')
            ->limit(100)
            ->get()->getResultArray();
    }
}
