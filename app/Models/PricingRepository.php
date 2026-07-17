<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Pricing\PriceResolver;
use Config\Database;

/**
 * PricingRepository — assembles a variant's price candidates from the existing
 * pricing tables (price_lists + price_list_items overlays, price_tiers qty
 * slabs, customer_group_prices) and resolves the effective price via the pure
 * PriceResolver. Also CRUD for special prices / tiers / group prices.
 *
 * price_lists has no vendor_id, so tenant safety comes from the linked variant:
 * callers must verify variant ownership before mutating.
 *
 * @see App\Libraries\Pricing\PriceResolver
 */
final class PricingRepository
{
    /** Flat candidate list for the resolver. @return list<array<string,mixed>> */
    public function candidatesFor(int $variantId): array
    {
        $db = Database::connect();

        $items = $db->table('price_list_items pli')
            ->select('pli.price, pl.kind, pl.channel, pl.customer_group_id, pl.shop_id, pl.priority, pl.valid_from, pl.valid_to, 1 AS min_qty, pl.id AS list_id')
            ->join('price_lists pl', 'pl.id = pli.price_list_id', 'left')
            ->where('pli.variant_id', $variantId)->where('pli.deleted_at', null)
            ->where('pl.deleted_at', null)->where('pl.status', 'active')
            ->get()->getResultArray();

        $tiers = $db->table('price_tiers pt')
            ->select('pt.price, pl.kind, pl.channel, pl.customer_group_id, pl.shop_id, pl.priority, pl.valid_from, pl.valid_to, pt.min_qty, pl.id AS list_id')
            ->join('price_lists pl', 'pl.id = pt.price_list_id', 'left')
            ->where('pt.variant_id', $variantId)->where('pl.deleted_at', null)->where('pl.status', 'active')
            ->get()->getResultArray();

        $groups = $db->table('customer_group_prices')
            ->select("price, 'group' AS kind, 'all' AS channel, customer_group_id, NULL AS shop_id, 5 AS priority, NULL AS valid_from, NULL AS valid_to, 1 AS min_qty, NULL AS list_id", false)
            ->where('variant_id', $variantId)->where('deleted_at', null)
            ->get()->getResultArray();

        return array_merge($items, $tiers, $groups);
    }

    /** @param array<string,mixed> $ctx @return array{price:float,source:string,priority:int} */
    public function effectivePrice(int $variantId, float $basePrice, array $ctx): array
    {
        return PriceResolver::resolve($basePrice, $this->candidatesFor($variantId), $ctx);
    }

