<?php

declare(strict_types=1);

namespace App\Libraries\Notify;

use Config\Database;
use Throwable;

/**
 * NotificationService — the unified dispatcher behind Phase 15. On a domain event
 * it renders the message, honours the user's channel preferences, writes an
 * in-app `notifications` row, and queues per-channel `notification_deliveries`
 * (email / SMS / push) onto the Phase 11 job queue for the worker to send via the
 * configured providers (SMTP / Firebase / SMS). Fail-safe: never breaks the
 * caller's flow.
 *
 * @see docs/architecture/39-NOTIFICATIONS.md
 */
final class NotificationService
{
    /** Event catalogue: code => [category, channels, title, body]. DB templates override. */
    private const EVENTS = [
        'registration'      => ['transactional', ['in_app', 'email'], 'Welcome to Shiplore', 'Hi {{name}}, your account is ready.'],
        'verification'      => ['transactional', ['sms', 'email'], 'Verify your contact', 'Your code is {{code}}.'],
        'order_update'      => ['transactional', ['in_app', 'push'], 'Order {{order_no}} {{status}}', 'Your order {{order_no}} is now {{status}}.'],
        'payment_update'    => ['transactional', ['in_app', 'email'], 'Payment {{status}}', 'Payment for {{order_no}} is {{status}}.'],
        'refund_update'     => ['transactional', ['in_app', 'email'], 'Refund {{status}}', 'Your refund for {{order_no}} is {{status}}.'],
        'delivery_update'   => ['transactional', ['in_app', 'push'], 'Delivery {{status}}', 'Delivery for {{order_no}}: {{status}}.'],
        // New order landed at the shop (T+0) — prompt the shop/vendor to accept it.
        'order.new' => ['transactional', ['in_app', 'push'], 'New order {{sub_order_no}}', 'New order {{sub_order_no}} received — please accept it.'],
        // Out for delivery — OTP is NEVER put in the push body; the customer opens the app to read it.
        'order.out_for_delivery' => ['transactional', ['in_app', 'push'], 'Out for delivery', 'Your order {{order_no}} is out for delivery — open the app for your delivery OTP.'],
        'order.otp_regenerated'  => ['transactional', ['in_app', 'push'], 'Delivery OTP updated', 'Your delivery OTP for {{sub_order_no}} was updated — open the app to view it.'],
        // Escalation (EscalationService) — vars: order_no, sub_order_no.
        'order.reminder_shop'       => ['transactional', ['in_app', 'push'], 'Order {{sub_order_no}} waiting', 'Order {{sub_order_no}} is still unhandled — please action it.'],
        'order.reminder_vendor'     => ['transactional', ['in_app', 'push'], 'Order {{sub_order_no}} waiting', 'Order {{sub_order_no}} is still unhandled at the shop — please action it.'],
        'order.escalated_to_vendor' => ['transactional', ['in_app', 'push'], 'Order {{sub_order_no}} escalated', 'Order {{sub_order_no}} was not actioned in time and is now with the vendor.'],
        'order.escalated_to_admin'  => ['transactional', ['in_app', 'push', 'email'], 'Order {{sub_order_no}} escalated to admin', 'Order {{sub_order_no}} was not actioned and has been escalated to admin.'],
        'order.urgent_admin'        => ['transactional', ['in_app', 'push', 'email'], 'URGENT: order {{sub_order_no}}', 'Order {{sub_order_no}} is still unhandled and needs immediate attention.'],
        'promotion'         => ['promotional', ['push', 'email'], '{{title}}', '{{body}}'],
        'stock_alert'       => ['transactional', ['in_app'], 'Low stock: {{product}}', '{{product}} at {{shop}} is low ({{qty}} left).'],
        'admin_alert'       => ['transactional', ['in_app', 'email'], 'Admin: {{title}}', '{{body}}'],
        'vendor_alert'      => ['transactional', ['in_app'], 'Vendor: {{title}}', '{{body}}'],
        'support_alert'     => ['transactional', ['in_app', 'email'], 'Support: {{subject}}', '{{body}}'],
        'sync_failure'      => ['transactional', ['in_app', 'email'], 'Sync failure', '{{entity}} sync failed: {{reason}}.'],
        'approval_update'   => ['transactional', ['in_app', 'email'], '{{kind}} {{decision}}', 'Your {{kind}} was {{decision}}.'],
        // Governance change-requests (ChangeRequestEngine) — vars: request_no, action, status.
        'request.submitted'         => ['transactional', ['in_app'], 'Approval requested', 'Your {{action}} change (request {{request_no}}) was submitted for approval.'],
        'request.resubmitted'       => ['transactional', ['in_app'], 'Request resubmitted', 'Your {{action}} change (request {{request_no}}) was resubmitted for approval.'],
        'request.approved'          => ['transactional', ['in_app'], 'Request approved', 'Your {{action}} change (request {{request_no}}) was approved.'],
        'request.rejected'          => ['transactional', ['in_app'], 'Request rejected', 'Your {{action}} change (request {{request_no}}) was rejected.'],
        'request.changes_requested' => ['transactional', ['in_app'], 'Changes requested', 'Your {{action}} change (request {{request_no}}) needs changes before it can be approved.'],
        'request.withdrawn'         => ['transactional', ['in_app'], 'Request withdrawn', 'Your {{action}} change (request {{request_no}}) was withdrawn.'],
        'request.expired'           => ['transactional', ['in_app'], 'Request expired', 'Your {{action}} change (request {{request_no}}) expired without a decision.'],
        'request.applied'           => ['transactional', ['in_app'], 'Change applied', 'Your approved {{action}} change (request {{request_no}}) has been applied.'],
        'request.apply_failed'      => ['transactional', ['in_app'], 'Change could not be applied', 'Your approved {{action}} change (request {{request_no}}) failed to apply — please retry.'],
        'request.pending'           => ['transactional', ['in_app'], 'Awaiting next approval', 'Request {{request_no}} ({{action}}) is awaiting the next approval level.'],
        // Stock transfers (TransferService) — var: transfer_id.
        'transfer.requested'  => ['transactional', ['in_app'], 'Stock transfer requested', 'Stock transfer #{{transfer_id}} was requested.'],
        'transfer.approved'   => ['transactional', ['in_app'], 'Stock transfer approved', 'Stock transfer #{{transfer_id}} was approved.'],
        'transfer.rejected'   => ['transactional', ['in_app'], 'Stock transfer rejected', 'Stock transfer #{{transfer_id}} was rejected.'],
        'transfer.dispatched' => ['transactional', ['in_app'], 'Stock transfer dispatched', 'Stock transfer #{{transfer_id}} has been dispatched.'],
        'transfer.received'   => ['transactional', ['in_app'], 'Stock transfer received', 'Stock transfer #{{transfer_id}} was received.'],
        // monline B2B purchase orders (PurchaseOrderRepository) — var: po_no.
        // in_app only, like transfer.* — this is a back-office trade flow between two
        // businesses, not a consumer order that warrants a push.
        'po.placed'     => ['transactional', ['in_app'], 'New purchase order {{po_no}}', 'Purchase order {{po_no}} was placed — please accept or reject it.'],
        'po.accepted'   => ['transactional', ['in_app'], 'Purchase order {{po_no}} accepted', 'Your purchase order {{po_no}} was accepted.'],
        'po.rejected'   => ['transactional', ['in_app'], 'Purchase order {{po_no}} rejected', 'Your purchase order {{po_no}} was rejected — open it to see the reason.'],
        'po.dispatched' => ['transactional', ['in_app'], 'Purchase order {{po_no}} dispatched', 'Purchase order {{po_no}} has been dispatched.'],
        'po.received'   => ['transactional', ['in_app'], 'Purchase order {{po_no}} received', 'Purchase order {{po_no}} was marked received by the buyer.'],
        // A platform force-cancel is distinct from a manufacturer rejection — it must say
        // so, not "rejected", which is both inaccurate and implies the wrong party acted.
        'po.cancelled'  => ['transactional', ['in_app'], 'Purchase order {{po_no}} cancelled', 'Purchase order {{po_no}} was cancelled by the platform — see the order for the reason.'],
    ];

