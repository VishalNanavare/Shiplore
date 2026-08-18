<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Batch 2 — master CRUD guards + form render (generic MasterCrud trait).
 * DB-write paths are covered by the live smoke; here we assert the no-DB paths:
 * the new-form renders and create is RBAC-gated.
 */
final class AdminMasterTest extends CIUnitTestCase
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

    public function testUnitNewFormRenders(): void
    {
        $this->grant(['unit.manage']);
        $r = $this->withSession($this->sess())->get('admin/units/new');
        $r->assertStatus(200);
        $this->assertStringContainsString('Allow fractional qty', (string) $r->getBody());
    }

    public function testBrandNewFormRenders(): void
    {
        $this->grant(['brand.create']);
        $r = $this->withSession($this->sess())->get('admin/brands/new');
        $r->assertStatus(200);
        $this->assertStringContainsString('Brand name', (string) $r->getBody());
    }

    public function testAttributeNewFormRenders(): void
    {
        $this->grant(['attribute.manage']);
        $r = $this->withSession($this->sess())->get('admin/attributes/new');
        $r->assertStatus(200);
        $this->assertStringContainsString('Variant-defining', (string) $r->getBody());
    }

    public function testTaxClassStoreDeniedWithoutPermission(): void
    {
        $this->grant(['dashboard.view']); // lacks tax.manage
        $data = service('session')->get() + $this->sess();
        $r = $this->withSession($data)->post('admin/tax-classes/store', [csrf_token() => csrf_hash(), 'code' => 'X', 'name' => 'Y']);
        $r->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $r->getRedirectUrl());
    }

    public function testHsnNewFormRenders(): void
    {
        $this->grant(['hsn.manage']);
        $r = $this->withSession($this->sess())->get('admin/hsn-codes/new');
        $r->assertStatus(200);
        $this->assertStringContainsString('SAC (services)', (string) $r->getBody());
    }
}
