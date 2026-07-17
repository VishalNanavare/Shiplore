<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Topbar notification feed endpoint — returns JSON scoped to the session user.
 * The repo is mocked so the test asserts the controller's contract: it passes
 * the SESSION user id to the repo and shapes rows with icon/accent/time_ago.
 */
final class NotificationFeedTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        // webAuth resolves capabilities from the DB — mock to allow the request.
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => [], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]];
            }
        });

        // Mock the repo: record the user id it was queried with; return one
        // unread order row + one read row.
        Services::injectMock('notificationRepository', new class {
            public ?int $askedFor = null;
            public function forUser(int $userId, int $limit = 10): array
            {
                $this->askedFor = $userId;
                return [
                    ['id' => 9, 'title' => 'A new order has been placed', 'category' => 'order',
                     'read_at' => null, 'created_at' => date('Y-m-d H:i:s', time() - 300)],
                    ['id' => 8, 'title' => 'Payout successful', 'category' => 'payout',
                     'read_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s', time() - 7200)],
                ];
            }
        });
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function session(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 101, 'user_name' => 'Rahul Mehta', 'principal_type' => 'vendor'];
    }

    public function testFeedRequiresLogin(): void
    {
        $this->get('notifications/feed')->assertRedirect();
    }

    public function testFeedReturnsScopedJson(): void
    {
        $result = $this->withSession($this->session())->get('notifications/feed');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);

        $this->assertSame(1, $json['unread']);                       // one unread row
        $this->assertCount(2, $json['items']);
        $this->assertSame('A new order has been placed', $json['items'][0]['title']);
        $this->assertSame('bi-bag', $json['items'][0]['icon']);
        $this->assertSame('orange', $json['items'][0]['accent']);
        $this->assertTrue($json['items'][0]['unread']);
        $this->assertSame('5 min ago', $json['items'][0]['time_ago']);
        $this->assertSame('bi-currency-dollar', $json['items'][1]['icon']);
        $this->assertFalse($json['items'][1]['unread']);

        // Scoped to the SESSION user, not a hardcoded id.
        $this->assertSame(101, service('notificationRepository')->askedFor);
    }
}
