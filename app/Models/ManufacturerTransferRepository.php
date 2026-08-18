<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * Stock moving between a manufacturer's own units.
 *
 * The vendor equivalent, stock_transfers, is keyed on shop_id with FKs to shops(id), so
 * an mshop cannot use it — the same block that required mfg_deliveries. Hence the
 * parallel mfg_stock_transfers pair.
 *
 * TWO STEPS, not one. Dispatch decrements the source; receipt credits the destination.
 * A single atomic move would be simpler and wrong: goods on a lorry are at neither end,
 * and a warehouse whose inventory counts them before they arrive disagrees with its own
 * shelves. The gap between the two is exactly the in-transit quantity, and a short
 * receipt records the difference rather than quietly inventing the missing units.
 *
 * Every movement goes through ManufacturerInventoryService so it is ledgered at both
 * ends — a stock change with no ledger row is one nobody can explain later.
 */
final class ManufacturerTransferRepository
{
    /**
     * Draft a transfer. Nothing moves until dispatch.
     *
     * @param list<array{variant_id:int|string,qty:int|float|string}> $lines
     * @return array{ok:bool,id?:int,transfer_no?:string,error?:string}
     */
    public function create(int $manufacturerId, int $fromMshopId, int $toMshopId, array $lines, string $notes = '', ?int $actorId = null): array
    {
        if ($manufacturerId <= 0 || $fromMshopId <= 0 || $toMshopId <= 0) {
            return $this->fail('Pick both a source and a destination.');
        }
        // Enforced here rather than by a CHECK constraint: MariaDB before 10.2.1 parses
        // CHECK and ignores it, and a constraint that silently does nothing is worse
        // than no constraint at all.
        if ($fromMshopId === $toMshopId) {
            return $this->fail('Source and destination must be different units.');
        }
        if (! $this->ownsBoth($manufacturerId, $fromMshopId, $toMshopId)) {
            return $this->fail('Unit not found.');
        }

        $clean = [];

        foreach ($lines as $l) {
            $vid = (int) ($l['variant_id'] ?? 0);
            $qty = (float) ($l['qty'] ?? 0);
            if ($vid > 0 && $qty > 0) {
                $clean[$vid] = ($clean[$vid] ?? 0) + $qty;   // a repeated variant sums
            }
        }
        if ($clean === []) {
            return $this->fail('Add at least one item.');
        }

        $db = Database::connect();
        $db->transBegin();

        try {
            $no = $this->nextNumber($db, $manufacturerId);
            $db->table('mfg_stock_transfers')->insert([
                'uuid' => $this->uuid(), 'transfer_no' => $no, 'vendor_id' => $manufacturerId,
                'from_mshop_id' => $fromMshopId, 'to_mshop_id' => $toMshopId,
                'status' => 'draft', 'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null,
                'created_by' => $actorId,
            ]);
            $id = (int) $db->insertID();

            foreach ($clean as $vid => $qty) {
                $db->table('mfg_stock_transfer_items')->insert([
                    'transfer_id' => $id, 'variant_id' => $vid, 'qty' => number_format($qty, 3, '.', ''),
                ]);
            }

            $db->transComplete();

            return $db->transStatus()
                ? ['ok' => true, 'id' => $id, 'transfer_no' => $no]
                : $this->fail('Could not create the transfer.');
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'mfg transfer create failed: ' . $e->getMessage());

            return $this->fail('Could not create the transfer.');
        }
    }

    /**
     * Send it: decrement the source, ledger it, mark dispatched.
     *
     * Availability is checked for every line BEFORE anything moves, because bump()
     * floors on_hand at zero and never fails — without the pre-check a transfer would
     * report success having moved stock the plant did not have.
     *
     * @return array{ok:bool,error?:string}
     */
    public function dispatch(int $transferId, int $manufacturerId, ?int $actorId = null): array
    {
        $t = $this->findOwned($transferId, $manufacturerId);
        if ($t === null) {
            return $this->fail('Transfer not found.');
        }
        if ($t['status'] !== 'draft') {
            return $this->fail('Only a draft transfer can be dispatched.');
        }

        $items = $this->items($transferId);
        $inv   = service('manufacturerInventoryService');
        $from  = (int) $t['from_mshop_id'];

        foreach ($items as $it) {
            $have = (float) ($inv->levels((int) $it['variant_id'], $from)['on_hand'] ?? 0);
            if ($have + 0.0001 < (float) $it['qty']) {
                return $this->fail('Not enough stock at the source for one or more items.');
            }
        }

        $db = Database::connect();
        $db->transBegin();

        try {
            foreach ($items as $it) {
                if (! $inv->moveOut((int) $it['variant_id'], $from, (float) $it['qty'], $transferId, $actorId, $db)) {
                    throw new \RuntimeException('transfer_out failed');
                }
            }
            $db->table('mfg_stock_transfers')->where('id', $transferId)
                ->update(['status' => 'dispatched', 'dispatched_at' => date('Y-m-d H:i:s')]);

            $db->transComplete();

            return $db->transStatus() ? ['ok' => true] : $this->fail('Could not dispatch.');
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'mfg transfer dispatch failed: ' . $e->getMessage());

            return $this->fail('Could not dispatch.');
        }
    }

    /**
     * Receive it: credit the destination with what ACTUALLY arrived.
     *
     * $received maps variant_id => qty. A variant left out defaults to the dispatched
     * quantity; a smaller number is a short receipt and is stored, so the difference
     * between qty and qty_received is a shrinkage figure rather than a rounding.
     * A LARGER number is refused — more cannot arrive than left.
     *
     * @param array<int,float> $received
     * @return array{ok:bool,error?:string}
     */
    public function receive(int $transferId, int $manufacturerId, array $received = [], ?int $actorId = null): array
    {
        $t = $this->findOwned($transferId, $manufacturerId);
        if ($t === null) {
            return $this->fail('Transfer not found.');
        }
        if ($t['status'] !== 'dispatched') {
            return $this->fail('Only a dispatched transfer can be received.');
        }

        $items = $this->items($transferId);
        $inv   = service('manufacturerInventoryService');
        $to    = (int) $t['to_mshop_id'];

        $db = Database::connect();
        $db->transBegin();

        try {
            foreach ($items as $it) {
                $vid  = (int) $it['variant_id'];
                $sent = (float) $it['qty'];
                $got  = array_key_exists($vid, $received) ? (float) $received[$vid] : $sent;

                if ($got < 0 || $got > $sent + 0.0001) {
                    throw new \RuntimeException('received qty out of range');
                }
                if ($got > 0 && ! $inv->moveIn($vid, $to, $got, $transferId, $actorId, $db)) {
                    throw new \RuntimeException('transfer_in failed');
                }
                $db->table('mfg_stock_transfer_items')->where('id', (int) $it['id'])
                    ->update(['qty_received' => number_format($got, 3, '.', '')]);
            }
            $db->table('mfg_stock_transfers')->where('id', $transferId)
                ->update(['status' => 'received', 'received_at' => date('Y-m-d H:i:s')]);

            $db->transComplete();

            return $db->transStatus() ? ['ok' => true] : $this->fail('Could not receive.');
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'mfg transfer receive failed: ' . $e->getMessage());

            return $this->fail('Could not receive.');
        }
    }

    /** @return list<array<string,mixed>> transfers for this manufacturer, newest first */
    public function list(int $manufacturerId, ?string $status = null, int $limit = 200): array
    {
        if ($manufacturerId <= 0) {
            return [];
        }
        $b = Database::connect()->table('mfg_stock_transfers t')
            ->select('t.*, f.name AS from_name, d.name AS to_name')
            ->join('mshops f', 'f.id = t.from_mshop_id', 'left')
            ->join('mshops d', 'd.id = t.to_mshop_id', 'left')
            ->where('t.vendor_id', $manufacturerId)
            ->where('t.deleted_at', null);

        if ($status !== null && $status !== '') {
            $b->where('t.status', $status);
        }

        return $b->orderBy('t.id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /** @return array<string,mixed>|null the transfer and its lines, tenant-scoped */
    public function find(int $transferId, int $manufacturerId): ?array
    {
        $t = $this->findOwned($transferId, $manufacturerId);
        if ($t === null) {
            return null;
        }
        $t['items'] = Database::connect()->table('mfg_stock_transfer_items i')
            ->select('i.*, pv.sku, p.title')
            ->join('product_variants pv', 'pv.id = i.variant_id', 'left')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->where('i.transfer_id', $transferId)->orderBy('i.id')->get()->getResultArray();

        return $t;
    }

    // ---- internals ---------------------------------------------------------

    /** Both units must belong to this manufacturer — the tenant boundary. */
    private function ownsBoth(int $manufacturerId, int $a, int $b): bool
    {
        return Database::connect()->table('mshops')
            ->whereIn('id', [$a, $b])->where('vendor_id', $manufacturerId)
            ->countAllResults() === 2;
    }

    /** @return array<string,mixed>|null */
    private function findOwned(int $transferId, int $manufacturerId): ?array
    {
        if ($transferId <= 0 || $manufacturerId <= 0) {
            return null;
        }

        return Database::connect()->table('mfg_stock_transfers')
            ->where('id', $transferId)->where('vendor_id', $manufacturerId)->where('deleted_at', null)
            ->get()->getRowArray();
    }

    /** @return list<array<string,mixed>> */
    private function items(int $transferId): array
    {
        return Database::connect()->table('mfg_stock_transfer_items')
            ->where('transfer_id', $transferId)->orderBy('id')->get()->getResultArray();
    }

    /** TR/<fy>/000001 per manufacturer. A counter, never COUNT(*) + 1. */
    private function nextNumber(object $db, int $manufacturerId): string
    {
        $y  = (int) date('Y');
        $fy = (int) date('n') >= 4 ? $y . '-' . substr((string) ($y + 1), 2, 2) : ($y - 1) . '-' . substr((string) $y, 2, 2);

        $row = $db->table('mfg_stock_transfers')->select('transfer_no')
            ->where('vendor_id', $manufacturerId)->like('transfer_no', 'TR/' . $fy . '/', 'after')
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        $last = $row === null ? 0 : (int) substr((string) $row['transfer_no'], -6);

        return 'TR/' . $fy . '/' . str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT);
    }

    /** @return array{ok:false,error:string} */
    private function fail(string $why): array
    {
        return ['ok' => false, 'error' => $why];
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
