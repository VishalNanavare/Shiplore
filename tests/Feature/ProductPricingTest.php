<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** P4 — admin pricing HTTP flow: render, special/tier/group wiring, RBAC. */
final class ProductPricingTest extends CIUnitTestCase
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
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Admin', 'principal_type' => 'platform'];
    }

    private function postSess(): array
    {
        return service('session')->get() + $this->sess();
    }

    private function mocks(object $pricingSpy): void
    {
        Services::injectMock('adminProductRepository', new class {
            public function findById(int $id): ?array { return ['id' => $id, 'vendor_id' => 4, 'title' => 'Tee']; }
        });
        Services::injectMock('productVariantRepository', new class {
            public function listWithValues(int $p): array { return [['id' => 9, 'sku' => 'TEE', 'attributes' => '', 'mrp' => '1000', 'base_price' => '900']]; }
            public function findVariant(int $id): ?array { return ['id' => $id, 'product_id' => 7, 'vendor_id' => 4]; }
        });
        Services::injectMock('pricingRepository', $pricingSpy);
    }

    private function readMock(): object
    {
        return new class {
            public function effectivePrice(int $v, float $b, array $c): array { return ['price' => 799.0, 'source' => 'flash', 'priority' => 20]; }
            public function specials(int $v): array { return []; }
            public function tiers(int $v): array { return []; }
            public function groupPrices(int $v): array { return []; }
            public function customerGroups(): array { return [['id' => 1, 'code' => 'retail', 'name' => 'Retail']]; }
        };
    }

    public function testPricingPageRendersWithEffectivePreview(): void
    {
        $this->grant(['product.update']);
        $this->mocks($this->readMock());
        $r = $this->withSession($this->sess())->get('admin/products/7/pricing');
        $r->assertStatus(200);
        $this->assertStringContainsString('Effective', (string) $r->getBody());
    }

    public function testAddSpecialWiresToRepoForOwnedVariant(): void
    {
        $this->grant(['product.update']);
        $spy = new class {
            public array $got = [];
            public function effectivePrice(int $v, float $b, array $c): array { return ['price' => 900.0, 'source' => 'base', 'priority' => 0]; }
            public function specials(int $v): array { return []; }
            public function tiers(int $v): array { return []; }
            public function groupPrices(int $v): array { return []; }
            public function customerGroups(): array { return []; }
            public function addSpecial(int $vid, array $d, ?int $a = null): int { $this->got = ['vid' => $vid, 'kind' => $d['kind'] ?? null, 'price' => $d['price'] ?? null]; return 1; }
        };
        $this->mocks($spy);
        $r = $this->withSession($this->postSess())->post('admin/products/7/pricing/special', [csrf_token() => csrf_hash(), 'variant_id' => 9, 'kind' => 'flash', 'price' => '799']);
        $r->assertRedirect();
        $this->assertSame(9, $spy->got['vid']);
        $this->assertSame('flash', $spy->got['kind']);
    }

    public function testAddTierWiresToRepo(): void
    {
        $this->grant(['product.update']);
        $spy = new class {
            public bool $tierAdded = false;
            public function effectivePrice(int $v, float $b, array $c): array { return ['price' => 900.0, 'source' => 'base', 'priority' => 0]; }
            public function specials(int $v): array { return []; }
            public function tiers(int $v): array { return []; }
            public function groupPrices(int $v): array { return []; }
            public function customerGroups(): array { return []; }
            public function addTier(int $vid, float $q, float $p, ?int $a = null): bool { $this->tierAdded = true; return true; }
        };
        $this->mocks($spy);
        $r = $this->withSession($this->postSess())->post('admin/products/7/pricing/tier', [csrf_token() => csrf_hash(), 'variant_id' => 9, 'min_qty' => '10', 'price' => '700']);
        $r->assertRedirect();
        $this->assertTrue($spy->tierAdded);
    }

    public function testDeniedWithoutPermission(): void
    {
        $this->grant(['product.view']);
        $this->mocks($this->readMock());
        $r = $this->withSession($this->sess())->get('admin/products/7/pricing');
        $r->assertRedirect();
        $this->assertStringContainsString('dashboard', (string) $r->getRedirectUrl());
    }
}
