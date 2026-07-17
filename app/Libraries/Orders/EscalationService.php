<?php

declare(strict_types=1);

namespace App\Libraries\Orders;

use Config\Database;

/**
 * EscalationService — scans unclaimed confirmed sub-orders and escalates them
 * through shop → vendor → admin if no one acts within the configured thresholds.
 *
 * Called by `php spark orders:escalate` (run every minute via cron).
 */
final class EscalationService
{
    /** Minutes before shop-level order is escalated to vendor. */
    private const SHOP_TIMEOUT_MIN   = 5;
    /** Minutes before vendor-level order is escalated to admin. */
    private const VENDOR_TIMEOUT_MIN = 5;
    /** Interval between repeat reminders (minutes). */
    private const REMINDER_INTERVAL  = 2;
    /** Maximum reminders before giving up on reminders (still escalates). */
    private const MAX_REMINDERS      = 5;

    public function escalatePending(): void
    {
        $db = Database::connect();

        // First, release any claim whose heartbeat lapsed (handler walked away) so
        // the order returns to the queue and can escalate / be re-claimed.
        try {
            service('orderClaimService')->expireStale();
        } catch (\Throwable) {
        }

        // Find all confirmed sub-orders with no valid (unexpired) claim
        $orders = $db->query(
            "SELECT so.id, so.vendor_id, so.shop_id, so.sub_order_no,
                    so.escalation_level, so.escalation_notified_at, so.escalation_reminder_count,
                    so.priority_level, so.created_at, o.order_no,
                    TIMESTAMPDIFF(MINUTE, so.created_at, NOW())             AS age_minutes,
                    TIMESTAMPDIFF(MINUTE, so.escalation_notified_at, NOW()) AS since_notified_min,
                    TIMESTAMPDIFF(SECOND, NOW(), so.accept_deadline_at)     AS grace_seconds
             FROM sub_orders so
             JOIN orders o ON o.id = so.order_id
             WHERE so.status = 'confirmed'
               AND so.deleted_at IS NULL
               AND (so.claimed_by_role IS NULL OR so.claim_expires_at < NOW())"
        )->getResultArray();

        foreach ($orders as $order) {
            try {
                $this->processOrder($order);
            } catch (\Throwable $e) {
                log_message('error', 'orders:escalate failed for sub-order {id}: {msg}', ['id' => $order['id'] ?? '?', 'msg' => $e->getMessage()]);
            }
        }
    }

    /** @param array<string,mixed> $order */
    private function processOrder(array $order): void
    {
        // An admin hand-back (Admin\OrderController::returnToShop) sets a fresh
        // accept_deadline_at grace window so a long-pending order doesn't instantly
        // re-escalate on raw age. While that window is open, leave it with the shop.
        // (grace_seconds is NULL for normal orders — accept_deadline_at unset.)
        if ($order['grace_seconds'] !== null && (int) $order['grace_seconds'] > 0) {
            return;
        }

        $level = (string) $order['escalation_level'];
        // Age + time-since-notified are computed in SQL (TIMESTAMPDIFF vs NOW()) so the
        // math is immune to any PHP<->MySQL timezone offset (PHP runs UTC, MySQL may not).
        $ageMinutes       = (float) ($order['age_minutes'] ?? 0);
        $sinceNotifiedMin = $order['since_notified_min'] !== null ? (float) $order['since_notified_min'] : $ageMinutes;

        match ($level) {
            'shop'   => $this->handleShopLevel($order, $ageMinutes, $sinceNotifiedMin),
            'vendor' => $this->handleVendorLevel($order, $sinceNotifiedMin),
            'admin'  => $this->handleAdminLevel($order, $sinceNotifiedMin),
            default  => null,
        };
    }

    /** @param array<string,mixed> $order */
    private function handleShopLevel(array $order, float $ageMinutes, float $sinceNotifiedMin): void
    {
        if ($ageMinutes >= self::SHOP_TIMEOUT_MIN) {
            // Escalate to vendor
            $this->escalate($order, 'vendor');

            return;
        }

        // Reminder at T+2 min (and every REMINDER_INTERVAL thereafter up to MAX_REMINDERS)
        if ($ageMinutes >= self::REMINDER_INTERVAL && $sinceNotifiedMin >= self::REMINDER_INTERVAL && $this->underReminderCap($order)) {
            $this->sendShopReminder($order);
        }
    }

    /** @param array<string,mixed> $order */
    private function handleVendorLevel(array $order, float $sinceNotifiedMin): void
    {
        if ($sinceNotifiedMin >= self::VENDOR_TIMEOUT_MIN) {
            $this->escalate($order, 'admin');

            return;
        }

        if ($sinceNotifiedMin >= self::REMINDER_INTERVAL && $this->underReminderCap($order)) {
            $this->sendVendorReminder($order);
        }
    }

