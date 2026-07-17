<?php

declare(strict_types=1);

namespace App\Libraries\Inventory;

use App\Libraries\Workflow\StatusMachine;
use Config\Database;
use Throwable;

/**
 * TransferService — the inter-store stock-transfer workflow engine. Orchestrates
 * the lifecycle (request -> approve[reserve] -> pack -> dispatch[ship] ->
 * receive[partial] -> close, + reject/cancel) and delegates ALL stock movement
 * to InventoryService (reserve / transfer_out / transfer_in), so there is one
 * source of truth for inventory. Every transition is guarded by
 * StatusMachine::canTransfer, audited, and best-effort notified. Tenant-scoped:
 * a transfer only moves between two shops of the same vendor.
 *
 * @see App\Libraries\Inventory\InventoryService, App\Libraries\Workflow\StatusMachine
 */
final class TransferService
{
    /** Create a transfer request (both shops + variant must belong to the vendor). @return array{ok:bool,message:string,id:int|null} */
    public function request(int $vendorId, int $fromShop, int $toShop, int $variantId, float $qty, string $reason = '', ?int $actorId = null): array
    {
        $db = Database::connect();
        if ($fromShop === $toShop || $qty <= 0) {
            return ['ok' => false, 'message' => 'Pick two different shops and a positive quantity.', 'id' => null];
        }
        if (! $this->ownsShop($fromShop, $vendorId) || ! $this->ownsShop($toShop, $vendorId) || ! $this->ownsVariant($variantId, $vendorId)) {
            return ['ok' => false, 'message' => 'Shops and product must belong to your stores.', 'id' => null];
        }
        $db->table('stock_transfers')->insert([
            'uuid' => bin2hex(random_bytes(16)), 'transfer_no' => 'TR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)),
            'vendor_id' => $vendorId, 'from_shop_id' => $fromShop, 'to_shop_id' => $toShop, 'variant_id' => $variantId,
            'qty' => $qty, 'reason' => mb_substr($reason, 0, 255) ?: null, 'status' => 'requested',
            'requested_by' => $actorId, 'created_by' => $actorId,
        ]);
        $id = (int) $db->insertID();
        $this->audit('transfer.request', $id, $actorId, null, 'requested');
        $this->notifyShop($fromShop, 'transfer.requested', ['transfer_id' => $id]);

        return ['ok' => true, 'message' => 'Transfer requested.', 'id' => $id];
    }

    /** Approve a request and reserve the stock at the source. */
    public function approve(int $id, int $vendorId, ?int $actorId = null): array
    {
        $t = $this->find($id, $vendorId);
        if ($t === null || ! StatusMachine::canTransfer($t['status'], 'approved')) {
            return ['ok' => false, 'message' => "This transfer can't be approved from its current state."];
        }
        // orderId=null: a transfer is not a customer order (stock_reservations.order_id FKs orders)
        if (! service('inventoryService')->reserve((int) $t['variant_id'], (int) $t['from_shop_id'], (float) $t['qty'], null, $actorId)) {
            return ['ok' => false, 'message' => 'Not enough available stock at the source store to reserve.'];
        }
        $this->set($id, ['status' => 'approved', 'approved_by' => $actorId, 'decided_at' => date('Y-m-d H:i:s')]);
        $this->audit('transfer.approve', $id, $actorId, $t['status'], 'approved');
        $this->notifyShop((int) $t['to_shop_id'], 'transfer.approved', ['transfer_id' => $id]);

        return ['ok' => true, 'message' => 'Approved — stock reserved at the source store.'];
    }

    public function reject(int $id, int $vendorId, ?int $actorId = null, ?string $reason = null): array
    {
        return $this->simple($id, $vendorId, 'rejected', $actorId, 'Transfer rejected.', 'transfer.reject', $reason, fn () => true, 'to_shop_id', 'transfer.rejected');
    }

    public function pack(int $id, int $vendorId, ?int $actorId = null): array
    {
        return $this->simple($id, $vendorId, 'packed', $actorId, 'Marked packed.', 'transfer.pack', null, fn () => true);
    }

    /** Ship the goods: source on_hand − qty, release the reservation. */
    public function dispatch(int $id, int $vendorId, ?int $actorId = null): array
    {
        $t = $this->find($id, $vendorId);
        if ($t === null || ! StatusMachine::canTransfer($t['status'], 'dispatched')) {
            return ['ok' => false, 'message' => "This transfer can't be dispatched now."];
        }
        if (! service('inventoryService')->transferOut((int) $t['variant_id'], (int) $t['from_shop_id'], (float) $t['qty'], true, $id, $actorId)) {
            return ['ok' => false, 'message' => 'Could not ship the stock out of the source store.'];
        }
        $this->set($id, ['status' => 'dispatched', 'qty_dispatched' => $t['qty'], 'dispatched_by' => $actorId, 'dispatched_at' => date('Y-m-d H:i:s')]);
        $this->audit('transfer.dispatch', $id, $actorId, $t['status'], 'dispatched');
        $this->notifyShop((int) $t['to_shop_id'], 'transfer.dispatched', ['transfer_id' => $id]);

        return ['ok' => true, 'message' => 'Dispatched — in transit to the destination store.'];
    }

