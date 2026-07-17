<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * ComboRepository — Phase S3: a combo is a bundle-type product (Coke + Chips)
 * with its own price/barcode and component links. create() reuses the tested
 * draftCreate() to make the base product safely, flips it to a bundle, and
 * records the components in product_bundle_items.
 *
 * Channel governs visibility: a combo is always POS-sellable; `is_online_enabled`
 * is set only when the combo was approved for the online channel (AR-14).
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §9
 */
final class ComboRepository
{
    /** @param array<string,mixed> $in name, category_id, tax_class_id, unit_id, mrp, price, channel, inventory_mode, components[] */
    public function create(int $vendorId, array $in, ?int $actorId = null): ?int
    {
        $db = Database::connect();

        // Resolve + validate components first: each must be one of THIS vendor's
        // own variants (tenant isolation). Bail before creating anything if fewer
        // than two valid components survive.
        $components = [];
        foreach ((array) ($in['components'] ?? []) as $c) {
            $vid = (int) ($c['variant_id'] ?? 0);
            $qty = (float) ($c['qty'] ?? 1);
            if ($vid > 0 && $qty > 0) {
                $components[$vid] = $qty;   // de-dupe by variant id
            }
        }
        if ($components !== []) {
            $owned = $db->table('product_variants')->select('id')
                ->whereIn('id', array_keys($components))
                ->where('vendor_id', $vendorId)->where('deleted_at', null)
                ->get()->getResultArray();
            $components = array_intersect_key($components, array_flip(array_map('intval', array_column($owned, 'id'))));
        }
        if (count($components) < 2) {
            return null;
        }

        $pid = service('adminProductRepository')->draftCreate([
            'vendor_id'    => $vendorId,
            'category_id'  => (int) ($in['category_id'] ?? 0),
            'title'        => (string) ($in['name'] ?? 'Combo'),
            'tax_class_id' => (int) ($in['tax_class_id'] ?? 0),
            'unit_id'      => (int) ($in['unit_id'] ?? 0),
        ], $actorId);
        if ($pid === null) {
            return null;
        }

        $online = ($in['channel'] ?? 'pos') === 'online';
        $db->table('products')->where('id', $pid)->update([
            'product_type'         => 'bundle',
            'combo_inventory_mode' => ($in['inventory_mode'] ?? 'virtual') === 'assembled' ? 'assembled' : 'virtual',
            'is_pos_enabled'       => 1,
            'is_online_enabled'    => $online ? 1 : 0,
            'status'               => 'approved',
            'updated_by'           => $actorId,
        ]);

        // Combo price + MRP live on the default variant — `products` has no price
        // columns (they sit on product_variants, like every other product).
        $db->table('product_variants')->where('product_id', $pid)->where('is_default', 1)->update([
            'mrp'        => (string) ($in['mrp'] ?? '0'),
            'base_price' => (string) ($in['price'] ?? '0'),
            'updated_by' => $actorId,
        ]);

        foreach ($components as $vid => $qty) {
            $db->table('product_bundle_items')->insert([
                'uuid'              => $this->uuid(),
                'bundle_product_id' => $pid,
                'item_variant_id'   => (int) $vid,
                'qty'               => $qty,
                'created_by'        => $actorId,
            ]);
        }

        return $pid;
    }

    /** @return list<array<string,mixed>> the vendor's combos */
    public function list(int $vendorId): array
    {
        return Database::connect()->table('products p')
            ->select('p.id, p.title, pv.mrp, pv.base_price, p.is_online_enabled, p.status, p.created_at')
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left')
            ->where('p.vendor_id', $vendorId)->where('p.product_type', 'bundle')->where('p.deleted_at', null)
            ->orderBy('p.id', 'DESC')->limit(200)->get()->getResultArray();
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0F | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
