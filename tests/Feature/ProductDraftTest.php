<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** RB3 — draft create + per-section autosave HTTP flow; RBAC/tenant guarded. */
final class ProductDraftTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
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
    }

    private function sess(): array
    {
        return service('session')->get() + ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'A', 'principal_type' => 'platform'];
    }

    private function decode($r): array
    {
        $b = (string) $r->getBody();
        if (str_contains($b, '<') && str_contains($b, '{')) {
            $b = substr($b, (int) strpos($b, '{'), (int) strrpos($b, '}') - (int) strpos($b, '{') + 1);
        }

        return json_decode($b, true) ?: [];
    }

    private function ajaxPost(string $url, array $data)
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->sess())->post($url, $data + [csrf_token() => csrf_hash()]);
    }

    public function testDraftCreatesAndReturnsId(): void
    {
        $this->grant(['product.create']);
        Services::injectMock('adminProductRepository', new class {
            public function allowedCategories(int $v): array { return [['id' => 3, 'name' => 'Cat']]; }
            public function draftCreate(array $d, ?int $a = null): ?int { return 42; }
        });
        $r = $this->ajaxPost('admin/products/draft', ['vendor_id' => 7, 'category_id' => 3, 'title' => 'New']);
        $r->assertStatus(200);
        $json = $this->decode($r);
        $this->assertTrue($json['ok']);
        $this->assertSame(42, $json['product_id']);
    }

    public function testDraftRejectsCategoryOutsideVendor(): void
    {
        $this->grant(['product.create']);
        Services::injectMock('adminProductRepository', new class {
            public function allowedCategories(int $v): array { return [['id' => 3]]; }   // 9 not allowed
            public function draftCreate(array $d, ?int $a = null): ?int { return 42; }
        });
        $r = $this->ajaxPost('admin/products/draft', ['vendor_id' => 7, 'category_id' => 9]);
        $r->assertStatus(422);
    }

    public function testAutosaveSectionWires(): void
    {
        $this->grant(['product.update']);
        $spy = new class {
            public array $got = [];
            public function findById(int $id): ?array { return ['id' => $id, 'vendor_id' => 4]; }
            public function autosave(int $id, string $section, array $d, ?int $a = null): bool { $this->got = ['id' => $id, 'section' => $section]; return true; }
        };
        Services::injectMock('adminProductRepository', $spy);
        $r = $this->ajaxPost('admin/products/42/autosave/pricing', ['mrp' => '999', 'base_price' => '799']);
        $r->assertStatus(200);
        $this->assertSame(['id' => 42, 'section' => 'pricing'], $spy->got);
    }

    public function testDraftDeniedWithoutPermission(): void
    {
        $this->grant(['product.view']);
        $r = $this->ajaxPost('admin/products/draft', ['vendor_id' => 7, 'category_id' => 3]);
        $r->assertStatus(403);
    }
}
