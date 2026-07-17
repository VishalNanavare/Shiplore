<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * VendorNotificationRepository — notifications addressed to the vendor's user.
 * Scoped by user_id.
 */
final class VendorNotificationRepository
{
    /** @return list<array<string,mixed>> */
    public function list(int $userId, int $limit = 50, int $offset = 0): array
    {
        return Database::connect()->table('notifications')
            ->select('id, event_code, title, body, category, data, status, read_at, created_at')
            ->where('user_id', $userId)->where('deleted_at', null)
            ->orderBy('created_at', 'DESC')->limit($limit, $offset)
            ->get()->getResultArray();
    }

    public function count(int $userId): int
    {
        return Database::connect()->table('notifications')
            ->where('user_id', $userId)->where('deleted_at', null)
            ->countAllResults();
    }

    public function markRead(int $id, int $userId): bool
    {
        return Database::connect()->table('notifications')
            ->where('id', $id)->where('user_id', $userId)->where('deleted_at', null)
            ->update(['status' => 'read', 'read_at' => date('Y-m-d H:i:s')]);
    }

    public function markAllRead(int $userId): void
    {
        Database::connect()->table('notifications')
            ->where('user_id', $userId)->where('read_at IS NULL', null, false)->where('deleted_at', null)
            ->update(['status' => 'read', 'read_at' => date('Y-m-d H:i:s')]);
    }

    public function deleteOne(int $id, int $userId): void
    {
        Database::connect()->table('notifications')
            ->where('id', $id)->where('user_id', $userId)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);
    }

    public function deleteAll(int $userId): void
    {
        Database::connect()->table('notifications')
            ->where('user_id', $userId)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);
    }

    /** Return unread ring-type notifications for restoring alert on app relaunch. */
    public function pendingRings(int $userId): array
    {
        return Database::connect()->table('notifications')
            ->select('id AS notification_id, JSON_UNQUOTE(JSON_EXTRACT(data, "$.order_id")) AS order_id, event_code')
            ->where('user_id', $userId)
            ->whereIn('event_code', ['order.new', 'order.cancelled', 'refund.requested'])
            ->where('read_at IS NULL', null, false)
            ->where('deleted_at', null)
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();
    }
}
