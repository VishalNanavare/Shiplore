<?php

declare(strict_types=1);

namespace App\Libraries\Inventory;

use Config\Database;
use Throwable;

/**
 * InventoryService — the stock engine. Every quantity change is atomic and posts
 * an inventory_ledger movement (with running balance_after), so stock is always
 * auditable. Handles receipts (with cost batches for FIFO/LIFO valuation),
 * adjustments (damage/return/manual), and reservations. `inventory.available`
 * is a STORED GENERATED column (on_hand − reserved) — we only set on_hand/reserved.
 *
 * @see App\Libraries\Inventory\StockValuation, docs/architecture/24-ADMIN-PANEL.md
 */
final class InventoryService
{
    /** @return array<string,mixed> on_hand/reserved/available/reorder_level (zeros if no row) */
    public function levels(int $variantId, int $shopId): array
    {
        $row = Database::connect()->table('inventory')
            ->select('id, on_hand, reserved, available, reorder_level, status')
            ->where('variant_id', $variantId)->where('shop_id', $shopId)->get()->getRowArray();

        return $row ?: ['id' => null, 'on_hand' => 0, 'reserved' => 0, 'available' => 0, 'reorder_level' => null, 'status' => 'active'];
    }

    /** Receive stock into a shop: cost batch + on_hand increment + ledger. @return bool */
    public function receive(int $variantId, int $shopId, float $qty, float $cost, array $opts = [], ?int $actorId = null): bool
    {
        if ($qty <= 0) {
            return false;
        }
        $db = Database::connect();
        $db->transBegin();
        try {
            $this->ensureRow($variantId, $shopId, $db);
            $db->table('stock_batches')->insert([
                'variant_id' => $variantId, 'shop_id' => $shopId,
                'batch_no' => ($opts['batch_no'] ?? '') ?: $this->autoBatch(),
                'mfg_date' => ($opts['mfg_date'] ?? '') ?: null, 'expiry_date' => ($opts['expiry_date'] ?? '') ?: null,
                'qty' => $qty, 'cost_price' => $cost, 'status' => 'active', 'created_by' => $actorId,
            ]);
            $batchId = (int) $db->insertID();
            $bal = $this->bump($variantId, $shopId, $qty, $db);
            $this->postLedger($variantId, $shopId, 'purchase', $qty, $bal, 'purchase', $batchId, ($opts['reason'] ?? 'stock_received'), $batchId, $actorId, $db);
            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }

    /**
     * Adjust on-hand by a (signed) delta with a reason. damage/return/manual/sale.
     * On-hand is floored at 0. Writes stock_adjustments + ledger.
     */
    public function adjust(int $variantId, int $shopId, float $qtyDelta, string $reasonCode, string $notes = '', ?int $actorId = null): bool
    {
        if ($qtyDelta == 0.0) {
            return false;
        }
        $db = Database::connect();
        $db->transBegin();
        try {
            $this->ensureRow($variantId, $shopId, $db);
            $bal = $this->bump($variantId, $shopId, $qtyDelta, $db);
            if ($qtyDelta < 0) {
                $this->consumeBatches($variantId, $shopId, abs($qtyDelta), $db); // keep cost layers in sync
            }
            $db->table('stock_adjustments')->insert([
                'variant_id' => $variantId, 'shop_id' => $shopId, 'qty_delta' => $qtyDelta,
                'reason_code' => mb_substr($reasonCode, 0, 40), 'notes' => $notes ?: null,
                'status' => 'approved', 'approved_by' => $actorId, 'created_by' => $actorId,
            ]);
            // map to inventory_ledger.movement_type ENUM
            $movement = in_array($reasonCode, ['damage', 'damaged'], true) ? 'write_off'
                : (in_array($reasonCode, ['return', 'returned'], true) ? 'return' : 'adjustment');
            $this->postLedger($variantId, $shopId, $movement, $qtyDelta, $bal, 'adjustment', (int) $db->insertID(), $reasonCode, null, $actorId, $db);
            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }

    /** Reserve stock for an order (only if enough is available). */
    public function reserve(int $variantId, int $shopId, float $qty, ?int $orderId = null, ?int $actorId = null): bool
    {
        if ($qty <= 0) {
            return false;
        }
        $db = Database::connect();
        $db->transBegin();
        try {
            $this->ensureRow($variantId, $shopId, $db);
            $lvl = $this->levels($variantId, $shopId);
            if ((float) $lvl['available'] < $qty) {
                $db->transRollback();

                return false;
            }
            $db->table('inventory')->where('variant_id', $variantId)->where('shop_id', $shopId)
                ->set('reserved', 'reserved + ' . $qty, false)->update();
            $db->table('stock_reservations')->insert([
                'variant_id' => $variantId, 'shop_id' => $shopId, 'order_id' => $orderId,
                'qty' => $qty, 'status' => 'held',
            ]);
            $lvl = $this->levels($variantId, $shopId);
            $this->postLedger($variantId, $shopId, 'reservation', -$qty, (float) $lvl['available'], 'reservation', $orderId, 'reserve', null, $actorId, $db);
            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }

    /** Release a prior reservation back to available. */
    public function release(int $variantId, int $shopId, float $qty, ?int $orderId = null, ?int $actorId = null): bool
    {
        if ($qty <= 0) {
            return false;
        }
        $db = Database::connect();
        $db->transBegin();
        try {
            $cur = (float) $this->levels($variantId, $shopId)['reserved'];
            $rel = min($cur, $qty);
            $db->table('inventory')->where('variant_id', $variantId)->where('shop_id', $shopId)
                ->set('reserved', 'GREATEST(reserved - ' . $rel . ', 0)', false)->update();
            if ($orderId !== null) {
                $db->table('stock_reservations')->where('variant_id', $variantId)->where('shop_id', $shopId)
                    ->where('order_id', $orderId)->where('status', 'held')->update(['status' => 'released']);
            }
            $lvl = $this->levels($variantId, $shopId);
            $this->postLedger($variantId, $shopId, 'release', $rel, (float) $lvl['available'], 'reservation', $orderId, 'release', null, $actorId, $db);
            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }

    /**
     * Ship stock out of a shop for a transfer: on_hand − qty (FIFO batch
     * consumption), optionally releasing a prior reservation, with a transfer_out
     * ledger entry.
     */
    public function transferOut(int $variantId, int $shopId, float $qty, bool $releaseReserved, ?int $refId = null, ?int $actorId = null): bool
    {
        if ($qty <= 0) {
            return false;
        }
        $db = Database::connect();
        $db->transBegin();
        try {
            $this->ensureRow($variantId, $shopId, $db);
            $bal = $this->bump($variantId, $shopId, -$qty, $db);
            $this->consumeBatches($variantId, $shopId, $qty, $db);
            if ($releaseReserved) {
                $db->table('inventory')->where('variant_id', $variantId)->where('shop_id', $shopId)
                    ->set('reserved', 'GREATEST(reserved - ' . $qty . ', 0)', false)->update();
            }
            $this->postLedger($variantId, $shopId, 'transfer_out', -$qty, $bal, 'transfer', $refId, 'transfer_out', null, $actorId, $db);
            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }

    /** Book transferred stock INTO the destination shop: cost batch + on_hand + transfer_in ledger. */
    public function transferIn(int $variantId, int $shopId, float $qty, float $cost = 0.0, ?int $refId = null, ?int $actorId = null): bool
    {
        if ($qty <= 0) {
            return false;
        }
        $db = Database::connect();
        $db->transBegin();
        try {
            $this->ensureRow($variantId, $shopId, $db);
            $db->table('stock_batches')->insert([
                'variant_id' => $variantId, 'shop_id' => $shopId, 'batch_no' => $this->autoBatch(),
                'qty' => $qty, 'cost_price' => $cost, 'status' => 'active', 'created_by' => $actorId,
            ]);
            $batchId = (int) $db->insertID();
            $bal = $this->bump($variantId, $shopId, $qty, $db);
            $this->postLedger($variantId, $shopId, 'transfer_in', $qty, $bal, 'transfer', $refId, 'transfer_in', $batchId, $actorId, $db);
            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }

    /** Record a sale: on_hand − qty (FIFO batch consumption) + sale/pos_sale ledger. */
    public function sell(int $variantId, int $shopId, float $qty, bool $isPos = true, ?int $refId = null, ?int $actorId = null): bool
    {
        if ($qty <= 0) {
            return false;
        }
        $db = Database::connect();
        $db->transBegin();
        try {
            $this->ensureRow($variantId, $shopId, $db);
            $bal = $this->bump($variantId, $shopId, -$qty, $db);
            $this->consumeBatches($variantId, $shopId, $qty, $db);
            $this->postLedger($variantId, $shopId, $isPos ? 'pos_sale' : 'sale', -$qty, $bal, $isPos ? 'pos_sale' : 'order', $refId, 'sale', null, $actorId, $db);
            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }

    /** Add returned stock back to a shop: on_hand + qty + a return ledger entry. */
    public function returnStock(int $variantId, int $shopId, float $qty, ?int $refId = null, ?int $actorId = null): bool
    {
        if ($qty <= 0) {
            return false;
        }
        $db = Database::connect();
        $db->transBegin();
        try {
            $this->ensureRow($variantId, $shopId, $db);
            $db->table('stock_batches')->insert([
                'variant_id' => $variantId, 'shop_id' => $shopId, 'batch_no' => $this->autoBatch(),
                'qty' => $qty, 'cost_price' => 0, 'status' => 'active', 'created_by' => $actorId,
            ]);
            $bal = $this->bump($variantId, $shopId, $qty, $db);
            $this->postLedger($variantId, $shopId, 'return', $qty, $bal, 'pos_return', $refId, 'return', null, $actorId, $db);
            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }

    /** Valuation over open cost layers (FIFO order) using StockValuation. @return array<string,float> */
    public function valuation(int $variantId, int $shopId, string $method = 'fifo'): array
    {
        $batches = Database::connect()->table('stock_batches')
            ->select('qty, cost_price')->where('variant_id', $variantId)->where('shop_id', $shopId)
            ->where('qty >', 0)->where('status', 'active')->where('deleted_at', null)
            ->orderBy('COALESCE(mfg_date, created_at) ASC, id ASC', '', false)
            ->get()->getResultArray();
        $layers = array_map(static fn ($b) => ['qty' => (float) $b['qty'], 'cost' => (float) $b['cost_price']], $batches);

        return [
            'on_hand_qty'   => StockValuation::onHandQty($layers),
            'on_hand_value' => StockValuation::onHandValue($layers),
            'wavg_cost'     => StockValuation::weightedAverageCost($layers),
            'method'        => $method,
        ];
    }

    /** @return list<array<string,mixed>> recent ledger movements */
    public function ledger(int $variantId, int $shopId, int $limit = 50): array
    {
        return Database::connect()->table('inventory_ledger')
            ->select('movement_type, qty_delta, balance_after, ref_type, reason_code, created_at')
            ->where('variant_id', $variantId)->where('shop_id', $shopId)
            ->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /** Variants at/under reorder level for a vendor. @return list<array<string,mixed>> */
    public function lowStock(int $vendorId, int $limit = 100): array
    {
        return Database::connect()->table('inventory i')
            ->select('i.variant_id, i.shop_id, i.on_hand, i.available, i.reorder_level, pv.sku, p.title, s.name AS shop')
            ->join('product_variants pv', 'pv.id = i.variant_id', 'left')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->join('shops s', 's.id = i.shop_id', 'left')
            ->where('pv.vendor_id', $vendorId)->where('i.reorder_level >', 0)
            ->where('i.available <= i.reorder_level')
            ->orderBy('i.available', 'ASC')->limit($limit)->get()->getResultArray();
    }

    /**
     * Build inventory rows for a product page: per variant, levels at each shop +
     * valuation aggregated across those shops.
     * @param list<array<string,mixed>> $variants  from ProductVariantRepository::listWithValues
     * @param list<array<string,mixed>> $shops     the vendor's shops (id, name)
     * @return list<array<string,mixed>>
     */
    public function snapshotRows(array $variants, array $shops, string $method = 'fifo'): array
    {
        $rows = [];
        foreach ($variants as $v) {
            $vid = (int) $v['id'];
            $levels = [];
            $value = 0.0;
            $qty = 0.0;
            foreach ($shops as $s) {
                $sid = (int) $s['id'];
                $levels[$sid] = $this->levels($vid, $sid);
                $val = $this->valuation($vid, $sid, $method);
                $value += (float) $val['on_hand_value'];
                $qty += (float) $val['on_hand_qty'];
            }
            $rows[] = [
                'v' => $v, 'levels' => $levels,
                'val' => ['method' => $method, 'on_hand_value' => round($value, 4), 'on_hand_qty' => round($qty, 4), 'wavg_cost' => $qty > 0 ? round($value / $qty, 4) : 0.0],
            ];
        }

        return $rows;
    }

    // ---- internals ----------------------------------------------------------

    private function ensureRow(int $variantId, int $shopId, object $db): void
    {
        $exists = $db->table('inventory')->where('variant_id', $variantId)->where('shop_id', $shopId)->countAllResults();
        if (! $exists) {
            $db->table('inventory')->insert([
                'uuid' => $this->uuid(), 'variant_id' => $variantId, 'shop_id' => $shopId,
                'on_hand' => 0, 'reserved' => 0, 'status' => 'active',
            ]);
        }
    }

    /** Consume $qty from the oldest open cost batches (FIFO) so layers track on_hand. */
    private function consumeBatches(int $variantId, int $shopId, float $qty, object $db): void
    {
        $need    = max(0.0, $qty);
        $batches = $db->table('stock_batches')->select('id, qty')
            ->where('variant_id', $variantId)->where('shop_id', $shopId)->where('qty >', 0)
            ->where('status', 'active')->where('deleted_at', null)
            ->orderBy('COALESCE(mfg_date, created_at) ASC, id ASC', '', false)->get()->getResultArray();
        foreach ($batches as $b) {
            if ($need <= 0) {
                break;
            }
            $take = min((float) $b['qty'], $need);
            $db->table('stock_batches')->where('id', (int) $b['id'])->set('qty', 'qty - ' . $take, false)->update();
            $need -= $take;
        }
    }

    /** Apply a signed delta to on_hand (floored at 0) and return the new on_hand. */
    private function bump(int $variantId, int $shopId, float $delta, object $db): float
    {
        $expr = $delta >= 0 ? 'on_hand + ' . $delta : 'GREATEST(on_hand - ' . abs($delta) . ', 0)';
        $db->table('inventory')->where('variant_id', $variantId)->where('shop_id', $shopId)->set('on_hand', $expr, false)->update();

        return (float) ($db->table('inventory')->select('on_hand')->where('variant_id', $variantId)->where('shop_id', $shopId)->get()->getRowArray()['on_hand'] ?? 0);
    }

    private function postLedger(int $variantId, int $shopId, string $movement, float $qtyDelta, float $balanceAfter, string $refType, ?int $refId, string $reasonCode, ?int $batchId, ?int $actorId, object $db): void
    {
        $db->table('inventory_ledger')->insert([
            'uuid' => $this->uuid(), 'variant_id' => $variantId, 'shop_id' => $shopId,
            'movement_type' => $movement, 'qty_delta' => $qtyDelta, 'balance_after' => $balanceAfter,
            'ref_type' => $refType, 'ref_id' => $refId, 'batch_id' => $batchId,
            'reason_code' => mb_substr($reasonCode, 0, 40), 'origin' => 'server', 'created_by' => $actorId,
        ]);
    }

    /** Auto batch number when the user doesn't supply one (stock_batches.batch_no is required). */
    private function autoBatch(): string
    {
        return 'B' . date('ymdHis') . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
