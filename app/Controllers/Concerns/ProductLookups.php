<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

/**
 * ProductLookups — shared read-only JSON endpoints that power the redesigned
 * product form's cascading loads (shops -> categories -> attributes -> defaults).
 * The host controller supplies the effective vendor id (admin: from the URL;
 * vendor: forced from session) and the access guard. A vendor can only read its
 * own shops and its business-type-allowed categories.
 */
trait ProductLookups
{
    /** Effective vendor for this request: admin uses $requested, vendor forces own. */
    abstract protected function effectiveVendorId(int $requested): ?int;

    abstract protected function lookupGuard();

    public function shops(int $vendorId = 0)
    {
        if ($g = $this->lookupGuard()) {
            return $g;
        }
        $vid = $this->effectiveVendorId($vendorId);
        if ($vid === null) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        return $this->response->setJSON(['ok' => true, 'data' => service('vendorShopRepository')->list($vid)]);
    }

    public function categories(int $vendorId = 0)
    {
        if ($g = $this->lookupGuard()) {
            return $g;
        }
        $vid = $this->effectiveVendorId($vendorId);
        if ($vid === null) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        return $this->response->setJSON(['ok' => true, 'data' => service('adminProductRepository')->allowedCategories($vid)]);
    }

    public function attributes(int $categoryId)
    {
        if ($g = $this->lookupGuard()) {
            return $g;
        }
        if (! $this->categoryAllowed($categoryId)) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        return $this->response->setJSON(['ok' => true, 'data' => service('catalogLookupRepository')->attributesForCategory($categoryId)]);
    }

    public function defaults(int $categoryId)
    {
        if ($g = $this->lookupGuard()) {
            return $g;
        }
        if (! $this->categoryAllowed($categoryId)) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        return $this->response->setJSON(['ok' => true, 'data' => service('catalogLookupRepository')->defaultsForCategory($categoryId)]);
    }

    /**
     * Search a variant attribute's values for the variant builder's Select2, so an
     * attribute with 1000+ values (e.g. Color) is typeable instead of a giant
     * checkbox list. Returns the Select2 shape {results:[{id,text}]}. Read-only.
     */
    public function attributeValues(int $attributeId)
    {
        if ($g = $this->lookupGuard()) {
            return $g;
        }
        $q     = trim((string) $this->request->getGet('q'));
        $limit = min(max((int) $this->request->getGet('limit') ?: 30, 1), 100);
        $b     = db_connect()->table('attribute_values')
            ->select('id, value')
            ->where('attribute_id', $attributeId)->where('deleted_at', null);
        if ($q !== '') {
            $b->like('value', $q, 'both');
        }
        $rows = $b->orderBy('sort_order', 'ASC')->orderBy('value', 'ASC')->limit($limit)->get()->getResultArray();

        return $this->response->setJSON([
            'results' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'text' => $r['value']], $rows),
        ]);
    }

    public function brands(int $categoryId)
    {
        if ($g = $this->lookupGuard()) {
            return $g;
        }
        if (! $this->categoryAllowed($categoryId)) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }
        // Return brands mapped to this category OR brands with no mappings at all (global).
        $rows = db_connect()->query(
            'SELECT b.id, b.name FROM brands b
             WHERE b.status = "active" AND b.deleted_at IS NULL
               AND (
                 EXISTS (SELECT 1 FROM brand_categories bc WHERE bc.brand_id = b.id AND bc.category_id = ?)
                 OR NOT EXISTS (SELECT 1 FROM brand_categories bc2 WHERE bc2.brand_id = b.id)
               )
             ORDER BY b.name',
            [$categoryId]
        )->getResultArray();

        return $this->response->setJSON(['ok' => true, 'data' => $rows]);
    }

    /** Admin may read any category; a vendor only its allowed set. */
    protected function categoryAllowed(int $categoryId): bool
    {
        $vid = $this->effectiveVendorId(0);
        if ($vid === null) {            // admin (no vendor context) -> allow
            return true;
        }
        $allowed = array_column(service('adminProductRepository')->allowedCategories($vid), 'id');

        return in_array($categoryId, array_map('intval', $allowed), true);
    }
}
