<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Phase 11 — sync engine: admin health dashboard + manual trigger, and the
 * generic JWT sync API (pull deltas, idempotent push, entity validation).
 * Repos mocked.
 */
final class SyncEngineTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    // ---- shared mocks ----
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

    private function engineMock(): void
    {
        Services::injectMock('jobQueueRepository', new class {
            public function stats(): array { return ['queued' => 2, 'reserved' => 0, 'processing' => 1, 'done' => 40, 'failed' => 1, 'dead_letter' => 1]; }
            public function enqueue(string $q, string $t, array $p, ?string $k = null, ?string $a = null): int { return 99; }
        });
        Services::injectMock('syncEngineRepository', new class {
            public function health(): array { return ['counts' => [], 'conflicts_open' => 1, 'dead_letter' => 1, 'cursors' => [['terminal_id' => 1, 'entity_type' => 'stock', 'last_anchor' => '2026-06-10 10:00', 'last_pulled_at' => '2026-06-10 10:00']]]; }
            public function openConflicts(int $l = 50): array { return [['id' => 5, 'uuid' => 'u', 'entity_type' => 'price', 'entity_uuid' => 'pv-1', 'conflict_type' => 'version', 'server_version' => 5, 'client_version' => 3, 'resolution' => 'recorded_flagged', 'status' => 'open', 'created_at' => '2026-06-10']]; }
            public function deadLetters(int $l = 50): array { return [['id' => 8, 'uuid' => 'd', 'entity_type' => 'order', 'entity_uuid' => 'o-1', 'reason' => 'timeout', 'attempts' => 5, 'status' => 'open', 'created_at' => '2026-06-10']]; }
            public function pull(string $e, ?string $s, int $l = 200): array { return ['entity' => $e, 'rows' => [], 'next_anchor' => '2026-06-10 11:00:00']; }
            public function applyBatch(?int $t, string $k, array $i, ?int $a = null): array { return ['batch_uuid' => 'b-1', 'replay' => false, 'applied' => count($i), 'conflicts' => 0, 'duplicates' => 0, 'failed' => 0, 'results' => []]; }
        });
    }

    // ---- Admin dashboard ----
    public function testDashboardRenders(): void
    {
        $this->grant(['integration.manage']);
        $this->engineMock();
        $r = $this->withSession($this->sess())->get('admin/sync-health');
        $r->assertStatus(200);
        $body = (string) $r->getBody();
        $this->assertStringContainsString('Sync Health', $body);
        $this->assertStringContainsString('Dead-letter', $body);
    }

    public function testDashboardDenied(): void
    {
        $this->grant(['shop.view']);
        $this->engineMock();
        $this->withSession($this->sess())->get('admin/sync-health')->assertRedirect();
    }

    public function testManualTriggerQueuesJob(): void
    {
        $this->grant(['integration.manage']);
        $this->engineMock();
        $r = $this->withSession(service('session')->get() + $this->sess())
            ->post('admin/sync-health/trigger', [csrf_token() => csrf_hash(), 'entity' => 'stock']);
        $r->assertRedirect();
        $this->assertStringContainsString('admin/sync-health', $r->getRedirectUrl());
    }

    // ---- Sync API ----
    private function bearer(): array
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => [], 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]]; }
        });
        $secret = (string) (getenv('JWT_SECRET') ?: env('jwt.secret', 'dev-insecure-secret-change-me'));
        $token  = service('tokenService')->issue(['sub' => 1, 'typ' => 'platform', 'name' => 'T'], 3600, $secret, time());

        return ['Authorization' => 'Bearer ' . $token];
    }

    public function testApiPullKnownEntity(): void
    {
        $this->engineMock();
        $h = $this->bearer();
        $r = $this->withHeaders($h)->get('api/v1/sync/pull?entity=stock&since=2026-06-01');
        $r->assertStatus(200);
        $this->assertStringContainsString('next_anchor', (string) $r->getJSON());
    }

    public function testApiPullUnknownEntityRejected(): void
    {
        $this->withHeaders($this->bearer())->get('api/v1/sync/pull?entity=teleporter')->assertStatus(422);
    }

    public function testApiPushIdempotentBatch(): void
    {
        $this->engineMock();
        $payload = ['idempotency_key' => 'batch-1', 'terminal_id' => 1, 'items' => [
            ['entity_type' => 'stock', 'entity_uuid' => 'inv-1', 'op' => 'upsert', 'client_version' => 2, 'payload' => []],
        ]];
        $r = $this->withHeaders($this->bearer())->post('api/v1/sync/push', $payload);
        $r->assertStatus(200);
        $this->assertStringContainsString('applied', (string) $r->getJSON());
    }

    public function testApiPushRequiresIdempotencyKey(): void
    {
        $this->engineMock();
        $this->withHeaders($this->bearer())->post('api/v1/sync/push', ['items' => [['entity_type' => 'stock']]])->assertStatus(422);
    }

    public function testApiEntitiesList(): void
    {
        $r = $this->withHeaders($this->bearer())->get('api/v1/sync/entities');
        $r->assertStatus(200);
        $this->assertStringContainsString('product', (string) $r->getJSON());
    }

    public function testApiUnauthenticated(): void
    {
        $this->get('api/v1/sync/pull?entity=stock')->assertStatus(401);
    }
}
