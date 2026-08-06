<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * AuditLogRepository — read-only access to the append-only audit trail (the
 * hash-chained record of who did what). Lists the most recent entries.
 *
 * @see docs/architecture/14-AUDIT-LOG-ARCHITECTURE.md
 */
final class AuditLogRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $action = null, int $limit = 300): array
    {
        $builder = Database::connect()->table('audit_logs a')
            ->select('a.id, a.action, a.entity_type, a.entity_id, a.actor_principal_type, a.result, a.ip, a.created_at, u.name AS actor')
            ->join('users u', 'u.id = a.actor_user_id', 'left')
            ->orderBy('a.id', 'DESC')
            ->limit($limit);

        if ($action !== null && $action !== '') {
            // Prefix match, not a leading-wildcard scan: action codes are dotted
            // prefixes (order., product., monline.), and idx_audit_action can serve
            // 'action%' as a range scan on this append-only, ever-growing table.
            // Narrower than before on purpose — 'login' no longer matches
            // 'auth.login' (audit L8).
            $builder->like('a.action', $action, 'after');
        }

        return $builder->get()->getResultArray();
    }
}
