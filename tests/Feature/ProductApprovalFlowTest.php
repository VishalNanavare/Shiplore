<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** P7 — approval lifecycle HTTP: vendor submit + admin publish/unpublish/request-changes. */
final class ProductApprovalFlowTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function grant(array $perms, string $scope = 'platform', ?int $scopeId = null): void
    {
        Services::injectMock('capabilityRepository', new class ($perms, $scope, $scopeId) {
            public function __construct(private array $perms, private string $scope, private ?int $scopeId) {}
            public function loadAssignments(int $u): array
            {
                return [['permissions' => $this->perms, 'scope_type' => $this->scope, 'scope_id' => $this->scopeId, 'attributes' => []]];
            }
        });
    }

    // ---- Vendor submit ----
    public function testVendorSubmitWiresToRepoForOwnProduct(): void
    {
        $this->grant([], 'vendor', 1);
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate']; }
        });
        Services::injectMock('adminProductRepository', new class {
            public function findById(int $id): ?array { return ['id' => $id, 'vendor_id' => 1, 'title' => 'X']; }
        });
        $spy = new class {
            public bool $submitted = false;
            public function submit(int $id, int $a): bool { $this->submitted = true; return true; }
        };
        Services::injectMock('productApprovalRepository', $spy);
        $sess = ['isLoggedIn' => true, 'user_id' => 5, 'user_name' => 'V', 'principal_type' => 'vendor'];
        $r = $this->withSession(service('session')->get() + $sess)->post('vendor/products/7/submit', [csrf_token() => csrf_hash()]);
        $r->assertRedirect();
        $this->assertTrue($spy->submitted);
    }

    public function testVendorCannotSubmitAnotherVendorsProduct(): void
    {
        $this->grant([], 'vendor', 1);
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate']; }
        });
        Services::injectMock('adminProductRepository', new class {
            public function findById(int $id): ?array { return ['id' => $id, 'vendor_id' => 99, 'title' => 'X']; } // not vendor 1
        });
        $spy = new class {
            public bool $submitted = false;
            public function submit(int $id, int $a): bool { $this->submitted = true; return true; }
        };
        Services::injectMock('productApprovalRepository', $spy);
        $sess = ['isLoggedIn' => true, 'user_id' => 5, 'user_name' => 'V', 'principal_type' => 'vendor'];
        $r = $this->withSession(service('session')->get() + $sess)->post('vendor/products/7/submit', [csrf_token() => csrf_hash()]);
        $r->assertRedirect();
        $this->assertFalse($spy->submitted);
    }

    // ---- Vendor publish / unpublish (vendor controls storefront visibility) ----
    public function testVendorPublishWiresForOwnApprovedProduct(): void
    {
        $this->grant([], 'vendor', 1);
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate']; }
        });
        Services::injectMock('adminProductRepository', new class {
            public function findById(int $id): ?array { return ['id' => $id, 'vendor_id' => 1, 'title' => 'X', 'status' => 'approved']; }
        });
        $spy = new class {
            public bool $published = false;
            public function publish(int $id, int $a): bool { $this->published = true; return true; }
        };
        Services::injectMock('productApprovalRepository', $spy);
        $sess = ['isLoggedIn' => true, 'user_id' => 5, 'user_name' => 'V', 'principal_type' => 'vendor'];
        $this->withSession(service('session')->get() + $sess)->post('vendor/products/7/publish', [csrf_token() => csrf_hash()])->assertRedirect();
        $this->assertTrue($spy->published);
    }

    public function testVendorCannotPublishAnotherVendorsProduct(): void
    {
        $this->grant([], 'vendor', 1);
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate']; }
        });
        Services::injectMock('adminProductRepository', new class {
            public function findById(int $id): ?array { return ['id' => $id, 'vendor_id' => 99, 'title' => 'X', 'status' => 'approved']; }
        });
        $spy = new class {
            public bool $published = false;
            public function publish(int $id, int $a): bool { $this->published = true; return true; }
        };
        Services::injectMock('productApprovalRepository', $spy);
        $sess = ['isLoggedIn' => true, 'user_id' => 5, 'user_name' => 'V', 'principal_type' => 'vendor'];
        $this->withSession(service('session')->get() + $sess)->post('vendor/products/7/publish', [csrf_token() => csrf_hash()])->assertRedirect();
        $this->assertFalse($spy->published);
    }

    // ---- Admin publish ----
    public function testAdminPublishWires(): void
    {
        $this->grant(['product.approve']);
        $spy = new class {
            public bool $published = false;
            public function publish(int $id, int $a): bool { $this->published = true; return true; }
        };
        Services::injectMock('productApprovalRepository', $spy);
        $sess = ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'A', 'principal_type' => 'platform'];
        $r = $this->withSession(service('session')->get() + $sess)->post('admin/product-approvals/7/publish', [csrf_token() => csrf_hash()]);
        $r->assertRedirect();
        $this->assertTrue($spy->published);
    }

    public function testAdminPublishDeniedWithoutPermission(): void
    {
        $this->grant(['product.view']);
        Services::injectMock('productApprovalRepository', new class {
            public function publish(int $id, int $a): bool { return true; }
        });
        $sess = ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'A', 'principal_type' => 'platform'];
        $r = $this->withSession(service('session')->get() + $sess)->post('admin/product-approvals/7/publish', [csrf_token() => csrf_hash()]);
        $r->assertRedirect();
        $this->assertStringContainsString('dashboard', (string) $r->getRedirectUrl());
    }
}