    /** @return list<array<string,mixed>> special overlays (offer/deal/flash/base lists with an item) */
    public function specials(int $variantId): array
    {
        return Database::connect()->table('price_list_items pli')
            ->select('pli.id AS item_id, pl.id AS list_id, pl.name, pl.kind, pl.channel, pl.customer_group_id, pl.priority, pl.valid_from, pl.valid_to, pl.status, pli.price, cg.name AS group_name')
            ->join('price_lists pl', 'pl.id = pli.price_list_id', 'left')
            ->join('customer_groups cg', 'cg.id = pl.customer_group_id', 'left')
            ->where('pli.variant_id', $variantId)->where('pli.deleted_at', null)->where('pl.deleted_at', null)
            ->orderBy('pl.priority', 'DESC')->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    public function tiers(int $variantId): array
    {
        return Database::connect()->table('price_tiers')
            ->select('id, min_qty, price')->where('variant_id', $variantId)->orderBy('min_qty')->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    public function groupPrices(int $variantId): array
    {
        return Database::connect()->table('customer_group_prices cgp')
            ->select('cgp.id, cgp.customer_group_id, cgp.price, cg.name AS group_name')
            ->join('customer_groups cg', 'cg.id = cgp.customer_group_id', 'left')
            ->where('cgp.variant_id', $variantId)->where('cgp.deleted_at', null)->orderBy('cg.name')
            ->get()->getResultArray();
    }

    /** Create an overlay price list (offer/deal/flash) + its item. @param array<string,mixed> $d @return int list id */
    public function addSpecial(int $variantId, array $d, ?int $actorId = null): int
    {
        $db   = Database::connect();
        $kind = in_array($d['kind'] ?? '', ['base', 'offer', 'deal', 'flash'], true) ? $d['kind'] : 'offer';
        $chan = in_array($d['channel'] ?? '', ['online', 'pos', 'all'], true) ? $d['channel'] : 'all';
        $db->table('price_lists')->insert([
            'uuid' => $this->uuid(), 'name' => mb_substr((string) ($d['name'] ?? ucfirst($kind)), 0, 120) ?: ucfirst($kind),
            'kind' => $kind, 'channel' => $chan,
            'customer_group_id' => ($d['customer_group_id'] ?? null) ?: null, 'shop_id' => ($d['shop_id'] ?? null) ?: null,
            'is_gst_inclusive' => 1, 'priority' => (int) ($d['priority'] ?? 10),
            'valid_from' => ($d['valid_from'] ?? '') ?: date('Y-m-d H:i:s'), 'valid_to' => ($d['valid_to'] ?? '') ?: null,
            'status' => 'active', 'created_by' => $actorId,
        ]);
        $listId = (int) $db->insertID();
        $db->table('price_list_items')->insert([
            'uuid' => $this->uuid(), 'price_list_id' => $listId, 'variant_id' => $variantId,
            'price' => (float) ($d['price'] ?? 0), 'created_by' => $actorId,
        ]);

        return $listId;
    }

    /** Add a qty tier (attaches to a per-variant tier list, created on demand). */
    public function addTier(int $variantId, float $minQty, float $price, ?int $actorId = null): bool
    {
        if ($minQty < 1 || $price < 0) {
            return false;
        }
        $db   = Database::connect();
        $name = 'tiers-v' . $variantId;
        $list = $db->table('price_lists')->select('id')->where('name', $name)->where('kind', 'base')->where('deleted_at', null)->get()->getRowArray();
        if ($list === null) {
            $db->table('price_lists')->insert(['uuid' => $this->uuid(), 'name' => $name, 'kind' => 'base', 'channel' => 'all', 'is_gst_inclusive' => 1, 'priority' => 1, 'status' => 'active', 'created_by' => $actorId]);
            $listId = (int) $db->insertID();
        } else {
            $listId = (int) $list['id'];
        }

        return (bool) $db->table('price_tiers')->insert(['price_list_id' => $listId, 'variant_id' => $variantId, 'min_qty' => $minQty, 'price' => $price, 'created_by' => $actorId]);
    }

    /** Upsert a customer-group price. */
    public function setGroupPrice(int $variantId, int $groupId, float $price, ?int $actorId = null): bool
    {
        if ($groupId <= 0 || $price < 0) {
            return false;
        }
        $db  = Database::connect();
        $row = $db->table('customer_group_prices')->select('id')->where('variant_id', $variantId)->where('customer_group_id', $groupId)->where('deleted_at', null)->get()->getRowArray();
        if ($row !== null) {
            return $db->table('customer_group_prices')->where('id', (int) $row['id'])->update(['price' => $price, 'updated_by' => $actorId]);
        }

        return (bool) $db->table('customer_group_prices')->insert(['variant_id' => $variantId, 'customer_group_id' => $groupId, 'price' => $price, 'created_by' => $actorId]);
    }

    public function deleteSpecial(int $listId): bool
    {
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('price_list_items')->where('price_list_id', $listId)->update(['deleted_at' => $now]);

        return $db->table('price_lists')->where('id', $listId)->update(['deleted_at' => $now, 'status' => 'expired']);
    }

    public function deleteTier(int $tierId): bool
    {
        return (bool) Database::connect()->table('price_tiers')->where('id', $tierId)->delete();
    }

    /** @return list<array<string,mixed>> */
    public function customerGroups(): array
    {
        return Database::connect()->table('customer_groups')->select('id, code, name')->where('status', 'active')->where('deleted_at', null)->orderBy('name')->get()->getResultArray();
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
