<?php

declare(strict_types=1);

namespace App\Libraries\Inventory;

use Config\Database;
use Throwable;

/**
 * ManufacturerInventoryService — stock held at manufacturing units.
 *
 * Forked from InventoryService rather than widening it, because the two work on
 * different tables all the way down: `mfg_inventory` / `mfg_inventory_ledger` /
 * `mfg_stock_batches`, keyed on mshop_id, versus `inventory` / `inventory_ledger` /
 * `stock_batches` keyed on shop_id. Passing an mshop id to the vendor service would
 * write rows that silently point at whatever `shops` row shares that number.
 *
 * TWO COLUMN DIVERGENCES that a copy-paste of InventoryService gets wrong, and which
 * fail quietly rather than loudly:
 *
 *   1. the ledger quantity column is `qty` here, not `qty_delta`;
 *   2. the batch cost column is `making_cost` here, not `cost_price`.
 *
 * As in the vendor service, `available` is a STORED GENERATED column
 * (on_hand − reserved) — only on_hand and reserved are ever written.
 *
 * These three tables shipped in 70_manufacturer.sql and had NO reader or writer
 * anywhere in app/ until this class; manufacturer stock was simply never tracked.
 *
 * @see \App\Libraries\Inventory\InventoryService — the vendor counterpart
 */
final class ManufacturerInventoryService
{
    /** @return array<string,mixed> on_hand/reserved/available/reorder_level (zeros if no row) */
    public function levels(int $variantId, int $mshopId): array
    {
        $row = Database::connect()->table('mfg_inventory')
            ->select('id, on_hand, reserved, available, reorder_level, status')
            ->where('variant_id', $variantId)->where('mshop_id', $mshopId)
            ->get()->getRowArray();

        return $row ?: ['id' => null, 'on_hand' => 0, 'reserved' => 0, 'available' => 0, 'reorder_level' => null, 'status' => 'out_of_stock'];
    }

