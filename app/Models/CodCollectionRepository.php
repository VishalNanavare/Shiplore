<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * CodCollectionRepository — Phase 4 cash-on-delivery reconciliation. Every COD
 * trip the rider closes logs the cash they now hold (status 'collected'); admin
 * finance reconciles that against what the rider actually deposited
 * (collected → deposited → reconciled, or 'short' when the cash is light).
 *
 * Outstanding = cash the rider is still holding (collected/pending/short, not yet
 * deposited or reconciled) — the platform's float exposure per rider.
 */
final class CodCollectionRepository
{
    /** Statuses that still represent un-deposited cash in the rider's hands. */
    private const OUTSTANDING = ['pending', 'collected', 'short'];

    /** Idempotently record the cash a rider collected for a delivery. */
    public function record(int $deliveryId, int $riderUserId, ?int $paymentId, float $amount, ?int $actorId = null): bool
    {
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $existing = $db->table('cod_collections')->select('id')->where('delivery_id', $deliveryId)->get()->getRowArray();
        if ($existing) {
            return (bool) $db->table('cod_collections')->where('id', $existing['id'])
                ->update(['rider_user_id' => $riderUserId, 'amount' => $amount, 'collected_at' => $now, 'status' => 'collected', 'updated_by' => $actorId, 'updated_at' => $now]);
        }

        return (bool) $db->table('cod_collections')->insert([
            'delivery_id' => $deliveryId, 'rider_user_id' => $riderUserId, 'payment_id' => $paymentId,
            'amount' => $amount, 'collected_at' => $now, 'variance' => 0, 'status' => 'collected',
            'created_by' => $actorId, 'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @return list<array<string,mixed>> Per-rider COD position (collected / deposited / outstanding). */
    public function riderSummary(): array
    {
        $out = "'" . implode("','", self::OUTSTANDING) . "'";

        return Database::connect()->table('cod_collections cc')
            ->select('cc.rider_user_id')
            ->select('MAX(u.name) AS rider, MAX(v.display_name) AS vendor', false)
            ->select('COUNT(*) AS trips', false)
            ->select('COALESCE(SUM(cc.amount),0) AS collected', false)
            ->select("COALESCE(SUM(CASE WHEN cc.status IN ('deposited','reconciled') THEN cc.amount ELSE 0 END),0) AS deposited", false)
            ->select("COALESCE(SUM(CASE WHEN cc.status IN ($out) THEN cc.amount ELSE 0 END),0) AS outstanding", false)
            ->select('MAX(cc.collected_at) AS last_collected', false)
            ->join('users u', 'u.id = cc.rider_user_id', 'left')
            ->join('delivery_boys db', 'db.user_id = cc.rider_user_id', 'left')
            ->join('vendors v', 'v.id = db.vendor_id', 'left')
            ->where('cc.rider_user_id IS NOT NULL', null, false)
            ->groupBy('cc.rider_user_id')
            ->orderBy('outstanding', 'DESC')
            ->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> Individual COD lines for one rider. */
    public function forRider(int $riderUserId, ?string $status = null): array
    {
        $b = Database::connect()->table('cod_collections cc')
            ->select('cc.id, cc.delivery_id, cc.amount, cc.status, cc.variance, cc.collected_at, cc.deposited_at, cc.reconciled_at, o.order_no')
            ->join('deliveries d', 'd.id = cc.delivery_id', 'left')
            ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
            ->join('orders o', 'o.id = so.order_id', 'left')
            ->where('cc.rider_user_id', $riderUserId);
        if ($status === 'outstanding') {
            $b->whereIn('cc.status', self::OUTSTANDING);
        } elseif ($status !== null && $status !== '') {
            $b->where('cc.status', $status);
        }

        return $b->orderBy('cc.id', 'DESC')->get()->getResultArray();
    }

    public function outstandingForRider(int $riderUserId): float
    {
        $row = Database::connect()->table('cod_collections')
            ->selectSum('amount')->where('rider_user_id', $riderUserId)->whereIn('status', self::OUTSTANDING)
            ->get()->getRowArray();

        return (float) ($row['amount'] ?? 0);
    }

    /** @return array<string,string> Collected / deposited / outstanding for a rider. */
    public function totalsForRider(int $riderUserId): array
    {
        $out = "'" . implode("','", self::OUTSTANDING) . "'";
        $row = Database::connect()->table('cod_collections')
            ->select('COALESCE(SUM(amount),0) AS collected', false)
            ->select("COALESCE(SUM(CASE WHEN status IN ('deposited','reconciled') THEN amount ELSE 0 END),0) AS deposited", false)
            ->select("COALESCE(SUM(CASE WHEN status IN ($out) THEN amount ELSE 0 END),0) AS outstanding", false)
            ->where('rider_user_id', $riderUserId)->get()->getRowArray();

        return [
            'collected'   => number_format((float) ($row['collected'] ?? 0), 2, '.', ''),
            'deposited'   => number_format((float) ($row['deposited'] ?? 0), 2, '.', ''),
            'outstanding' => number_format((float) ($row['outstanding'] ?? 0), 2, '.', ''),
        ];
    }

    public function markDeposited(int $id, ?int $actorId = null): bool
    {
        $now = date('Y-m-d H:i:s');

        return (bool) Database::connect()->table('cod_collections')->where('id', $id)->whereIn('status', self::OUTSTANDING)
            ->update(['status' => 'deposited', 'deposited_at' => $now, 'updated_by' => $actorId, 'updated_at' => $now]);
    }

    public function markReconciled(int $id, ?int $actorId = null): bool
    {
        $now = date('Y-m-d H:i:s');

        return (bool) Database::connect()->table('cod_collections')->where('id', $id)
            ->update(['status' => 'reconciled', 'reconciled_at' => $now, 'updated_by' => $actorId, 'updated_at' => $now]);
    }

    /** Bulk: mark every outstanding line for a rider as deposited. @return int rows updated */
    public function depositAllForRider(int $riderUserId, ?int $actorId = null): int
    {
        $now = date('Y-m-d H:i:s');
        $db  = Database::connect();
        $db->table('cod_collections')->where('rider_user_id', $riderUserId)->whereIn('status', self::OUTSTANDING)
            ->update(['status' => 'deposited', 'deposited_at' => $now, 'updated_by' => $actorId, 'updated_at' => $now]);

        return $db->affectedRows();
    }
}
