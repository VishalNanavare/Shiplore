<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Notify/NotificationService.php';

use App\Libraries\Notify\NotificationService;

/**
 * Notification dispatcher core — template rendering + preference-based channel
 * filtering. @see docs/architecture/39-NOTIFICATIONS.md
 */
final class NotificationServiceTest extends TestCase
{
    public function testRenderReplacesPlaceholders(): void
    {
        $this->assertSame('Order OD-9 shipped', NotificationService::render('Order {{order_no}} {{status}}', ['order_no' => 'OD-9', 'status' => 'shipped']));
    }

    public function testRenderMissingVarBecomesEmpty(): void
    {
        $this->assertSame('Hi ', NotificationService::render('Hi {{name}}', []));
    }

    public function testAllowedChannelsHonoursOptOut(): void
    {
        $prefs = [
            ['category' => 'promotional', 'channel' => 'email', 'enabled' => 0],
            ['category' => 'transactional', 'channel' => 'sms', 'enabled' => 1],
        ];
        $this->assertSame(['push'], NotificationService::allowedChannels(['email', 'push'], $prefs, 'promotional'));
    }

    public function testAllowedChannelsKeepsAllWhenNoOptOut(): void
    {
        $this->assertSame(['in_app', 'email'], NotificationService::allowedChannels(['in_app', 'email'], [], 'transactional'));
    }
}
