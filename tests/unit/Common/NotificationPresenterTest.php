<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Notify/NotificationPresenter.php';

use App\Libraries\Notify\NotificationPresenter;

/** Pure presentation helpers for the topbar notification dropdown. */
final class NotificationPresenterTest extends TestCase
{
    public function testIconMapsKnownCategories(): void
    {
        $this->assertSame('bi-bag', NotificationPresenter::icon('order'));
        $this->assertSame('bi-currency-dollar', NotificationPresenter::icon('payment'));
        $this->assertSame('bi-currency-dollar', NotificationPresenter::icon('payout'));
        $this->assertSame('bi-person', NotificationPresenter::icon('user'));
        $this->assertSame('bi-chat-dots', NotificationPresenter::icon('comment'));
    }

    public function testIconFallsBackToBell(): void
    {
        $this->assertSame('bi-bell', NotificationPresenter::icon('anything-else'));
        $this->assertSame('bi-bell', NotificationPresenter::icon(null));
    }

    public function testAccentMapsKnownCategories(): void
    {
        $this->assertSame('orange', NotificationPresenter::accent('order'));
        $this->assertSame('green', NotificationPresenter::accent('payout'));
        $this->assertSame('purple', NotificationPresenter::accent('comment'));
        $this->assertSame('blue', NotificationPresenter::accent('user'));
        $this->assertSame('blue', NotificationPresenter::accent(null));
    }

    public function testTimeAgoBuckets(): void
    {
        $now = 1_700_000_000; // fixed reference
        $this->assertSame('Just now', NotificationPresenter::timeAgo($now - 30, $now));
        $this->assertSame('5 min ago', NotificationPresenter::timeAgo($now - 5 * 60, $now));
        $this->assertSame('2 hrs ago', NotificationPresenter::timeAgo($now - 2 * 3600, $now));
        $this->assertSame('1 day ago', NotificationPresenter::timeAgo($now - 26 * 3600, $now));
        $this->assertSame('3 days ago', NotificationPresenter::timeAgo($now - 3 * 86400, $now));
    }

    public function testTimeAgoAcceptsDatetimeString(): void
    {
        $now = strtotime('2026-06-13 12:00:00');
        $this->assertSame('10 min ago', NotificationPresenter::timeAgo('2026-06-13 11:50:00', $now));
    }
}
