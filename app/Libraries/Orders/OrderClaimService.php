<?php

declare(strict_types=1);

namespace App\Libraries\Orders;

use Config\Database;

/**
 * OrderClaimService — mutual-exclusion ownership for sub-orders.
 *
 * Rules:
 *  - Only one user (not just role) may hold the claim at a time.
 *  - Claim is acquired on first status change; page views never claim.
 *  - Escalation hierarchy is one-way (shop → vendor → admin); lower levels
 *    cannot reclaim once the order has escalated past them.
 *  - Expired claims (claim_expires_at < NOW()) are treated as unclaimed.
 */
final class OrderClaimService
{
    // Claim lifetime. The order-detail page heartbeats every 60s; a 3-minute TTL
    // survives one missed/slow ping yet releases the claim ~2-3 min after a user
    // walks away (closes the tab), matching the spec's inactivity-release intent.
    private const TTL_MINUTES = 3;

    /**
     * Attempt to claim a sub-order for the given role + user.
     *
     * @return array{ok:bool, reason?:string, owner?:array<string,mixed>, escalation_level?:string}
     */
    public function claim(int $subOrderId, string $role, int $userId, string $ip = '', string $ua = ''): array
    {
        $db = Database::connect();

        // Atomic: only succeed if unclaimed / expired AND escalation allows this role
        $db->query(
            "UPDATE sub_orders
             SET    claimed_by_role    = ?,
                    claimed_by_user_id = ?,
                    claimed_at         = NOW(),
                    claim_expires_at   = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                    claim_heartbeat_at = NOW()
             WHERE  id = ?
               AND  deleted_at IS NULL
               AND  (claimed_by_role IS NULL OR claim_expires_at < NOW() OR claimed_by_user_id = ?)
               AND  (
                     (escalation_level = 'shop'   AND ? IN ('shop','vendor','admin'))
                  OR (escalation_level = 'vendor' AND ? IN ('vendor','admin'))
                  OR (escalation_level = 'admin'  AND ? = 'admin')
                   )",
            [$role, $userId, self::TTL_MINUTES, $subOrderId, $userId, $role, $role, $role]
        );

        if ($db->affectedRows() === 1) {
            $this->log($subOrderId, 'claimed', null, null, $role, $userId, null, $ip, $ua);

            return ['ok' => true];
        }

        // Determine why it failed
        $row = $db->table('sub_orders')
            ->select('claimed_by_role, claimed_by_user_id, claimed_at, claim_expires_at, escalation_level, u.name AS handler_name, TIMESTAMPDIFF(SECOND, NOW(), sub_orders.claim_expires_at) AS expires_in_seconds', false)
            ->join('users u', 'u.id = sub_orders.claimed_by_user_id', 'left')
            ->where('sub_orders.id', $subOrderId)
            ->get()->getRowArray();

        if ($row === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        // Escalation blocked (order moved past this role's level)
        if (
            ($row['escalation_level'] === 'vendor' && $role === 'shop') ||
            ($row['escalation_level'] === 'admin'  && in_array($role, ['shop', 'vendor'], true))
        ) {
            return [
                'ok'               => false,
                'reason'           => 'escalation_blocked',
                'escalation_level' => $row['escalation_level'],
            ];
        }

        // Another user holds the claim. The countdown is computed in SQL (TIMESTAMPDIFF
        // vs NOW()) so it's immune to the PHP(UTC)<->MySQL timezone offset.
        $expiresIn = max(0, (int) ($row['expires_in_seconds'] ?? 0));

        return [
            'ok'     => false,
            'reason' => 'locked',
            'owner'  => [
                'role'             => $row['claimed_by_role'],
                'user_id'          => (int) $row['claimed_by_user_id'],
                'user_name'        => $row['handler_name'] ?? 'Unknown',
                'label'            => $this->roleLabel((string) $row['claimed_by_role']),
                'claimed_at'       => $row['claimed_at'],
                'expires_in_seconds' => $expiresIn,
            ],
        ];
    }

    /**
     * Refresh the claim TTL (heartbeat — called every 60 s while page is open).
     * Only updates if the caller still holds the claim.
     */
    public function heartbeat(int $subOrderId, int $userId): bool
    {
        $db = Database::connect();
        $db->query(
            "UPDATE sub_orders
             SET claim_expires_at   = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                 claim_heartbeat_at = NOW()
             WHERE id = ? AND claimed_by_user_id = ? AND claim_expires_at > NOW()",
            [self::TTL_MINUTES, $subOrderId, $userId]
        );

        return $db->affectedRows() === 1;
    }

    /**
     * Release ownership voluntarily — only if the caller holds the claim.
     */
    public function release(int $subOrderId, string $role, int $userId, string $ip = '', string $ua = ''): void
    {
        $db = Database::connect();
        $db->query(
            "UPDATE sub_orders
             SET claimed_by_role = NULL, claimed_by_user_id = NULL,
                 claimed_at = NULL, claim_expires_at = NULL, claim_heartbeat_at = NULL
             WHERE id = ? AND claimed_by_user_id = ? AND claimed_by_role = ?",
            [$subOrderId, $userId, $role]
        );

        if ($db->affectedRows() === 1) {
            $this->log($subOrderId, 'released', $role, $userId, null, null, null, $ip, $ua);
        }
    }

    /**
     * Release every claim whose TTL has lapsed (the handler walked away — the
     * heartbeat stopped). Clears the claim columns and logs an 'expired' event so
     * the order returns to the queue. Called each minute by the escalation cron.
     *
     * @return int number of claims expired
     */
    public function expireStale(): int
    {
        $db = Database::connect();

        $stale = $db->table('sub_orders')
            ->select('id, claimed_by_role, claimed_by_user_id')
            ->where('claimed_by_role IS NOT NULL', null, false)
            ->where('claim_expires_at IS NOT NULL', null, false)
            ->where('claim_expires_at < NOW()', null, false)
            ->get()->getResultArray();

        if ($stale === []) {
            return 0;
        }

        $db->query(
            "UPDATE sub_orders
             SET claimed_by_role = NULL, claimed_by_user_id = NULL,
                 claimed_at = NULL, claim_expires_at = NULL, claim_heartbeat_at = NULL
             WHERE claimed_by_role IS NOT NULL AND claim_expires_at < NOW()"
        );

        foreach ($stale as $row) {
            $this->log(
                (int) $row['id'],
                'expired',
                $row['claimed_by_role'] ?? null,
                isset($row['claimed_by_user_id']) ? (int) $row['claimed_by_user_id'] : null,
                null,
                null,
                'claim TTL lapsed (no heartbeat)'
            );
        }

        return count($stale);
    }

    /**
     * Admin force-release — clears any claim regardless of who holds it.
     */
    public function forceRelease(int $subOrderId, int $adminUserId, string $reason = '', string $ip = '', string $ua = ''): void
    {
        $db = Database::connect();

        $current = $db->table('sub_orders')
            ->select('claimed_by_role, claimed_by_user_id')
            ->where('id', $subOrderId)->get()->getRowArray();

        $db->query(
            "UPDATE sub_orders
             SET claimed_by_role = NULL, claimed_by_user_id = NULL,
                 claimed_at = NULL, claim_expires_at = NULL, claim_heartbeat_at = NULL
             WHERE id = ?",
            [$subOrderId]
        );

        $this->log(
            $subOrderId,
            'force_released',
            $current['claimed_by_role'] ?? null,
            isset($current['claimed_by_user_id']) ? (int) $current['claimed_by_user_id'] : null,
            'admin',
            $adminUserId,
            $reason,
            $ip,
            $ua
        );
    }

    /**
     * Fetch current claim info (null if unclaimed or expired).
     *
     * @return array<string,mixed>|null
     */
    public function owner(int $subOrderId): ?array
    {
        $row = Database::connect()->table('sub_orders so')
            ->select('so.claimed_by_role, so.claimed_by_user_id, so.claimed_at, so.claim_expires_at, so.escalation_level, u.name AS handler_name, TIMESTAMPDIFF(SECOND, NOW(), so.claim_expires_at) AS expires_in_seconds', false)
            ->join('users u', 'u.id = so.claimed_by_user_id', 'left')
            ->where('so.id', $subOrderId)
            ->where('so.claimed_by_role IS NOT NULL', null, false)
            ->where('so.claim_expires_at > NOW()', null, false)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function isOwner(int $subOrderId, int $userId): bool
    {
        $row = Database::connect()->table('sub_orders')
            ->select('claimed_by_user_id')
            ->where('id', $subOrderId)
            ->where('claimed_by_user_id', $userId)
            ->where('claim_expires_at > NOW()', null, false)
            ->get()->getRowArray();

        return $row !== null;
    }

    public function roleLabel(string $role): string
    {
        return match ($role) {
            'admin'  => 'Admin',
            'vendor' => 'Vendor',
            'shop'   => 'Shop',
            default  => ucfirst($role),
        };
    }

    /**
     * Write to sub_order_claim_logs. Non-fatal — swallows DB errors.
     */
    public function log(
        int $subOrderId,
        string $event,
        ?string $fromRole,
        ?int $fromUserId,
        ?string $toRole,
        ?int $toUserId,
        ?string $reason,
        string $ip = '',
        string $ua = ''
    ): void {
        try {
            Database::connect()->table('sub_order_claim_logs')->insert([
                'sub_order_id' => $subOrderId,
                'event'        => $event,
                'from_role'    => $fromRole,
                'from_user_id' => $fromUserId,
                'to_role'      => $toRole,
                'to_user_id'   => $toUserId,
                'reason'       => $reason !== '' ? $reason : null,
                'ip_address'   => $ip !== '' ? $ip : null,
                'user_agent'   => $ua !== '' ? substr($ua, 0, 1000) : null,
            ]);
        } catch (\Throwable) {
        }
    }
}
