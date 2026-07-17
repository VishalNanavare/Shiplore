<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * DeviceTokenRepository — stores FCM device tokens for push notifications.
 * Upserts by fcm_token (unique key) so each physical device only has one row
 * regardless of re-install or user change.
 */
final class DeviceTokenRepository
{
    /**
     * Register or refresh a device token for a user.
     * Idempotent: updates last_seen_at and re-activates stale/invalid tokens.
     */
    public function upsert(int $userId, string $fcmToken, string $platform, ?string $appVersion): void
    {
        try {
            $db  = Database::connect();
            $row = $db->table('device_tokens')->where('fcm_token', $fcmToken)->get()->getRowArray();

            if ($row !== null) {
                $db->table('device_tokens')->where('fcm_token', $fcmToken)->update([
                    'user_id'      => $userId,
                    'platform'     => $platform,
                    'app_version'  => $appVersion,
                    'last_seen_at' => date('Y-m-d H:i:s'),
                    'status'       => 'active',
                    'deleted_at'   => null,
                ]);
            } else {
                $db->table('device_tokens')->insert([
                    'user_id'      => $userId,
                    'platform'     => $platform,
                    'fcm_token'    => $fcmToken,
                    'app_version'  => $appVersion,
                    'last_seen_at' => date('Y-m-d H:i:s'),
                    'status'       => 'active',
                ]);
            }
        } catch (Throwable) {
            // Never break the caller's flow
        }
    }

    /**
     * Return the active FCM token strings for a user (all devices).
     * @return list<string>
     */
    public function activeForUser(int $userId): array
    {
        try {
            $rows = Database::connect()->table('device_tokens')
                ->select('fcm_token')
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->where('deleted_at', null)
                ->get()->getResultArray();

            return array_column($rows, 'fcm_token');
        } catch (Throwable) {
            return [];
        }
    }

    public function markInvalid(string $fcmToken): void
    {
        try {
            Database::connect()->table('device_tokens')
                ->where('fcm_token', $fcmToken)
                ->update(['status' => 'invalid']);
        } catch (Throwable) {
        }
    }

    public function markStale(string $fcmToken): void
    {
        try {
            Database::connect()->table('device_tokens')
                ->where('fcm_token', $fcmToken)
                ->update(['status' => 'stale']);
        } catch (Throwable) {
        }
    }
}
