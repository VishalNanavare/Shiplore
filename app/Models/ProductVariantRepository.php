<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Catalog\VariantCombinator;
use Config\Database;
use Throwable;

/**
 * ProductVariantRepository — the variant engine. Resolves a category's
 * variant-defining attributes + values, generates the Cartesian product of
 * variants (skipping combinations that already exist), and lists/edits the
 * per-variant commercial + logistics fields. Tenant-scoped by vendor_id.
 *
 * @see App\Libraries\Catalog\VariantCombinator, docs/architecture/24-ADMIN-PANEL.md
 */
final class ProductVariantRepository
{
    /**
     * Variant-defining attributes (+values) a category exposes. If the category
     * declares attributes via category_attributes, restrict to the variant-
     * defining ones among them; otherwise fall back to all variant-defining.
     * @return list<array<string,mixed>>
     */
    public function definingAttributes(int $categoryId): array
    {
        $db = Database::connect();
        $mapped = $db->table('category_attributes ca')
            ->select('a.id, a.code, a.name, a.type')
            ->join('attributes a', 'a.id = ca.attribute_id', 'left')
            ->where('ca.category_id', $categoryId)->where('a.is_variant_defining', 1)
            ->where('a.deleted_at', null)->orderBy('a.name')->get()->getResultArray();

        $attrs = $mapped !== [] ? $mapped : $db->table('attributes')
            ->select('id, code, name, type')->where('is_variant_defining', 1)->where('status', 'active')
            ->where('deleted_at', null)->orderBy('name')->get()->getResultArray();

        foreach ($attrs as &$a) {
            $a['values'] = $db->table('attribute_values')->select('id, value, sort_order')
                ->where('attribute_id', (int) $a['id'])->where('deleted_at', null)
                ->orderBy('sort_order')->orderBy('value')->get()->getResultArray();
        }

        return $attrs;
    }

    /** @return array<string,true> signature => true, for existing variants */
    public function existingSignatures(int $productId): array
    {
        $rows = Database::connect()->table('variant_attribute_values vav')
            ->select('vav.variant_id, vav.attribute_id, vav.attribute_value_id')
            ->join('product_variants pv', 'pv.id = vav.variant_id', 'left')
            ->where('pv.product_id', $productId)->where('pv.deleted_at', null)
            ->get()->getResultArray();

        $byVariant = [];
        foreach ($rows as $r) {
            $byVariant[(int) $r['variant_id']][(int) $r['attribute_id']] = (int) $r['attribute_value_id'];
        }
        $sigs = [];
        foreach ($byVariant as $combo) {
            $sigs[VariantCombinator::signature($combo)] = true;
        }

        return $sigs;
    }

    /**
     * Generate variants for every selected combination not already present.
     * @param array<int,list<int>> $selections attribute_id => value_ids
     * @param array<string,mixed>  $base        skuPrefix, mrp, base_price
     * @return int number of variants created
     */
    public function generate(int $productId, int $vendorId, array $selections, array $base, ?int $actorId = null): int
    {
        $db      = Database::connect();
        $product = $db->table('products')->select('base_unit_id')->where('id', $productId)->get()->getRowArray();
        if ($product === null) {
            return 0;
        }
        $unitId = (int) $product['base_unit_id'];
        $labels = $this->valueLabels($selections);                 // value_id => label
        $combos = VariantCombinator::combine($selections);
        $seen   = $this->existingSignatures($productId);
        $prefix = strtoupper((string) ($base['skuPrefix'] ?? 'SKU')) ?: 'SKU';
        $created = 0;

        foreach ($combos as $combo) {
            $sig = VariantCombinator::signature($combo);
            if (isset($seen[$sig])) {
                continue;
            }
            $suffix = VariantCombinator::skuSuffix(array_map(static fn ($vid) => $labels[$vid] ?? (string) $vid, array_values($combo)));
            $sku    = $this->uniqueSku($db, $prefix . '-' . $suffix);

            $db->transBegin();
            try {
                $db->table('product_variants')->insert([
                    'uuid' => $this->uuid(), 'product_id' => $productId, 'vendor_id' => $vendorId,
                    'sku' => $sku, 'unit_id' => $unitId,
                    'mrp' => $base['mrp'] ?? 0, 'base_price' => $base['base_price'] ?? 0,
                    'is_default' => 0, 'status' => 'active', 'created_by' => $actorId,
                ]);
                $variantId = (int) $db->insertID();
                foreach ($combo as $attrId => $valueId) {
                    $db->table('variant_attribute_values')->insert([
                        'variant_id' => $variantId, 'attribute_id' => (int) $attrId, 'attribute_value_id' => (int) $valueId,
                    ]);
                }
                $db->transComplete();
                if ($db->transStatus()) {
                    $seen[$sig] = true;
                    $created++;
                }
            } catch (Throwable) {
                $db->transRollback();
            }
        }

        if ($created > 0) {
            $db->table('products')->where('id', $productId)->update(['product_type' => 'variant', 'updated_by' => $actorId]);
            $this->cleanupEmptyDefault($productId);
        }

        return $created;
    }

