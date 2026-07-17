<?php

declare(strict_types=1);

namespace App\Libraries\Notify;

/**
 * NotificationPresenter — pure presentation helpers for the topbar dropdown.
 * Maps a notification category to an icon + accent colour and formats a
 * human "time ago" string. No DB or framework dependencies (unit-testable).
 */
final class NotificationPresenter
{
    /** category => Bootstrap Icon name */
    private const ICONS = [
        'order'   => 'bi-bag',
        'payment' => 'bi-currency-dollar',
        'payout'  => 'bi-currency-dollar',
        'user'    => 'bi-person',
        'comment' => 'bi-chat-dots',
    ];

    /** category => accent colour token (matches .notif-icon--* CSS) */
    private const ACCENTS = [
        'order'   => 'orange',
        'payment' => 'green',
        'payout'  => 'green',
        'user'    => 'blue',
        'comment' => 'purple',
    ];

    public static function icon(?string $category): string
    {
        return self::ICONS[$category] ?? 'bi-bell';
    }

    public static function accent(?string $category): string
    {
        return self::ACCENTS[$category] ?? 'blue';
    }

    /**
     * Human relative time. $when is a unix timestamp or a datetime string;
     * $now is a unix timestamp (injectable for deterministic tests).
     */
    public static function timeAgo(int|string $when, int $now): string
    {
        $ts   = is_int($when) ? $when : (int) strtotime($when);
        $diff = max(0, $now - $ts);

        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);
            return $m . ' min ago';
        }
        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);
            return $h . ($h === 1 ? ' hr ago' : ' hrs ago');
        }
        $d = (int) floor($diff / 86400);
        return $d . ($d === 1 ? ' day ago' : ' days ago');
    }
}
