<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Commission\CommissionLedgerStore;
use Config\Database;

/**
 * CommissionLedgerRepository — persistence for the commission hold engine
 * (X2a). Also serves the settlement run (accrued sums, mark-settled) and the
 * admin/vendor hold queues.
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §8
 */
final class CommissionLedgerRepository implements CommissionLedgerStore
{
    public function existsForSubOrder(int $subOrderId): bool
    {
        return Database::connect()->table('commission_ledger')
            ->where('sub_order_id', $subOrderId)->countAllResults() > 0;
    }

    public function insertRows(array $rows): void
    {
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $r) {
            $db->table('commission_ledger')->insert($r + ['uuid' => $this->uuid(), 'created_at' => $now]);
        }
    }

    public function rowsForSubOrder(int $subOrderId, ?array $orderItemIds = null): array
    {
        $b = Database::connect()->table('commission_ledger')->where('sub_order_id', $subOrderId);
        if ($orderItemIds !== null && $orderItemIds !== []) {
            $b->whereIn('order_item_id', $orderItemIds);
        }

        return $b->get()->getResultArray();
    }

    public function setStatus(array $ids, string $status, ?string $reason = null): void
    {
        if ($ids === []) {
            return;
        }
        $fields = ['status' => $status];
        if ($reason !== null) {
            $fields['held_reason'] = mb_substr($reason, 0, 120);
        }
        Database::connect()->table('commission_ledger')->whereIn('id', $ids)->update($fields);
    }

    public function promoteDue(string $nowSql): int
    {
        $db = Database::connect();
        $db->table('commission_ledger')
            ->where('status', 'on_hold')
            ->where('window_ends_at IS NOT NULL', null, false)
            ->where('window_ends_at <=', $nowSql)
            ->update(['status' => 'accrued']);

        return $db->affectedRows();
    }

    public function subOrderWithItems(int $subOrderId): ?array
    {
        $db  = Database::connect();
        $sub = $db->table('sub_orders')->where('id', $subOrderId)->where('deleted_at', null)->get()->getRowArray();
        if ($sub === null) {
            return null;
        }
        $items = $db->table('order_items')->select('id, line_total')
            ->where('sub_order_id', $subOrderId)->get()->getResultArray();

        return ['sub' => $sub, 'items' => $items];
    }

    // ---- settlement support ------------------------------------------------

    /** Accrued commission per sub-order. @param list<int> $subOrderIds @return array<int,string> */
    public function accruedSumsForSubOrders(array $subOrderIds): array
    {
        if ($subOrderIds === []) {
            return [];
        }
        $rows = Database::connect()->table('commission_ledger')
            ->select('sub_order_id, SUM(commission_amount) AS total')
            ->whereIn('sub_order_id', $subOrderIds)->where('status', 'accrued')
            ->groupBy('sub_order_id')->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['sub_order_id']] = number_format((float) $r['total'], 2, '.', '');
        }

        return $out;
    }

    /** Mark a settlement's accrued rows as settled. @param list<int> $subOrderIds */
    public function markSettled(array $subOrderIds, int $settlementId): int
    {
        if ($subOrderIds === []) {
            return 0;
        }
        $db = Database::connect();
        $db->table('commission_ledger')
            ->whereIn('sub_order_id', $subOrderIds)->where('status', 'accrued')
            ->update(['status' => 'settled', 'settled_in_id' => $settlementId]);

        return $db->affectedRows();
    }

    // ---- hold-queue views (admin + vendor lenses) ---------------------------

    /** @return list<array<string,mixed>> on-hold/cancelled summary rows */
    public function holdQueue(?int $vendorId = null, string $status = 'on_hold'): array
    {
        $b = Database::connect()->table('commission_ledger cl')
            ->select('cl.sub_order_id, cl.vendor_id, v.display_name AS vendor, so.sub_order_no, '
                . 'SUM(cl.commission_amount) AS commission, MIN(cl.window_ends_at) AS window_ends_at, '
                . 'COUNT(*) AS line_count, cl.status')
            ->join('vendors v', 'v.id = cl.vendor_id', 'left')
            ->join('sub_orders so', 'so.id = cl.sub_order_id', 'left')
            ->where('cl.status', $status)
            ->groupBy('cl.sub_order_id, cl.vendor_id, v.display_name, so.sub_order_no, cl.status')
            ->orderBy('window_ends_at', 'ASC')->limit(300);
        if ($vendorId !== null) {
            $b->where('cl.vendor_id', $vendorId);
        }

        return $b->get()->getResultArray();
    }

    /** @return array<string,string> totals per status for dashboards */
    public function totalsByStatus(?int $vendorId = null): array
    {
        $b = Database::connect()->table('commission_ledger')
            ->select("status, SUM(commission_amount) AS total")->groupBy('status');
        if ($vendorId !== null) {
            $b->where('vendor_id', $vendorId);
        }
        $out = [];
        foreach ($b->get()->getResultArray() as $r) {
            $out[(string) $r['status']] = number_format((float) $r['total'], 2, '.', '');
        }

        return $out;
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0F | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
