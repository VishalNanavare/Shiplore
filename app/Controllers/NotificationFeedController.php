<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Notify\NotificationPresenter;

/**
 * NotificationFeedController — JSON feed for the shared topbar bell dropdown.
 * Returns the recent notifications of the logged-in session user (any panel).
 */
final class NotificationFeedController extends BaseController
{
    public function feed()
    {
        $userId = (int) session()->get('user_id');
        $rows   = service('notificationRepository')->forUser($userId, 10);
        $now    = time();

        $unread = 0;
        $items  = [];
        foreach ($rows as $r) {
            $isUnread = empty($r['read_at']);
            if ($isUnread) {
                $unread++;
            }
            $items[] = [
                'id'       => (int) ($r['id'] ?? 0),
                'title'    => (string) ($r['title'] ?? ''),
                'icon'     => NotificationPresenter::icon($r['category'] ?? null),
                'accent'   => NotificationPresenter::accent($r['category'] ?? null),
                'time_ago' => NotificationPresenter::timeAgo((string) ($r['created_at'] ?? ''), $now),
                'unread'   => $isUnread,
            ];
        }

        return $this->response->setJSON(['unread' => $unread, 'items' => $items]);
    }
}