    /** @return list<array<string,mixed>> variants with a combined attribute label */
    public function listWithValues(int $productId): array
    {
        $db = Database::connect();
        $variants = $db->table('product_variants')
            // making_price is selected for every panel, not just the manufacturer one:
            // the column lives on the shared product_variants table (70_manufacturer.sql)
            // and the variant grid renders whichever price pair its panel asks for.
            // Vendors simply have NULL here, which renders as an empty input exactly as
            // an unset price always did.
            ->select('id, sku, barcode, mrp, making_price, base_price, cost_price, purchase_price, weight_grams, length_mm, width_mm, height_mm, reorder_level, safety_stock, is_default, status, visibility')
            ->where('product_id', $productId)->where('deleted_at', null)->orderBy('is_default', 'DESC')->orderBy('id')
            ->get()->getResultArray();
        if ($variants === []) {
            return [];
        }
        $ids  = array_column($variants, 'id');
        $vals = $db->table('variant_attribute_values vav')
            ->select('vav.variant_id, a.name AS attr, av.value AS val')
            ->join('attributes a', 'a.id = vav.attribute_id', 'left')
            ->join('attribute_values av', 'av.id = vav.attribute_value_id', 'left')
            ->whereIn('vav.variant_id', $ids)->get()->getResultArray();
        $byVariant = [];
        foreach ($vals as $v) {
            $byVariant[(int) $v['variant_id']][] = $v['attr'] . ': ' . $v['val'];
        }
        foreach ($variants as &$variant) {
            $variant['attributes'] = implode(', ', $byVariant[(int) $variant['id']] ?? []);
        }

        return $variants;
    }

    /** @return array<string,mixed>|null */
    public function findVariant(int $variantId): ?array
    {
        return Database::connect()->table('product_variants')->where('id', $variantId)->where('deleted_at', null)->get()->getRowArray() ?: null;
    }

    /**
     * Tenant-scoped PARTIAL update of a variant: only the fields actually posted
     * are written, so editing one column never wipes the others (cost/weight/
     * dims/reorder all survive a price-only save).
     * @param array<string,mixed> $d
     */
    public function updateVariant(int $variantId, int $vendorId, array $d, ?int $actorId = null): bool
    {
        $num  = static fn (string $k) => ($d[$k] ?? '') !== '' ? $d[$k] : null;
        $set  = ['updated_by' => $actorId];
        $has  = static fn (string $k): bool => array_key_exists($k, $d);

        if ($has('sku'))          { $set['sku'] = mb_substr((string) ($d['sku'] ?? ''), 0, 64); }
        if ($has('barcode'))      { $set['barcode'] = ($d['barcode'] ?? '') ?: null; }
        if ($has('mrp'))          { $set['mrp'] = $num('mrp') ?? 0; }
        if ($has('base_price'))   { $set['base_price'] = $num('base_price') ?? 0; }
        if ($has('cost_price'))   { $set['cost_price'] = $num('cost_price'); }
        if ($has('purchase_price')) { $set['purchase_price'] = $num('purchase_price'); }
        if ($has('weight_grams')) { $set['weight_grams'] = $num('weight_grams'); }
        if ($has('length_mm'))    { $set['length_mm'] = $num('length_mm'); }
        if ($has('width_mm'))     { $set['width_mm'] = $num('width_mm'); }
        if ($has('height_mm'))    { $set['height_mm'] = $num('height_mm'); }
        if ($has('reorder_level')) { $set['reorder_level'] = $num('reorder_level'); }
        if ($has('safety_stock')) { $set['safety_stock'] = $num('safety_stock'); }
        if ($has('status'))       { $set['status'] = in_array($d['status'] ?? '', ['active', 'inactive', 'discontinued'], true) ? $d['status'] : 'active'; }
        if ($has('visibility'))   { $set['visibility'] = ($d['visibility'] ?? '') === 'hidden' ? 'hidden' : 'public'; }

        return Database::connect()->table('product_variants')
            ->where('id', $variantId)->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->update($set);
    }