    /** @param array<string,mixed> $order */
    private function handleAdminLevel(array $order, float $sinceNotifiedMin): void
    {
        // Repeat urgent notifications to admin every REMINDER_INTERVAL, capped at
        // MAX_REMINDERS so an unclaimed order can't spam admins forever.
        if ($sinceNotifiedMin >= self::REMINDER_INTERVAL && $this->underReminderCap($order)) {
            $this->sendAdminUrgent($order);
        }
    }

    /** @param array<string,mixed> $order */
    private function underReminderCap(array $order): bool
    {
        return (int) ($order['escalation_reminder_count'] ?? 0) < self::MAX_REMINDERS;
    }

    /** @param array<string,mixed> $order */
    private function escalate(array $order, string $toLevel): void
    {
        $db = Database::connect();
        $db->table('sub_orders')
            ->where('id', $order['id'])
            ->set('escalation_notified_at', 'NOW()', false) // MySQL-tz, matches TIMESTAMPDIFF reads
            ->update([
                'escalation_level'          => $toLevel,
                'escalation_reminder_count' => 0, // fresh reminder budget at the new level
                'priority_level'            => $toLevel === 'admin' ? 3 : (int) $order['priority_level'],
            ]);

        service('orderClaimService')->log(
            (int) $order['id'],
            'escalated',
            (string) $order['escalation_level'],
            null,
            $toLevel,
            null,
            'Auto-escalated due to inactivity timeout'
        );

        if ($toLevel === 'vendor') {
            $this->notifyVendorUsers((int) $order['vendor_id'], $order, 'order.escalated_to_vendor');
        } elseif ($toLevel === 'admin') {
            $this->notifyAdminUsers($order, 'order.escalated_to_admin');
        }
    }

    /** @param array<string,mixed> $order */
    private function sendShopReminder(array $order): void
    {
        $this->updateNotifiedAt((int) $order['id']);
        $shopUsers = $this->shopUsers((int) $order['shop_id']);
        foreach ($shopUsers as $userId) {
            $this->notify($userId, 'order.reminder_shop', $order);
        }
    }

    /** @param array<string,mixed> $order */
    private function sendVendorReminder(array $order): void
    {
        $this->updateNotifiedAt((int) $order['id']);
        $this->notifyVendorUsers((int) $order['vendor_id'], $order, 'order.reminder_vendor');
    }

    /** @param array<string,mixed> $order */
    private function sendAdminUrgent(array $order): void
    {
        $this->updateNotifiedAt((int) $order['id']);
        $this->notifyAdminUsers($order, 'order.urgent_admin');
    }

    private function updateNotifiedAt(int $subOrderId): void
    {
        Database::connect()->table('sub_orders')
            ->where('id', $subOrderId)
            ->set('escalation_reminder_count', 'escalation_reminder_count + 1', false)
            ->set('escalation_notified_at', 'NOW()', false)
            ->update();
    }

    /** @param array<string,mixed> $order */
    private function notifyVendorUsers(int $vendorId, array $order, string $eventCode): void
    {
        $db      = Database::connect();
        $vendor  = $db->table('vendors')->select('owner_user_id')->where('id', $vendorId)->get()->getRowArray();
        $userIds = [];
        if ($vendor && $vendor['owner_user_id']) {
            $userIds[] = (int) $vendor['owner_user_id'];
        }
        // Also notify active vendor staff managers
        $staff = $db->table('vendor_staff vs')
            ->select('vs.user_id')
            ->where('vs.vendor_id', $vendorId)->whereIn('vs.staff_type', ['manager', 'branch_manager'])->where('vs.status', 'active')
            ->get()->getResultArray();
        foreach ($staff as $s) {
            $userIds[] = (int) $s['user_id'];
        }

        foreach (array_unique($userIds) as $uid) {
            $this->notify($uid, $eventCode, $order);
        }
    }

    /** @param array<string,mixed> $order */
    private function notifyAdminUsers(array $order, string $eventCode): void
    {
        // Platform (admin) users are users.principal_type = 'platform'.
        $admins = Database::connect()->table('users')
            ->select('id')->where('principal_type', 'platform')->where('status', 'active')
            ->where('deleted_at', null)->get()->getResultArray();
        foreach ($admins as $a) {
            $this->notify((int) $a['id'], $eventCode, $order);
        }
    }

    /**
     * @return list<int>
     */
    private function shopUsers(int $shopId): array
    {
        $rows = Database::connect()->table('vendor_staff vs')
            ->select('vs.user_id')
            ->join('staff_shop_assignments ssa', "ssa.vendor_staff_id = vs.id AND ssa.status = 'active' AND ssa.deleted_at IS NULL", 'inner')
            ->where('ssa.shop_id', $shopId)->where('vs.status', 'active')
            ->get()->getResultArray();

        return array_map(static fn ($r) => (int) $r['user_id'], $rows);
    }

    /** @param array<string,mixed> $order */
    private function notify(int $userId, string $eventCode, array $order): void
    {
        try {
            service('notificationService')->notify($userId, $eventCode, [
                'order_no'     => (string) ($order['order_no'] ?? ''),
                'sub_order_no' => (string) ($order['sub_order_no'] ?? ''),
            ]);
        } catch (\Throwable) {
        }
    }
}
