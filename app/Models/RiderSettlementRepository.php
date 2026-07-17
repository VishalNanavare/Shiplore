<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * RiderSettlementRepository — Phase 4 rider earnings payout. Accrued payout
 * entries (rider_payout_entries) are grouped per rider into a batch of rider
 * payouts; an admin then marks the batch paid (manual model, no live banking
 * gateway — same as vendor payouts). Net paid out is the rider's earnings; the
 * cash (COD) the rider holds is reconciled separately and shown for context only.
 *
 * @see App\Models\PayoutRepository (the vendor analogue)
 */
final class RiderSettlementRepository
{
    /** @return list<array<string,mixed>> */
    public function listBatches(int $limit = 100): array
    {
        return Database::connect()->table('rider_payout_batches')
            ->select('id, period_start, period_end, total_amount, rider_count, status, created_at')
            ->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function batch(int $id): ?array
    {
        $row = Database::connect()->table('rider_payout_batches')->where('id', $id)->get()->getRowArray();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function payoutsForBatch(int $batchId): array
    {
        return Database::connect()->table('rider_payouts rp')
            ->select('rp.id, rp.rider_user_id, rp.entries_count, rp.gross_amount, rp.cod_outstanding, rp.net_amount, rp.status, rp.bank_ref, rp.paid_at, u.name AS rider')
            ->join('users u', 'u.id = rp.rider_user_id', 'left')
            ->where('rp.batch_id', $batchId)->orderBy('rp.id', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Riders with accrued (un-batched) earnings ready to pay out.
     * @return list<array<string,mixed>>
     */
    public function eligibleRiders(): array
    {
        return Database::connect()->table('rider_payout_entries rpe')
            ->select('rpe.rider_user_id')
            ->select('MAX(u.name) AS rider, COUNT(*) AS entries, COALESCE(SUM(rpe.amount),0) AS gross', false)
            ->join('users u', 'u.id = rpe.rider_user_id', 'left')
            ->where('rpe.status', 'accrued')
            ->groupBy('rpe.rider_user_id')
            ->orderBy('gross', 'DESC')->get()->getResultArray();
    }

    /**
     * Create a payout batch from all accrued entries, grouped per rider. The
     * entries are parked as 'batched' (linked to the batch) until paid.
     * @return array{ok:bool,batch_id?:int,count?:int,total?:float,reason?:string}
     */
    public function createBatch(?int $actorId = null): array
    {
        $db       = Database::connect();
        $eligible = $this->eligibleRiders();
        $payable  = array_values(array_filter($eligible, static fn ($r) => (float) $r['gross'] > 0));
        if ($payable === []) {
            return ['ok' => false, 'reason' => 'No rider has accrued earnings awaiting payout.'];
        }
        $cod   = service('codCollectionRepository');
        $total = array_sum(array_map(static fn ($r) => (float) $r['gross'], $payable));
        $db->transBegin();
        try {
            $db->table('rider_payout_batches')->insert([
                'uuid' => $this->uuid(), 'total_amount' => round($total, 2), 'rider_count' => count($payable),
                'status' => 'created', 'created_by' => $actorId, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $batchId = (int) $db->insertID();
            foreach ($payable as $r) {
                $uid   = (int) $r['rider_user_id'];
                $gross = (float) $r['gross'];
                $db->table('rider_payouts')->insert([
                    'uuid' => $this->uuid(), 'batch_id' => $batchId, 'rider_user_id' => $uid,
                    'entries_count' => (int) $r['entries'], 'gross_amount' => round($gross, 2),
                    'cod_outstanding' => round($cod->outstandingForRider($uid), 2), 'net_amount' => round($gross, 2),
                    'status' => 'pending', 'created_by' => $actorId, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $db->table('rider_payout_entries')->where('rider_user_id', $uid)->where('status', 'accrued')
                    ->update(['status' => 'batched', 'batch_id' => $batchId]);
            }
            $db->transComplete();

            return $db->transStatus()
                ? ['ok' => true, 'batch_id' => $batchId, 'count' => count($payable), 'total' => round($total, 2)]
                : ['ok' => false, 'reason' => 'Database error creating the batch.'];
        } catch (Throwable $e) {
            $db->transRollback();

            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /** Mark a batch paid → rider_payouts 'paid', their entries 'paid', batch 'completed'. */
    public function markBatchPaid(int $batchId, ?int $actorId = null): bool
    {
        $batch = $this->batch($batchId);
        if ($batch === null || $batch['status'] === 'completed') {
            return false;
        }
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->transBegin();
        $db->table('rider_payouts')->where('batch_id', $batchId)->where('status', 'pending')
            ->update(['status' => 'paid', 'paid_at' => $now, 'updated_by' => $actorId, 'updated_at' => $now]);
        $db->table('rider_payout_entries')->where('batch_id', $batchId)->where('status', 'batched')
            ->update(['status' => 'paid']);
        $db->table('rider_payout_batches')->where('id', $batchId)
            ->update(['status' => 'completed', 'updated_by' => $actorId, 'updated_at' => $now]);
        $db->transComplete();

        return $db->transStatus();
    }

    /** Mark a batch failed → release its entries back to 'accrued' so they re-batch. */
    public function markBatchFailed(int $batchId, ?int $actorId = null): bool
    {
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->transBegin();
        $db->table('rider_payouts')->where('batch_id', $batchId)->where('status', 'pending')
            ->update(['status' => 'failed', 'updated_by' => $actorId, 'updated_at' => $now]);
        $db->table('rider_payout_entries')->where('batch_id', $batchId)->where('status', 'batched')
            ->update(['status' => 'accrued', 'batch_id' => null]);
        $db->table('rider_payout_batches')->where('id', $batchId)
            ->update(['status' => 'failed', 'updated_by' => $actorId, 'updated_at' => $now]);
        $db->transComplete();

        return $db->transStatus();
    }

    /** Mark a single rider payout paid within a batch. */
    public function markRiderPaid(int $payoutId, ?int $actorId = null): bool
    {
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $row = $db->table('rider_payouts')->select('batch_id, rider_user_id, status')->where('id', $payoutId)->get()->getRowArray();
        if ($row === null || $row['status'] !== 'pending') {
            return false;
        }
        $db->transBegin();
        $db->table('rider_payouts')->where('id', $payoutId)->update(['status' => 'paid', 'paid_at' => $now, 'updated_by' => $actorId, 'updated_at' => $now]);
        $db->table('rider_payout_entries')->where('batch_id', (int) $row['batch_id'])->where('rider_user_id', (int) $row['rider_user_id'])->where('status', 'batched')
            ->update(['status' => 'paid']);
        // close the batch once nothing is left pending
        $pending = $db->table('rider_payouts')->where('batch_id', (int) $row['batch_id'])->where('status', 'pending')->countAllResults();
        if ($pending === 0) {
            $db->table('rider_payout_batches')->where('id', (int) $row['batch_id'])->update(['status' => 'completed', 'updated_at' => $now]);
        } else {
            $db->table('rider_payout_batches')->where('id', (int) $row['batch_id'])->update(['status' => 'partial', 'updated_at' => $now]);
        }
        $db->transComplete();

        return $db->transStatus();
    }

    /**
     * Period statement for one rider: earnings (by entry status), delivery count,
     * and the COD cash position.
     * @return array<string,mixed>
     */
    public function statement(int $riderUserId, string $from, string $to): array
    {
        $db   = Database::connect();
        $fromT = $from . ' 00:00:00';
        $toT   = $to . ' 23:59:59';

        $earn = $db->table('rider_payout_entries')
            ->select('COUNT(*) AS deliveries', false)
            ->select('COALESCE(SUM(amount),0) AS earnings', false)
            ->select("COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) AS paid", false)
            ->select("COALESCE(SUM(CASE WHEN status IN ('accrued','batched') THEN amount ELSE 0 END),0) AS unpaid", false)
            ->where('rider_user_id', $riderUserId)->where('created_at >=', $fromT)->where('created_at <=', $toT)
            ->get()->getRowArray();

        $lines = $db->table('rider_payout_entries rpe')
            ->select('rpe.delivery_id, rpe.model, rpe.order_value, rpe.distance_km, rpe.amount, rpe.status, rpe.created_at, o.order_no')
            ->join('deliveries d', 'd.id = rpe.delivery_id', 'left')
            ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
            ->join('orders o', 'o.id = so.order_id', 'left')
            ->where('rpe.rider_user_id', $riderUserId)->where('rpe.created_at >=', $fromT)->where('rpe.created_at <=', $toT)
            ->orderBy('rpe.id', 'DESC')->get()->getResultArray();

        return [
            'from'       => $from,
            'to'         => $to,
            'deliveries' => (int) ($earn['deliveries'] ?? 0),
            'earnings'   => number_format((float) ($earn['earnings'] ?? 0), 2, '.', ''),
            'paid'       => number_format((float) ($earn['paid'] ?? 0), 2, '.', ''),
            'unpaid'     => number_format((float) ($earn['unpaid'] ?? 0), 2, '.', ''),
            'cod'        => service('codCollectionRepository')->totalsForRider($riderUserId),
            'lines'      => $lines,
        ];
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
