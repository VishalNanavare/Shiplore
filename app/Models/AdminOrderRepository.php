<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * AdminOrderRepository — admin-only order oversight queries that sit alongside
 * the cross-vendor order list (ownership history, claim audit, etc.).
 *
 * @see docs/architecture/24-ADMIN-PANEL.md
 */
final class AdminOrderRepository
{
    /**
     * Ownership / claim audit trail for one sub-order (newest first). Each row
     * resolves an actor name from the to/from user so the admin timeline can
     * label who performed each event.
     *
     * @return list<array<string,mixed>>
     */
    public function claimLogs(int $subOrderId): array
    {
        return Database::connect()->table('sub_order_claim_logs l')
            ->select('l.event, l.from_role, l.to_role, l.from_user_id, l.to_user_id, l.reason, l.created_at, u.name AS actor_name')
            ->join('users u', 'u.id = COALESCE(l.to_user_id, l.from_user_id)', 'left')
            ->where('l.sub_order_id', $subOrderId)
            ->orderBy('l.id', 'DESC')
            ->get()->getResultArray();
    }
}