    /** Set a variant's on-hand stock in a shop to an absolute target (via the ledger-safe adjust). */
    public function setStock(int $variantId, int $shopId, float $target, ?int $actorId = null): void
    {
        if ($shopId <= 0 || $target < 0) {
            return;
        }
        $inv = service('inventoryService');
        $cur = (float) ($inv->levels($variantId, $shopId)['on_hand'] ?? 0);
        $delta = $target - $cur;
        if (abs($delta) > 0.0005) {
            $inv->adjust($variantId, $shopId, $delta, 'manual', 'Set on the Variants page', $actorId);
        }
    }

    /**
     * Once a product has real (attributed) variants, the auto-created empty
     * "Default" variant from product creation is redundant and confusing. Promote
     * the first attributed variant to default and retire the empty one. Idempotent.
     */
    public function cleanupEmptyDefault(int $productId): void
    {
        $db  = Database::connect();
        $def = $db->table('product_variants')->select('id')
            ->where('product_id', $productId)->where('is_default', 1)->where('deleted_at', null)
            ->get()->getRowArray();
        if ($def === null) {
            return;
        }
        $defId = (int) $def['id'];
        if ($db->table('variant_attribute_values')->where('variant_id', $defId)->countAllResults() > 0) {
            return;  // the default is itself a real variant — keep it
        }
        $promote = $db->table('product_variants pv')->select('pv.id')
            ->join('variant_attribute_values vav', 'vav.variant_id = pv.id', 'inner')
            ->where('pv.product_id', $productId)->where('pv.id !=', $defId)->where('pv.deleted_at', null)
            ->orderBy('pv.id')->get()->getRowArray();
        if ($promote === null) {
            return;  // no attributed variant yet — keep the empty default
        }
        $db->table('product_variants')->where('id', (int) $promote['id'])->update(['is_default' => 1]);
        $db->table('product_variants')->where('id', $defId)->update(['is_default' => 0, 'deleted_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Bulk-set one field across many of the vendor's variants (price/MRP/cost/
     * status). Only whitelisted fields; tenant-scoped by vendor_id.
     * @param list<int> $variantIds @return int rows updated
     */
    public function bulkUpdate(array $variantIds, int $vendorId, string $field, string $value, ?int $actorId = null): int
    {
        $ids = array_values(array_filter(array_map('intval', $variantIds)));
        if ($ids === []) {
            return 0;
        }
        $col = match ($field) {
            'mrp' => 'mrp', 'base_price' => 'base_price', 'cost_price' => 'cost_price', 'status' => 'status',
            default => null,
        };
        if ($col === null) {
            return 0;
        }
        $val = $col === 'status' ? (in_array($value, ['active', 'inactive', 'discontinued'], true) ? $value : 'active') : (is_numeric($value) ? $value : 0);

        return Database::connect()->table('product_variants')
            ->whereIn('id', $ids)->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->update([$col => $val, 'updated_by' => $actorId]) ? count($ids) : 0;
    }

    /** Delete a non-default variant (a product must keep at least its default). */
    public function deleteVariant(int $variantId, int $vendorId): bool
    {
        $db = Database::connect();
        $v  = $db->table('product_variants')->select('is_default')->where('id', $variantId)->where('vendor_id', $vendorId)->get()->getRowArray();
        if ($v === null || (int) $v['is_default'] === 1) {
            return false;
        }
        $db->table('variant_attribute_values')->where('variant_id', $variantId)->delete();

        return $db->table('product_variants')->where('id', $variantId)->where('vendor_id', $vendorId)->update(['deleted_at' => date('Y-m-d H:i:s')]);
    }

    /** @param array<int,list<int>> $selections @return array<int,string> value_id => label */
    private function valueLabels(array $selections): array
    {
        $ids = [];
        foreach ($selections as $vals) {
            foreach ((array) $vals as $v) {
                $ids[] = (int) $v;
            }
        }
        if ($ids === []) {
            return [];
        }
        $rows = Database::connect()->table('attribute_values')->select('id, value')->whereIn('id', array_unique($ids))->get()->getResultArray();
        $out  = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['value'];
        }

        return $out;
    }

    private function uniqueSku(object $db, string $sku): string
    {
        $sku  = mb_substr($sku, 0, 60);
        $base = $sku;
        $i    = 1;
        while ($db->table('product_variants')->where('sku', $sku)->countAllResults() > 0) {
            $sku = $base . '-' . (++$i);
        }

        return $sku;
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
