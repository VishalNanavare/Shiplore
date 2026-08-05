<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * VendorTransferRepository — stock transfers between a vendor's own shops.
 * Lists the vendor's transfers and creates a transfer request (validating that
 * both shops AND the variant belong to the vendor — tenant isolation).
 */
final class VendorTransferRepository
{
    /** @return list<array<string,mixed>> all of the vendor's transfers, newest first */
    public function list(int $vendorId, int $limit = 30, int $offset = 0): array
    {
        return Database::connect()->table('stock_transfers t')
            ->select('t.id, t.transfer_no, t.qty, t.qty_dispatched, t.qty_received, t.status, t.from_shop_id, t.to_shop_id, t.reason, t.created_at, fs.name AS from_shop, ts.name AS to_shop, pv.sku, p.title')
            ->join('shops fs', 'fs.id = t.from_shop_id', 'left')
            ->join('shops ts', 'ts.id = t.to_shop_id', 'left')
            ->join('product_variants pv', 'pv.id = t.variant_id', 'left')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->where('t.vendor_id', $vendorId)->where('t.deleted_at', null)
            ->orderBy('t.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->getResultArray();
    }

    public function count(int $vendorId): int
    {
        return Database::connect()->table('stock_transfers t')
            ->where('t.vendor_id', $vendorId)->where('t.deleted_at', null)
            ->countAllResults();
    }

    /**
     * A single transfer, vendor-scoped — used by the controller to check shop
     * access (from_shop_id / to_shop_id) before letting non-owner staff act on it.
     * @return array<string,mixed>|null
     */
    public function findOne(int $id, int $vendorId): ?array
    {
        return Database::connect()->table('stock_transfers')
            ->select('id, from_shop_id, to_shop_id, status')
            ->where('id', $id)->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->get()->getRowArray() ?: null;
    }

    /**
     * Cross-store stock visibility: where (in the vendor's stores) is a product
     * in stock? Matches by SKU / title / barcode and returns each shop with
     * available > 0, so a store manager can request a transfer from a store that
     * has it. @return list<array<string,mixed>>
     */
    public function searchStock(int $vendorId, string $q, int $limit = 100): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $db = Database::connect();
        $variantIds = array_column($db->table('product_barcodes')->select('variant_id')->where('status', 'active')->where('deleted_at', null)->like('barcode_value', strtoupper($q))->get()->getResultArray(), 'variant_id');

        $b = $db->table('inventory i')
            ->select('i.shop_id, s.name AS shop, i.available, i.on_hand, i.reserved, pv.id AS variant_id, pv.sku, p.title')
            ->join('product_variants pv', 'pv.id = i.variant_id', 'left')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->join('shops s', 's.id = i.shop_id', 'left')
            ->where('pv.vendor_id', $vendorId)->where('s.vendor_id', $vendorId)
            ->where('pv.deleted_at', null)->where('s.deleted_at', null)->where('i.available >', 0);

        $b->groupStart()->like('pv.sku', $q)->orLike('p.title', $q);
        if ($variantIds !== []) {
            $b->orWhereIn('pv.id', array_map('intval', $variantIds));
        }
        $b->groupEnd();

        return $b->orderBy('p.title')->orderBy('i.available', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> Own shops (for the request form). */
    public function shops(int $vendorId): array
    {
        return Database::connect()->table('shops')
            ->select('id, name')->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->orderBy('name', 'ASC')->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> Own variants (for the request form). */
    public function variants(int $vendorId): array
    {
        return Database::connect()->table('product_variants pv')
            ->select('pv.id, pv.sku, p.title')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->where('pv.vendor_id', $vendorId)->where('pv.deleted_at', null)
            ->orderBy('p.title', 'ASC')->limit(500)->get()->getResultArray();
    }

    private function ownsShop(int $shopId, int $vendorId): bool
    {
        return (bool) Database::connect()->table('shops')->where('id', $shopId)->where('vendor_id', $vendorId)->countAllResults();
    }

    private function ownsVariant(int $variantId, int $vendorId): bool
    {
        return (bool) Database::connect()->table('product_variants')->where('id', $variantId)->where('vendor_id', $vendorId)->countAllResults();
    }

    /** Create a transfer request; returns false unless all parts belong to the vendor. */
    public function createRequest(int $vendorId, int $fromShop, int $toShop, int $variantId, float $qty, ?int $actorId = null): bool
    {
        if ($fromShop === $toShop || $qty <= 0) {
            return false;
        }
        if (! $this->ownsShop($fromShop, $vendorId) || ! $this->ownsShop($toShop, $vendorId) || ! $this->ownsVariant($variantId, $vendorId)) {
            return false;
        }

        return (bool) Database::connect()->table('stock_transfers')->insert([
            'uuid'         => bin2hex(random_bytes(16)),
            'from_shop_id' => $fromShop,
            'to_shop_id'   => $toShop,
            'variant_id'   => $variantId,
            'qty'          => $qty,
            'status'       => 'requested',
            'created_by'   => $actorId,
        ]);
    }
}
