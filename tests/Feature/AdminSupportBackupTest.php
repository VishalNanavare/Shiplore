<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Phase 6 — Support tickets (list/detail/reply/close) + Backup logs (list).
 * RBAC + CSRF; repositories mocked.
 */
final class AdminSupportBackupTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.in');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function grant(array $permissions): void
    {
        Services::injectMock('capabilityRepository', new class ($permissions) {
            public function __construct(private array $perms) {}
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => $this->perms, 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Super Admin', 'principal_type' => 'platform'];
    }

    private function postSession(): array
    {
        return service('session')->get() + $this->sess();
    }

    private function supportMock(): void
    {
        Services::injectMock('supportTicketRepository', new class {
            public function list(?string $s = null): array
            {
                return [['id' => 1, 'ticket_no' => 'TKT-1001', 'subject' => 'Order not delivered', 'requester_type' => 'customer', 'priority' => 'high', 'status' => 'open', 'category' => 'delivery', 'created_at' => '2026-06-08 09:00:00', 'last_reply_at' => null, 'requester' => 'Aarav Sharma', 'vendor' => null]];
            }
            public function findById(int $id): ?array
            {
                return $id === 1 ? ['id' => 1, 'ticket_no' => 'TKT-1001', 'subject' => 'Order not delivered', 'body' => 'Where is my order?', 'requester_type' => 'customer', 'priority' => 'high', 'status' => 'open', 'category' => 'delivery', 'created_at' => '2026-06-08 09:00:00', 'requester' => 'Aarav Sharma', 'requester_email' => 'aarav@example.com', 'vendor' => null] : null;
            }
            public function messages(int $ticketId): array { return [['id' => 1, 'body' => 'Any update?', 'is_internal' => 0, 'created_at' => '2026-06-08 09:30:00', 'author' => 'Aarav Sharma']]; }
            public function addMessage(int $t, int $a, string $b): bool { return true; }
            public function updateStatus(int $id, string $st, ?int $a = null): bool { return true; }
        });
    }

    public function testSupportListRenders(): void
    {
        $this->grant(['support.view']);
        $this->supportMock();
        $r = $this->withSession($this->sess())->get('admin/support');
        $r->assertStatus(200);
        $this->assertStringContainsString('supportTable', (string) $r->getBody());
        $this->assertStringContainsString('TKT-1001', (string) $r->getBody());
    }

    public function testSupportDetailRenders(): void
    {
        $this->grant(['support.view']);
        $this->supportMock();
        $r = $this->withSession($this->sess())->get('admin/support/1');
        $r->assertStatus(200);
        $this->assertStringContainsString('Conversation', (string) $r->getBody());
    }

    public function testSupportReplyRedirects(): void
    {
        $this->grant(['support.respond']);
        $this->supportMock();
        $r = $this->withSession($this->postSession())->post('admin/support/1/reply', [csrf_token() => csrf_hash(), 'body' => 'On it.']);
        $r->assertRedirect();
        $this->assertStringContainsString('admin/support/1', $r->getRedirectUrl());
    }

    public function testSupportCloseRedirects(): void
    {
        $this->grant(['support.close']);
        $this->supportMock();
        $r = $this->withSession($this->postSession())->post('admin/support/1/close', [csrf_token() => csrf_hash()]);
        $r->assertRedirect();
        $this->assertStringContainsString('admin/support/1', $r->getRedirectUrl());
    }

    public function testSupportDenied(): void
    {
        $this->grant(['shop.view']);
        $this->withSession($this->sess())->get('admin/support')->assertRedirect();
    }

    public function testBackupsListRenders(): void
    {
        $this->grant(['backup.view']);
        Services::injectMock('backupRepository', new class {
            public function list(?string $s = null): array
            {
                return [['id' => 1, 'type' => 'full', 'scope' => 'database', 'status' => 'completed', 'size_bytes' => 268435456, 'location' => 's3://x', 'started_at' => '2026-06-08 02:00:00', 'finished_at' => '2026-06-08 02:14:00', 'notes' => 'Nightly', 'created_at' => '2026-06-08 02:00:00']];
            }
        });
        $r = $this->withSession($this->sess())->get('admin/backups');
        $r->assertStatus(200);
        $this->assertStringContainsString('backupsTable', (string) $r->getBody());
    }

    public function testBackupsDenied(): void
    {
        $this->grant(['shop.view']);
        $this->withSession($this->sess())->get('admin/backups')->assertRedirect();
    }
}
