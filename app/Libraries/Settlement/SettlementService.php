<?php

declare(strict_types=1);

namespace App\Libraries\Settlement;

use Config\Database;
use Throwable;

/**
 * SettlementService — the settlement batch run (Batch 4). Aggregates a vendor's
 * delivered/completed sub-orders in a period into a payable: gross − commission −
 * refunds − fees = net_payable, writing settlements + settlement_lines + a
 * balanced ledger entry. Idempotent per (vendor, period). Pure netPayable() is
 * unit-tested.
 *
 * @see docs/architecture/38-PAYMENTS-REFUNDS-SETTLEMENTS.md
 */
final class SettlementService
{
    private const SETTLEABLE = ['delivered', 'completed'];

    /** Pure: net payable to the vendor. */
    public static function netPayable(float $gross, float $commission, float $refunds, float $fees): string
    {
        return number_format(round($gross - $commission - $refunds - $fees, 2), 2, '.', '');
    }

    /**
     * Run a settlement for a vendor + period. Idempotent.
     * @return array{ok:bool,reason?:string,settlement_id?:int,gross?:string,commission?:string,net?:string,lines?:int,replay?:bool}
     */
    public function run(int $vendorId, string $periodStart, string $periodEnd, ?int $actorId = null): array
    {
        $db  = Database::connect();
        $key = 'settle-' . $vendorId . '-' . $periodStart . '-' . $periodEnd;

        $prior = $db->table('settlements')->where('idempotency_key', $key)->get()->getRowArray();
        if ($prior !== null) {
            return ['ok' => true, 'replay' => true, 'settlement_id' => (int) $prior['id'], 'net' => $prior['net_payable']];
        }

        // settle-able sub-orders not already settled.
        // X2a guard: anything still inside its return window (an on_hold
        // commission row) is NOT settleable yet — the scheduler promotes
        // holds to accrued when the window elapses.
        $subs = $db->table('sub_orders so')
            ->select('so.id, so.grand_total, so.commission_amount')
            ->where('so.vendor_id', $vendorId)->where('so.deleted_at', null)
            ->whereIn('so.status', self::SETTLEABLE)
            ->where('so.created_at >=', $periodStart . ' 00:00:00')->where('so.created_at <=', $periodEnd . ' 23:59:59')
            ->where('so.id NOT IN (SELECT COALESCE(sub_order_id,0) FROM settlement_lines)', null, false)
            ->where("NOT EXISTS (SELECT 1 FROM commission_ledger cl WHERE cl.sub_order_id = so.id AND cl.status = 'on_hold')", null, false)
            ->get()->getResultArray();

        if ($subs === []) {
            return ['ok' => false, 'reason' => 'No settle-able orders in this period.'];
        }

        // Commission per sub-order: the hold ledger is authoritative when rows
        // exist (cancelled/reversed lines reduce it to the accrued remainder);
        // sub_orders.commission_amount stays the legacy fallback.
        $ids         = array_map(static fn (array $s): int => (int) $s['id'], $subs);
        $accruedSums = service('commissionLedgerRepository')->accruedSumsForSubOrders($ids);

        $gross = 0.0; $commission = 0.0;
        foreach ($subs as $s) {
            $gross      += (float) $s['grand_total'];
            $commission += array_key_exists((int) $s['id'], $accruedSums)
                ? (float) $accruedSums[(int) $s['id']]
                : (float) $s['commission_amount'];
        }
        $net = self::netPayable($gross, $commission, 0.0, 0.0);

        $db->transBegin();
        try {
            $db->table('settlements')->insert([
                'uuid' => $this->uuid(), 'vendor_id' => $vendorId, 'period_start' => $periodStart, 'period_end' => $periodEnd,
                'gross' => $this->n($gross), 'commission_total' => $this->n($commission), 'refund_total' => '0.00',
                'tcs' => '0.00', 'tds' => '0.00', 'fees' => '0.00', 'adjustments' => '0.00',
                'net_payable' => $net, 'idempotency_key' => $key, 'status' => 'calculated', 'created_by' => $actorId,
            ]);
            $settlementId = (int) $db->insertID();

            foreach ($subs as $s) {
                $db->table('settlement_lines')->insert([
                    'settlement_id' => $settlementId, 'ref_type' => 'sub_order', 'ref_id' => (int) $s['id'],
                    'sub_order_id' => (int) $s['id'], 'amount' => $this->n((float) $s['grand_total']), 'direction' => 'credit',
                ]);
            }
            $db->table('settlement_lines')->insert([
                'settlement_id' => $settlementId, 'ref_type' => 'commission', 'ref_id' => $vendorId,
                'amount' => $this->n($commission), 'direction' => 'debit',
            ]);

            // balanced ledger: debit SALES (net), credit VENDOR_PAYABLE (net)
            $txn = $this->uuid();
            $this->ledger($db, $txn, 'SALES', 'debit', $net, $settlementId);
            $this->ledger($db, $txn, 'VENDOR_PAYABLE', 'credit', $net, $settlementId);

            // X2a: bind the consumed accrued hold rows to this settlement
            service('commissionLedgerRepository')->markSettled($ids, $settlementId);

            $db->transComplete();
            if (! $db->transStatus()) {
                return ['ok' => false, 'reason' => 'transaction failed'];
            }

            return ['ok' => true, 'settlement_id' => $settlementId, 'gross' => $this->n($gross), 'commission' => $this->n($commission), 'net' => $net, 'lines' => count($subs)];
        } catch (Throwable) {
            $db->transRollback();

            return ['ok' => false, 'reason' => 'processing error'];
        }
    }

    private function ledger($db, string $txn, string $code, string $dir, string $amount, int $refId): void
    {
        $acct = $db->table('ledger_accounts')->select('id')->where('code', $code)->get()->getRowArray();
        if ($acct !== null) {
            $db->table('ledger_entries')->insert([
                'uuid' => $this->uuid(), 'txn_group_uuid' => $txn, 'ledger_account_id' => $acct['id'],
                'direction' => $dir, 'amount' => $amount, 'ref_type' => 'settlement', 'ref_id' => $refId,
                'memo' => 'Settlement #' . $refId, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function n(float $v): string
    {
        return number_format(round($v, 2), 2, '.', '');
    }

    private function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
