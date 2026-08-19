<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Catalog\ManufacturerPricing;
use Config\Database;
use Throwable;

/**
 * Combo offers for a manufacturer — several items sold as one.
 *
 * FORKED from ComboRepository rather than reused. That class is tenancy-clean — it
 * scopes components by vendor_id, and a manufacturer is a vendors row — but it carries
 * two vendor assumptions that are actively wrong here:
 *
 *   - it sets is_online_enabled = 1 when the caller asks for the 'online' channel. A
 *     manufacturer product on the consumer storefront is precisely the leak that
 *     is_online_enabled = 0 and visibility = 'vendor' exist to prevent, and widening
 *     that class would leave the leak one boolean away.
 *   - it prices with MRP. Manufacturers have no MRP; they price with a making price and
 *     a selling price, under a different invariant (0 < making < selling, equality
 *     rejected).
 *
 * The tables themselves are party-agnostic and reused as-is: products,
 * product_variants, product_bundle_items, product_mshops. Only the rules differ.
 */
final class ManufacturerComboRepository
{
    /**
     * Create a combo. Returns the new product id, or null if nothing was created.
     *
     * @param array<string,mixed> $in name, category_id, tax_class_id, unit_id,
     *                                making_price, base_price, components[], mshop_ids[]
     */
    public function create(int $manufacturerId, array $in, ?int $actorId = null): ?int
    {
        if ($manufacturerId <= 0) {
            return null;
        }

        // Prices are validated BEFORE anything is created, so a bad pair cannot leave a
        // half-built draft behind. Same invariant as any other manufacturer product.
        if (! ManufacturerPricing::isValid($in)) {
            return null;
        }

        $db         = Database::connect();
        $components = [];

        foreach ((array) ($in['components'] ?? []) as $c) {
            $vid = (int) ($c['variant_id'] ?? 0);
            $qty = (float) ($c['qty'] ?? 1);
            if ($vid > 0 && $qty > 0) {
                $components[$vid] = ($components[$vid] ?? 0) + $qty;   // repeats sum
            }
        }

        // Every component must be one of THIS manufacturer's own variants. Foreign ones
        // are dropped rather than rejected, and the count is re-checked afterwards —
        // otherwise a combo of one owned and one foreign variant would quietly become a
        // one-item "combo".
        if ($components !== []) {
            $owned = $db->table('product_variants')->select('id')
                ->whereIn('id', array_keys($components))
                ->where('vendor_id', $manufacturerId)->where('deleted_at', null)
                ->get()->getResultArray();
            $components = array_intersect_key($components, array_flip(array_map('intval', array_column($owned, 'id'))));
        }
        if (count($components) < 2) {
            return null;   // a combo of one thing is not a combo
        }

        $db->transBegin();

        try {
            $db->table('products')->insert([
                'uuid'         => $this->uuid(),
                'vendor_id'    => $manufacturerId,
                'title'        => mb_substr(trim((string) ($in['name'] ?? 'Combo')), 0, 191) ?: 'Combo',
                'category_id'  => (int) ($in['category_id'] ?? 0) ?: null,
                'tax_class_id' => (int) ($in['tax_class_id'] ?? 0) ?: null,
                'unit_id'      => (int) ($in['unit_id'] ?? 0) ?: null,
                'product_type' => 'bundle',
                // 'virtual' draws stock from the components at sale; 'assembled' is
                // built ahead and stocked in its own right.
                'combo_inventory_mode' => ($in['inventory_mode'] ?? 'virtual') === 'assembled' ? 'assembled' : 'virtual',
                // NOT configurable, unlike the vendor version. B2B containment: a
                // manufacturer combo must never be reachable from the storefront or the
                // consumer POS, whatever the caller asks for.
                'is_online_enabled' => 0,
                'is_pos_enabled'    => 0,
                'visibility'        => 'vendor',
                'status'            => 'draft',
                'created_by'        => $actorId,
            ]);
            $pid = (int) $db->insertID();

            // Price lives on the default variant — `products` carries no price columns.
            $db->table('product_variants')->insert([
                'uuid'         => $this->uuid(),
                'product_id'   => $pid,
                'vendor_id'    => $manufacturerId,
                'sku'          => 'CMB-' . $pid,
                'is_default'   => 1,
                'mrp'          => 0,                       // manufacturers have no MRP
                'making_price' => $this->money($in, 'making_price'),
                'base_price'   => $this->money($in, 'base_price'),
                'status'       => 'active',
                'created_by'   => $actorId,
            ]);

            foreach ($components as $vid => $qty) {
                $db->table('product_bundle_items')->insert([
                    'product_id'           => $pid,
                    'component_variant_id' => $vid,
                    'qty'                  => number_format($qty, 3, '.', ''),
                ]);
            }

            $this->listAtUnits($db, $pid, $manufacturerId, (array) ($in['mshop_ids'] ?? []));

            $db->transComplete();

            return $db->transStatus() ? $pid : null;
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'mfg combo create failed: ' . $e->getMessage());

            return null;
        }
    }

    /** @return list<array<string,mixed>> this manufacturer's combos, newest first */
    public function list(int $manufacturerId, int $limit = 200): array
    {
        if ($manufacturerId <= 0) {
            return [];
        }

        return Database::connect()->table('products p')
            ->select('p.id, p.title, p.status, p.combo_inventory_mode, p.created_at,
                      pv.sku, pv.making_price, pv.base_price,
                      (SELECT COUNT(*) FROM ' . Database::connect()->prefixTable('product_bundle_items') . ' bi
                        WHERE bi.product_id = p.id) AS component_count', false)
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left')
            ->where('p.vendor_id', $manufacturerId)
            ->where('p.product_type', 'bundle')
            ->where('p.deleted_at', null)
            ->orderBy('p.id', 'DESC')->limit($limit)
            ->get()->getResultArray();
    }

    // ---- internals ---------------------------------------------------------

    /**
     * List the combo at the manufacturer's own units only.
     *
     * Intersected with the manufacturer's mshops, so a posted unit id belonging to
     * another business is silently dropped rather than creating a row that would make
     * the combo sellable from their counter.
     *
     * @param list<int|string> $mshopIds
     */
    private function listAtUnits(object $db, int $productId, int $manufacturerId, array $mshopIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $mshopIds))));
        if ($ids === []) {
            return;
        }

        $owned = $db->table('mshops')->select('id')
            ->whereIn('id', $ids)->where('vendor_id', $manufacturerId)
            ->get()->getResultArray();

        foreach ($owned as $row) {
            $db->table('product_mshops')->insert([
                'product_id' => $productId,
                'mshop_id'   => (int) $row['id'],
                'status'     => 'active',
            ]);
        }
    }

    private function money(array $in, string $key): string
    {
        return number_format((float) ($in[$key] ?? 0), 4, '.', '');
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
