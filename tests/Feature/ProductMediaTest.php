<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** RB7 — product media endpoints: reorder (AJAX JSON), set-primary, RBAC. */
final class ProductMediaTest extends CIUnitTestCase
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

    private function grant(array $perms): void
    {
        Services::injectMock('capabilityRepository', new class ($perms) {
            public function __construct(private array $perms) {}
            public function loadAssignments(int $u): array
            {
                return [['permissions' => $this->perms, 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
        Services::injectMock('adminProductRepository', new class {
            public function findById(int $id): ?array { return ['id' => $id, 'vendor_id' => 4, 'title' => 'X']; }
        });
    }

    private function postSess(): array
    {
        return service('session')->get() + ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'A', 'principal_type' => 'platform'];
    }

    public function testReorderReturnsJson(): void
    {
        $this->grant(['product.update']);
        $spy = new class {
            public array $order = [];
            public function reorder(int $p, array $ids, ?int $a = null): bool { $this->order = $ids; return true; }
        };
        Services::injectMock('mediaRepository', $spy);
        $r = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->postSess())
            ->post('admin/products/7/media/reorder', [csrf_token() => csrf_hash(), 'order' => [3, 1, 2]]);
        $r->assertStatus(200);
        $this->assertEquals([3, 1, 2], $spy->order);
    }

    public function testSetPrimaryWires(): void
    {
        $this->grant(['product.update']);
        $spy = new class {
            public int $primary = 0;
            public function setPrimaryImage(int $p, int $mid, ?int $a = null): bool { $this->primary = $mid; return true; }
        };
        Services::injectMock('mediaRepository', $spy);
        $r = $this->withSession($this->postSess())->post('admin/products/7/media/primary', [csrf_token() => csrf_hash(), 'media_id' => 9]);
        $r->assertRedirect();
        $this->assertSame(9, $spy->primary);
    }

    public function testReorderDeniedWithoutPermission(): void
    {
        $this->grant(['product.view']);
        Services::injectMock('mediaRepository', new class {
            public function reorder(int $p, array $ids, ?int $a = null): bool { return true; }
        });
        $r = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->postSess())
            ->post('admin/products/7/media/reorder', [csrf_token() => csrf_hash(), 'order' => [1]]);
        $r->assertStatus(403);
    }
}
