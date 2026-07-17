<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * CatalogLookupRepository — read-only lookups that power the redesigned product
 * form's dynamic dependency loading: a category's attributes (variant-defining
 * vs descriptive) and its suggested tax/HSN defaults. Shops + allowed categories
 * are served by the existing vendorShop / adminProduct repositories.
 */
final class CatalogLookupRepository
{
    /**
     * Attributes a category exposes, split for the UI.
     * @return array{variant_defining:list<array<string,mixed>>, custom:list<array<string,mixed>>}
     */
    public function attributesForCategory(int $categoryId): array
    {
        $db = Database::connect();

        $variant = service('productVariantRepository')->definingAttributes($categoryId);

        // descriptive (non-variant) attributes mapped to the category, else all
        $mapped = $db->table('category_attributes ca')
            ->select('a.id, a.code, a.name, a.type')
            ->join('attributes a', 'a.id = ca.attribute_id', 'left')
            ->where('ca.category_id', $categoryId)->where('a.is_variant_defining', 0)->where('a.deleted_at', null)
            ->orderBy('a.name')->get()->getResultArray();
        $custom = $mapped !== [] ? $mapped : $db->table('attributes')
            ->select('id, code, name, type')->where('is_variant_defining', 0)->where('status', 'active')->where('deleted_at', null)
            ->orderBy('name')->get()->getResultArray();
        foreach ($custom as &$c) {
            $c['values'] = $db->table('attribute_values')->select('id, value')->where('attribute_id', (int) $c['id'])->where('deleted_at', null)->orderBy('sort_order')->orderBy('value')->get()->getResultArray();
        }

        return ['variant_defining' => $variant, 'custom' => $custom];
    }

    /**
     * Suggested tax/HSN for a category (the user just confirms).
     * @return array{tax_class_id:?int, tax_class_name:?string, gst_pct:?string, hsn_id:?int, hsn_code:?string}
     */
    public function defaultsForCategory(int $categoryId): array
    {
        $db  = Database::connect();
        $cat = $db->table('categories')->select('default_tax_class_id')->where('id', $categoryId)->get()->getRowArray();
        $taxId = $cat && $cat['default_tax_class_id'] !== null ? (int) $cat['default_tax_class_id'] : null;
        if ($taxId === null) {
            return ['tax_class_id' => null, 'tax_class_name' => null, 'gst_pct' => null, 'hsn_id' => null, 'hsn_code' => null];
        }

        $tc  = $db->table('tax_classes')->select('name')->where('id', $taxId)->get()->getRowArray();
        $rate = $db->table('tax_rates')->select('igst')->where('tax_class_id', $taxId)->where('status', 'active')->orderBy('valid_from', 'DESC')->get()->getRowArray();
        $hsn = $db->table('hsn_sac_codes')->select('id, code')->where('default_tax_class_id', $taxId)->where('status', 'active')->where('deleted_at', null)->orderBy('id')->get()->getRowArray();

        return [
            'tax_class_id'   => $taxId,
            'tax_class_name' => $tc['name'] ?? null,
            'gst_pct'        => $rate['igst'] ?? null,
            'hsn_id'         => $hsn ? (int) $hsn['id'] : null,
            'hsn_code'       => $hsn['code'] ?? null,
        ];
    }
}