    /** Render a {{placeholder}} template. Pure. */
    public static function render(string $template, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', static fn ($m) => (string) ($vars[$m[1]] ?? ''), $template) ?? $template;
    }

    /** Turn an event code like "request.submitted" into a readable title "Request submitted". */
    private static function humanize(string $code): string
    {
        $code = preg_replace('/_l\d+$/', '', $code) ?? $code;   // drop the approval-level suffix
        $code = trim(str_replace(['.', '_'], ' ', $code));

        return $code === '' ? 'Notification' : ucfirst($code);
    }

    /** A non-blank generic body built from whatever identifying vars are present. */
    private static function fallbackBody(string $code, array $vars): string
    {
        $bits = [];
        foreach (['request_no' => 'Request', 'order_no' => 'Order', 'transfer_id' => 'Transfer'] as $k => $label) {
            if (! empty($vars[$k])) { $bits[] = $label . ' ' . $vars[$k]; }
        }
        $what   = $bits !== [] ? implode(', ', $bits) : self::humanize($code);
        $status = ! empty($vars['status']) ? ' — ' . $vars['status'] : '';

        return $what . $status . '.';
    }

    /**
     * Backfill in-app notifications stored blank before their event was registered.
     * Re-renders title/body from event_code + the saved `data` vars using the
     * current templates/fallback. Idempotent. @return int rows fixed
     */
    public function rerenderBlank(): int
    {
        $db   = Database::connect();
        $rows = $db->table('notifications')->select('id, event_code, data')
            ->groupStart()->where('title', '')->orWhere('title', null)->groupEnd()
            ->get()->getResultArray();
        $fixed = 0;
        foreach ($rows as $r) {
            $vars   = json_decode((string) ($r['data'] ?? '[]'), true) ?: [];
            $code   = (string) $r['event_code'];
            $lookup = preg_match('/^request\.pending_l\d+$/', $code) ? 'request.pending' : $code;
            $ev     = self::EVENTS[$lookup] ?? ['transactional', ['in_app'], self::humanize($code), self::fallbackBody($code, $vars)];
            $title  = self::render($ev[2], $vars);
            $body   = self::render($ev[3], $vars);
            if ($title === '' && $body === '') { continue; }
            $db->table('notifications')->where('id', (int) $r['id'])
                ->update(['title' => mb_substr($title, 0, 191), 'body' => $body]);
            $fixed++;
        }

        return $fixed;
    }

