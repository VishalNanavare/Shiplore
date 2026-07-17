<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * RiderPayoutRepository — persistence for the rider payout engine (X5):
 * plan resolution via delivery_boys.payout_plan_id, idempotent entry
 * writes (unique per delivery), and the rider's earnings feed.
 */
final class RiderPayoutRepository
{
    /** @return array<string,mixed>|null active plan for a rider user */
    public function planForRiderUser(int $riderUserId): ?array
    {
        return Database::connect()->table('delivery_boys db')
            ->select('p.*')
            ->join('rider_payout_plans p', 'p.id = db.payout_plan_id')
            ->where('db.user_id', $riderUserId)->where('db.deleted_at', null)
            ->where('p.status', 'active')
            ->get()->getRowArray();
    }

    /** Idempotent insert (unique delivery_id absorbs replays). */
    public function insertEntry(array $entry): bool
    {
        try {
            Database::connect()->table('rider_payout_entries')->insert($entry + [
                'uuid'       => $this->uuid(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (Throwable) {
            return false; // duplicate delivery → already accrued
        }
    }

    /** Delivered count this month (hybrid threshold input). */
    public function monthlyDelivered(int $riderUserId, string $monthStartSql): int
    {
        return Database::connect()->table('deliveries d')
            ->join('delivery_assignments da', 'da.delivery_id = d.id')
            ->where('da.rider_user_id', $riderUserId)
            ->where('d.status', 'delivered')
            ->where('d.updated_at >=', $monthStartSql)
            ->countAllResults();
    }

    /** @return list<array<string,mixed>> rider earnings feed */
    public function entriesForRider(int $riderUserId, int $limit = 100): array
    {
        return Database::connect()->table('rider_payout_entries')
            ->where('rider_user_id', $riderUserId)
            ->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0F | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