    /** Receive at the destination; partial when less than dispatched arrives. */
    public function receive(int $id, int $vendorId, float $qtyReceived, ?int $actorId = null): array
    {
        $t = $this->find($id, $vendorId);
        if ($t === null || ! StatusMachine::canTransfer($t['status'], 'received')) {
            return ['ok' => false, 'message' => "This transfer can't be received now."];
        }
        $dispatched = (float) $t['qty_dispatched'];
        $qtyReceived = max(0.0, min($qtyReceived, $dispatched));
        if ($qtyReceived <= 0) {
            return ['ok' => false, 'message' => 'Enter a received quantity.'];
        }
        if (! service('inventoryService')->transferIn((int) $t['variant_id'], (int) $t['to_shop_id'], $qtyReceived, 0.0, $id, $actorId)) {
            return ['ok' => false, 'message' => 'Could not book the stock into the destination store.'];
        }
        $status = $qtyReceived >= $dispatched ? 'received' : 'partially_received';
        $this->set($id, ['status' => $status, 'qty_received' => $qtyReceived, 'received_by' => $actorId, 'received_at' => date('Y-m-d H:i:s')]);
        $this->audit('transfer.receive', $id, $actorId, $t['status'], $status);
        $this->notifyShop((int) $t['from_shop_id'], 'transfer.received', ['transfer_id' => $id]);

        return ['ok' => true, 'message' => $status === 'received' ? 'Received in full.' : 'Partially received — shortfall logged.'];
    }

    public function close(int $id, int $vendorId, ?int $actorId = null): array
    {
        return $this->simple($id, $vendorId, 'closed', $actorId, 'Transfer closed.', 'transfer.close', null, fn () => true);
    }

    /** Cancel before dispatch; releases the source reservation if one was held. */
    public function cancel(int $id, int $vendorId, ?int $actorId = null): array
    {
        $t = $this->find($id, $vendorId);
        if ($t === null || ! StatusMachine::canTransfer($t['status'], 'cancelled')) {
            return ['ok' => false, 'message' => "This transfer can't be cancelled now."];
        }
        if (in_array($t['status'], ['approved', 'packed'], true)) {
            service('inventoryService')->release((int) $t['variant_id'], (int) $t['from_shop_id'], (float) $t['qty'], null, $actorId);
        }
        $this->set($id, ['status' => 'cancelled']);
        $this->audit('transfer.cancel', $id, $actorId, $t['status'], 'cancelled');

        return ['ok' => true, 'message' => 'Transfer cancelled.'];
    }

    // ---- internals ----------------------------------------------------------

    /** Generic guarded transition for the no-stock-movement steps. */
    private function simple(int $id, int $vendorId, string $to, ?int $actorId, string $okMsg, string $action, ?string $reason, callable $fn, ?string $notifyShopCol = null, ?string $event = null): array
    {
        $t = $this->find($id, $vendorId);
        if ($t === null || ! StatusMachine::canTransfer($t['status'], $to)) {
            return ['ok' => false, 'message' => "That action isn't allowed from the current state."];
        }
        $fn();
        $patch = ['status' => $to];
        if ($reason !== null && $reason !== '') {
            $patch['reason'] = mb_substr($reason, 0, 255);
        }
        $this->set($id, $patch);
        $this->audit($action, $id, $actorId, $t['status'], $to);
        if ($notifyShopCol !== null && $event !== null) {
            $this->notifyShop((int) $t[$notifyShopCol], $event, ['transfer_id' => $id]);
        }

        return ['ok' => true, 'message' => $okMsg];
    }

    /** @return array<string,mixed>|null vendor-scoped */
    private function find(int $id, int $vendorId): ?array
    {
        return Database::connect()->table('stock_transfers')->where('id', $id)->where('vendor_id', $vendorId)->where('deleted_at', null)->get()->getRowArray() ?: null;
    }

    /** @param array<string,mixed> $patch */
    private function set(int $id, array $patch): void
    {
        Database::connect()->table('stock_transfers')->where('id', $id)->update($patch);
    }

    private function ownsShop(int $shopId, int $vendorId): bool
    {
        return (bool) Database::connect()->table('shops')->where('id', $shopId)->where('vendor_id', $vendorId)->countAllResults();
    }

    private function ownsVariant(int $variantId, int $vendorId): bool
    {
        return (bool) Database::connect()->table('product_variants')->where('id', $variantId)->where('vendor_id', $vendorId)->countAllResults();
    }

    private function audit(string $action, int $id, ?int $actorId, ?string $from, string $to): void
    {
        try {
            Database::connect()->table('audit_logs')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'actor_user_id' => $actorId, 'action' => $action,
                'entity_type' => 'stock_transfer', 'entity_id' => $id,
                'ip' => service('request')->getIPAddress(), 'result' => 'success',
                'row_hash' => hash('sha256', $action . '|' . $id . '|' . $from . '>' . $to . '|' . date('c')),
            ]);
        } catch (Throwable) {
        }
    }

    /** Best-effort: notify the shop's vendor owner. Silently no-ops if unconfigured. */
    private function notifyShop(int $shopId, string $eventCode, array $vars): void
    {
        try {
            $row = Database::connect()->table('shops s')->select('v.owner_user_id')
                ->join('vendors v', 'v.id = s.vendor_id', 'left')->where('s.id', $shopId)->get()->getRowArray();
            $uid = $row['owner_user_id'] ?? null;
            if ($uid) {
                service('notificationService')->notify((int) $uid, $eventCode, $vars);
            }
        } catch (Throwable) {
        }
    }
}
