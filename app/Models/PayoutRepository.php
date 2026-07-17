<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * PayoutRepository — turns approved vendor settlements into payout batches +
 * per-vendor payout rows, and tracks their status. There is no live banking
 * gateway, so a batch is created as 'created' with 'pending' payouts and an
 * admin marks it paid/failed (the same manual model as settlement "mark paid").
 * Marking a batch paid also flips its settlements to 'paid'.
 */
final class PayoutRepository
{
    /** @return list<array<string,mixed>> */
    public function listBatches(int $limit = 100): array
    {
        return Database::connect()->table('payout_batches')
            ->select('id, gateway, total_amount, count, status, created_at')
            ->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function batch(int $id): ?array
    {
        $row = Database::connect()->table('payout_batches')->where('id', $id)->get()->getRowArray();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function payoutsForBatch(int $batchId): array
    {
        return Database::connect()->table('payouts p')
            ->select('p.id, p.amount, p.status, p.gateway_ref, p.settlement_id, v.display_name AS vendor, s.period_start, s.period_end')
            ->join('settlements s', 's.id = p.settlement_id', 'left')
            ->join('vendors v', 'v.id = s.vendor_id', 'left')
            ->where('p.payout_batch_id', $batchId)
            ->orderBy('p.id', 'ASC')->get()->getResultArray();
    }

    /**
     * Approved settlements with a positive net and no payout yet, joined to the
     * vendor's default bank account (bank_account_id is null if none on file).
     * @return list<array<string,mixed>>
     */
    public function eligibleSettlements(): array
    {
        return Database::connect()->table('settlements s')
            ->select('s.id, s.net_payable, s.vendor_id, v.display_name AS vendor, s.period_start, s.period_end, ba.id AS bank_account_id, ba.account_last4')
            ->join('vendors v', 'v.id = s.vendor_id', 'left')
            ->join('vendor_bank_accounts ba', 'ba.vendor_id = s.vendor_id AND ba.is_default = 1 AND ba.deleted_at IS NULL', 'left')
            ->where('s.status', 'approved')->where('s.net_payable >', 0)
            ->where('NOT EXISTS (SELECT 1 FROM payouts p WHERE p.settlement_id = s.id)', null, false)
            ->get()->getResultArray();
    }

    /**
     * Create a payout batch from eligible settlements that have a vendor bank
     * account; vendors without one are skipped (and reported).
     * @return array{ok:bool,batch_id?:int,count?:int,total?:float,skipped?:int,reason?:string}
     */
    public function createBatch(?int $actorId = null): array
    {
        $eligible = $this->eligibleSettlements();
        $payable  = array_values(array_filter($eligible, static fn ($s) => ! empty($s['bank_account_id'])));
        $skipped  = count($eligible) - count($payable);
        if ($payable === []) {
            return ['ok' => false, 'reason' => $eligible === []
                ? 'No approved settlements are awaiting payout.'
                : 'No approved settlement has a vendor bank account on file. Add bank details first.'];
        }
        $total = array_sum(array_map(static fn ($s) => (float) $s['net_payable'], $payable));
        $db    = Database::connect();
        $db->transBegin();
        try {
            $db->table('payout_batches')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'gateway' => 'manual',
                'total_amount' => round($total, 2), 'count' => count($payable), 'status' => 'created', 'created_by' => $actorId,
            ]);
            $batchId = (int) $db->insertID();
            foreach ($payable as $s) {
                $db->table('payouts')->insert([
                    'uuid' => bin2hex(random_bytes(18)), 'settlement_id' => (int) $s['id'], 'payout_batch_id' => $batchId,
                    'vendor_bank_account_id' => (int) $s['bank_account_id'],
                    'idempotency_key' => 'payout-' . $batchId . '-' . (int) $s['id'],
                    'amount' => (float) $s['net_payable'], 'gateway' => 'manual', 'status' => 'pending', 'created_by' => $actorId,
                ]);
            }
            $db->transComplete();

            return $db->transStatus()
                ? ['ok' => true, 'batch_id' => $batchId, 'count' => count($payable), 'total' => round($total, 2), 'skipped' => $skipped]
                : ['ok' => false, 'reason' => 'Database error creating the batch.'];
        } catch (Throwable $e) {
            $db->transRollback();

            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /** Mark a batch paid → payouts 'paid', batch 'completed', and its settlements 'paid'. */
    public function markBatchPaid(int $batchId, ?int $actorId = null): bool
    {
        $db    = Database::connect();
        $batch = $this->batch($batchId);
        if ($batch === null || $batch['status'] === 'completed') {
            return false;
        }
        $db->transBegin();
        $settlementIds = array_map('intval', array_column(
            $db->table('payouts')->select('settlement_id')->where('payout_batch_id', $batchId)->get()->getResultArray(),
            'settlement_id'
        ));
        $db->table('payouts')->where('payout_batch_id', $batchId)->update(['status' => 'paid', 'updated_by' => $actorId]);
        $db->table('payout_batches')->where('id', $batchId)->update(['status' => 'completed', 'updated_by' => $actorId]);
        if ($settlementIds !== []) {
            $db->table('settlements')->whereIn('id', $settlementIds)->update(['status' => 'paid', 'updated_by' => $actorId]);
        }
        $db->transComplete();

        return $db->transStatus();
    }

    public function markBatchFailed(int $batchId, ?int $actorId = null): bool
    {
        $db = Database::connect();
        $db->table('payouts')->where('payout_batch_id', $batchId)->update(['status' => 'failed', 'updated_by' => $actorId]);

        return (bool) $db->table('payout_batches')->where('id', $batchId)->update(['status' => 'failed', 'updated_by' => $actorId]);
    }
}
