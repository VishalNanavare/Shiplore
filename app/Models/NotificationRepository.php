<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * NotificationRepository — read-only oversight of sent/queued notifications.
 *
 * @see docs/architecture/11-NOTIFICATION-ARCHITECTURE.md
 */
final class NotificationRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null, int $limit = 200): array
    {
        $builder = Database::connect()->table('notifications n')
            ->select('n.id, n.event_code, n.title, n.category, n.status, n.read_at, n.created_at, u.name AS recipient')
            ->join('users u', 'u.id = n.user_id', 'left')
            ->where('n.deleted_at', null)
            ->orderBy('n.created_at', 'DESC')
            ->limit($limit);

        if ($status !== null && $status !== '') {
            $builder->where('n.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * Recent notifications addressed to one user — powers the topbar dropdown.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(int $userId, int $limit = 10): array
    {
        return Database::connect()->table('notifications')
            ->select('id, event_code, title, body, category, status, read_at, created_at')
            ->where('user_id', $userId)
            ->where('deleted_at', null)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }
}
