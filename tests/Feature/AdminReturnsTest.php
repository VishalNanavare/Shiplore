<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

require_once __DIR__ . '/../../app/Models/ReturnRepository.php';

use App\Models\ReturnRepository;

/** X4 — the previously-missing admin returns pipeline + document registers. */
final class AdminReturnsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $returns;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        $this->returns = new class () {
            public array $transitions = [];

            public function listForAdmin(?string $status = null): array
            {
                return [[
                    'id' => 4, 'status' => 'requested', 'channel' => 'online', 'refund_to' => 'source',
                    'qc_result' => null, 'created_at' => '2026-06-12 09:00:00', 'order_no' => 'ORD-1001',
                    'sub_order_no' => 'SO-1001-1', 'customer' => 'Asha', 'vendor' => 'Fresh Foods', 'reason' => 'Damaged',
                ]];
            }

            public function countsByStatus(): array
            {
                return ['requested' => 1];
            }

            public function transition(int $id, string $to, ?int $actorId = null): array
            {
                $this->transitions[] = [$id, $to];

                return ReturnRepository::canTransition('requested', $to) ? ['ok' => true] : ['ok' => false, 'reason' => 'illegal'];
            }
        };
        Services::injectMock('returnRepository', $this->returns);
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset(true);
        parent::tearDown();
    }

    private function grant(array $perms): void
    {
        Services::injectMock('capabilityRepository', new class ($perms) {
            public function __construct(private array $perms) {}

            public function loadAssignments(int $u): array
            {
                return [['permissions' => $this->perms, 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Admin', 'principal_type' => 'platform'];
    }

    public function testRegisterListsReturns(): void
    {
        $this->grant(['return.view']);

        $r = $this->withSession($this->sess())->get('admin/returns');

        $r->assertStatus(200);
        $r->assertSee('ORD-1001');
        $r->assertSee('Fresh Foods');
        $r->assertSee('Damaged');
    }

    public function testTransitionPostsToRepository(): void
    {
        $this->grant(['return.view', 'return.manage']);

        $r = $this->withSession(service('session')->get() + $this->sess())
            ->post('admin/returns/4/transition', [csrf_token() => csrf_hash(), 'to' => 'approved']);

        $r->assertRedirect();
        $this->assertSame([[4, 'approved']], $this->returns->transitions);
    }

    public function testBlockedWithoutPermission(): void
    {
        $this->grant([]);

        $this->withSession($this->sess())->get('admin/returns')->assertRedirect();
    }

    public function testStateMachineIsForwardOnly(): void
    {
        $this->assertTrue(ReturnRepository::canTransition('requested', 'approved'));
        $this->assertTrue(ReturnRepository::canTransition('qc', 'write_off'));
        $this->assertFalse(ReturnRepository::canTransition('refunded', 'requested'));
        $this->assertFalse(ReturnRepository::canTransition('rejected', 'approved'));
        $this->assertFalse(ReturnRepository::canTransition('requested', 'refunded')); // no skipping
    }
}