    /**
     * Filter requested channels by the user's preferences (a row with enabled=0
     * for a (category, channel) removes that channel). Pure.
     * @param list<string> $requested
     * @param list<array{category:string,channel:string,enabled:int}> $prefs
     * @return list<string>
     */
    public static function allowedChannels(array $requested, array $prefs, string $category): array
    {
        $disabled = [];
        foreach ($prefs as $p) {
            if ($p['category'] === $category && (int) $p['enabled'] === 0) {
                $disabled[$p['channel']] = true;
            }
        }

        return array_values(array_filter($requested, static fn ($c) => ! isset($disabled[$c])));
    }

    /**
     * Dispatch a notification for an event to a user.
     * @param array<string,mixed> $vars
     * @return int|null notification id
     */
    public function notify(int $userId, string $eventCode, array $vars = [], ?array $channels = null): ?int
    {
        // request.pending_l2 / _l3 (one per approval level) collapse to one template.
        $lookup = preg_match('/^request\.pending_l\d+$/', $eventCode) ? 'request.pending' : $eventCode;
        // Unknown event => never render blank: humanize the code + use any vars we have.
        $ev = self::EVENTS[$lookup] ?? ['transactional', ['in_app'], self::humanize($eventCode), self::fallbackBody($eventCode, $vars)];
        [$category, $defaultChannels, $titleTpl, $bodyTpl] = $ev;
        $requested = $channels ?? $defaultChannels;

        try {
            $db    = Database::connect();
            $prefs = $db->table('notification_preferences')->where('user_id', $userId)->get()->getResultArray();
            $allow = self::allowedChannels($requested, $prefs, $category);

            $title = self::render($titleTpl, $vars);
            $body  = self::render($bodyTpl, $vars);

            // Reminder/urgent events are meant to repeat (capped by EscalationService);
            // bucket their dedupe key into REMINDER-interval windows so consecutive pings
            // aren't collapsed into one, while still deduping accidental same-window doubles.
            $repeatable = in_array($eventCode, ['order.reminder_shop', 'order.reminder_vendor', 'order.urgent_admin'], true);
            $dedupe     = $eventCode . ':' . $userId . ':' . substr(md5($body), 0, 12)
                . ($repeatable ? ':b' . intdiv(time(), 120) : '');

            // INSERT IGNORE: the dedupe unique key cleanly drops a duplicate without
            // throwing (which previously spammed the logs with constraint errors).
            $db->table('notifications')->ignore(true)->insert([
                'uuid' => $this->uuid(), 'user_id' => $userId, 'event_code' => $eventCode,
                'title' => mb_substr($title, 0, 191), 'body' => $body, 'data' => json_encode($vars),
                'category' => $category, 'dedupe_key' => $dedupe,
                'status' => $allow === ['in_app'] || $allow === [] ? 'sent' : 'queued',
            ]);
            $notifId = (int) $db->insertID();
            if ($notifId === 0) {
                return null; // duplicate suppressed by the dedupe key — nothing to deliver
            }

            $queue = service('jobQueueRepository');
            foreach ($allow as $channel) {
                if ($channel === 'in_app') {
                    continue;
                }
                $db->table('notification_deliveries')->insert([
                    'notification_id' => $notifId, 'channel' => $channel, 'status' => 'queued',
                ]);
                $deliveryId = (int) $db->insertID();
                $queue->enqueue('notify', 'notification.deliver', ['delivery_id' => $deliveryId, 'channel' => $channel, 'user_id' => $userId]);
            }

            return $notifId;
        } catch (Throwable) {
            return null; // notifications never break the caller
        }
    }