    /**
     * Book production into a unit: cost batch + on_hand increment + ledger.
     *
     * The movement type is 'production', not 'purchase' — a manufacturer makes its
     * stock rather than buying it, and mfg_inventory_ledger.movement_type has no
     * 'purchase' member at all.
     */
    public function produce(int $variantId, int $mshopId, float $qty, float $makingCost, array $opts = [], ?int $actorId = null): bool
    {
        if ($qty <= 0) {
            return false;
        }

        $db = Database::connect();
        $db->transBegin();

        try {
            $this->ensureRow($variantId, $mshopId, $db);
            $db->table('mfg_stock_batches')->insert([
                'uuid'        => $this->uuid(),
                'variant_id'  => $variantId,
                'mshop_id'    => $mshopId,
                'batch_no'    => ($opts['batch_no'] ?? '') ?: $this->autoBatch(),
                'mfg_date'    => ($opts['mfg_date'] ?? '') ?: null,
                'exp_date'    => ($opts['exp_date'] ?? '') ?: null,
                'qty'         => $qty,
                'making_cost' => $makingCost,
                'status'      => 'active',
                'created_by'  => $actorId,
            ]);
            $batchId = (int) $db->insertID();

            $bal = $this->bump($variantId, $mshopId, $qty, $db);
            $this->postLedger($variantId, $mshopId, 'production', $qty, $bal, 'batch', $batchId, ($opts['note'] ?? 'produced'), $actorId, $db);

            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }

    /** Adjust on-hand by a signed delta with a reason. On-hand is floored at 0. */
    public function adjust(int $variantId, int $mshopId, float $qtyDelta, string $reasonCode, string $notes = '', ?int $actorId = null): bool
    {
        if ($qtyDelta == 0.0) {
            return false;
        }

        $db = Database::connect();
        $db->transBegin();

        try {
            $this->ensureRow($variantId, $mshopId, $db);
            $bal = $this->bump($variantId, $mshopId, $qtyDelta, $db);

            $movement = in_array($reasonCode, ['damage', 'damaged', 'scrap'], true) ? 'write_off'
                : (in_array($reasonCode, ['return', 'returned'], true) ? 'return' : 'adjustment');

            $this->postLedger($variantId, $mshopId, $movement, $qtyDelta, $bal, 'adjustment', null, ($notes !== '' ? $notes : $reasonCode), $actorId, $db);

            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }

    /**
     * Ship stock out against a purchase order. Called when a PO is dispatched, so the
     * manufacturer's own stock finally falls when goods leave the unit — until this
     * existed, a dispatched PO raised the buyer's stock and decremented nobody's.
     *
     * Deliberately allowed to drive on_hand to zero rather than refusing: the goods
     * have physically gone, and refusing here would leave the ledger disagreeing with
     * reality. Under-recorded production shows up as a zero balance, which is the
     * honest answer.
     */
    public function shipForPurchaseOrder(int $variantId, int $mshopId, float $qty, int $poId, ?int $actorId = null): bool
    {
        if ($qty <= 0) {
            return false;
        }

        $db = Database::connect();
        $db->transBegin();

        try {
            $this->ensureRow($variantId, $mshopId, $db);
            $bal = $this->bump($variantId, $mshopId, -$qty, $db);
            $this->postLedger($variantId, $mshopId, 'sale', -$qty, $bal, 'purchase_order', $poId, 'dispatched on PO', $actorId, $db);

            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }

    /** @return list<array<string,mixed>> recent movements, newest first */
    public function ledger(int $variantId, int $mshopId, int $limit = 50): array
    {
        return Database::connect()->table('mfg_inventory_ledger')
            ->select('id, movement_type, qty, balance_after, ref_type, ref_id, note, created_at')
            ->where('variant_id', $variantId)->where('mshop_id', $mshopId)
            ->orderBy('id', 'DESC')->limit($limit)
            ->get()->getResultArray();
    }

    /**
     * Stock across a manufacturer's units, for the grid.
     *
     * @param list<int> $mshopIds
     * @return list<array<string,mixed>>
     */
    public function levelsForUnits(int $manufacturerId, array $mshopIds, int $limit = 500): array
    {
        if ($mshopIds === []) {
            return [];
        }

        return Database::connect()->table('mfg_inventory mi')
            ->select('mi.variant_id, mi.mshop_id, mi.on_hand, mi.reserved, mi.available, mi.reorder_level, mi.status,
                      pv.sku, p.id AS product_id, p.title, m.name AS unit_name')
            ->join('product_variants pv', 'pv.id = mi.variant_id', 'inner')
            ->join('products p', 'p.id = pv.product_id', 'inner')
            ->join('mshops m', 'm.id = mi.mshop_id', 'left')
            // The tenant predicate. mfg_inventory itself is keyed only on mshop_id, so
            // without this a caller could read another manufacturer's stock by passing
            // its unit ids; the controller filters those to allowedMshopIds() as well.
            ->where('p.vendor_id', $manufacturerId)
            ->whereIn('mi.mshop_id', $mshopIds)
            ->orderBy('p.title', 'ASC')->orderBy('pv.sku', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    // ---- internals ---------------------------------------------------------

    private function ensureRow(int $variantId, int $mshopId, object $db): void
    {
        $exists = $db->table('mfg_inventory')
            ->where('variant_id', $variantId)->where('mshop_id', $mshopId)->countAllResults();

        if ($exists === 0) {
            $db->table('mfg_inventory')->insert([
                'uuid'       => $this->uuid(),
                'variant_id' => $variantId,
                'mshop_id'   => $mshopId,
                'on_hand'    => 0,
                'reserved'   => 0,
                'status'     => 'out_of_stock',
            ]);
        }
    }

    /** Apply a signed delta to on_hand (floored at 0) and return the new balance. */
    private function bump(int $variantId, int $mshopId, float $delta, object $db): float
    {
        $row  = $db->table('mfg_inventory')->select('on_hand, reorder_level')
            ->where('variant_id', $variantId)->where('mshop_id', $mshopId)->get()->getRowArray();
        $next = max(0.0, (float) ($row['on_hand'] ?? 0) + $delta);

        $reorder = $row['reorder_level'] ?? null;
        $status  = $next <= 0 ? 'out_of_stock'
            : (($reorder !== null && $next <= (float) $reorder) ? 'low' : 'in_stock');

        $db->table('mfg_inventory')
            ->where('variant_id', $variantId)->where('mshop_id', $mshopId)
            ->update(['on_hand' => $next, 'status' => $status]);

        return $next;
    }

    /** NOTE the column is `qty` here — `qty_delta` is the vendor ledger's name. */
    private function postLedger(int $variantId, int $mshopId, string $movement, float $qty, float $balanceAfter, ?string $refType, ?int $refId, string $note, ?int $actorId, object $db): void
    {
        $db->table('mfg_inventory_ledger')->insert([
            'uuid'          => $this->uuid(),
            'variant_id'    => $variantId,
            'mshop_id'      => $mshopId,
            'movement_type' => $movement,
            'qty'           => $qty,
            'balance_after' => $balanceAfter,
            'ref_type'      => $refType,
            'ref_id'        => $refId,
            'note'          => mb_substr($note, 0, 255),
            'created_by'    => $actorId,
        ]);
    }

    private function autoBatch(): string
    {
        return 'B' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