    public function markRead(int $notificationId, int $userId): bool
    {
        return Database::connect()->table('notifications')
            ->where('id', $notificationId)->where('user_id', $userId)
            ->update(['read_at' => date('Y-m-d H:i:s'), 'status' => 'read']);
    }

    /** Worker handler: send a queued delivery via the channel's provider. */
    public function deliver(int $deliveryId, string $channel): bool
    {
        $db     = Database::connect();
        $status = 'delivered';

        if ($channel === 'email') {
            $row = $db->table('notification_deliveries nd')
                ->select('n.title, n.body, u.email')
                ->join('notifications n', 'n.id = nd.notification_id', 'left')
                ->join('users u', 'u.id = n.user_id', 'left')
                ->where('nd.id', $deliveryId)->get()->getRowArray();

            $to = trim((string) ($row['email'] ?? ''));
            $ok = $to !== '' && service('mailer')->send(
                $to,
                (string) ($row['title'] ?? 'Notification'),
                '<p>' . esc((string) ($row['body'] ?? '')) . '</p>'
            );
            $status = $ok ? 'delivered' : 'failed';
        }

        if ($channel === 'push') {
            $row = $db->table('notification_deliveries nd')
                ->select('n.title, n.body, n.data, n.user_id')
                ->join('notifications n', 'n.id = nd.notification_id', 'left')
                ->where('nd.id', $deliveryId)->get()->getRowArray();

            $userId = (int) ($row['user_id'] ?? 0);
            $vars   = json_decode((string) ($row['data'] ?? '[]'), true) ?: [];
            $tokens = service('deviceTokenRepository')->activeForUser($userId);

            if ($tokens !== []) {
                $result = (new FcmPusher())->send(
                    $tokens,
                    (string) ($row['title'] ?? 'Notification'),
                    (string) ($row['body'] ?? ''),
                    $vars,
                );
                $status = $result['sent'] > 0 ? 'delivered' : 'failed';
            } else {
                $status = 'failed';
            }
        }

        return $db->table('notification_deliveries')->where('id', $deliveryId)->update([
            'provider'     => $channel === 'email' ? 'smtp' : ($channel === 'push' ? 'firebase' : 'sms'),
            'sent_at'      => date('Y-m-d H:i:s'),
            'delivered_at' => $status === 'delivered' ? date('Y-m-d H:i:s') : null,
            'status'       => $status,
        ]);
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
