<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** RB2 — cascading lookup endpoints return JSON; vendor lookups are tenant-scoped. */
final class ProductLookupTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function grantAdmin(array $perms): void
    {
        Services::injectMock('capabilityRepository', new class ($perms) {
            public function __construct(private array $perms) {}
            public function loadAssignments(int $u): array
            {
                return [['permissions' => $this->perms, 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    private function grantVendor(): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => [], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate']; }
        });
    }

    private function adminSess(): array
    {
        return service('session')->get() + ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'A', 'principal_type' => 'platform'];
    }

    private function vendorSess(): array
    {
        return service('session')->get() + ['isLoggedIn' => true, 'user_id' => 5, 'user_name' => 'V', 'principal_type' => 'vendor'];
    }

    private function decode($r): array
    {
        $b = (string) $r->getBody();
        if (str_contains($b, '<') && str_contains($b, '{')) {
            $b = substr($b, (int) strpos($b, '{'), (int) strrpos($b, '}') - (int) strpos($b, '{') + 1);
        }

        return json_decode($b, true) ?: [];
    }

    private function ajaxGet(array $sess, string $url)
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($sess)->get($url);
    }

    public function testAdminShopsReturnsVendorShops(): void
    {
        $this->grantAdmin(['product.view']);
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 2, 'name' => 'Main Shop']]; }
        });
        $r = $this->ajaxGet($this->adminSess(), 'admin/lookup/vendors/7/shops');
        $r->assertStatus(200);
        $json = $this->decode($r);
        $this->assertTrue($json['ok']);
        $this->assertSame('Main Shop', $json['data'][0]['name']);
    }

    public function testAdminCategoryDefaultsReturnsTaxAndHsn(): void
    {
        $this->grantAdmin(['product.view']);
        Services::injectMock('catalogLookupRepository', new class {
            public function defaultsForCategory(int $c): array { return ['tax_class_id' => 3, 'tax_class_name' => 'GST 12%', 'gst_pct' => '12.00', 'hsn_id' => 5, 'hsn_code' => '6403']; }
        });
        $r = $this->ajaxGet($this->adminSess(), 'admin/lookup/categories/9/defaults');
        $json = $this->decode($r);
        $this->assertSame('12.00', $json['data']['gst_pct']);
        $this->assertSame('6403', $json['data']['hsn_code']);
    }

    public function testAdminLookupDeniedWithoutPermission(): void
    {
        $this->grantAdmin(['order.view']);
        $r = $this->ajaxGet($this->adminSess(), 'admin/lookup/vendors/7/shops');
        $r->assertStatus(403);
    }

    public function testVendorShopsForcesOwnVendorId(): void
    {
        $this->grantVendor();
        $spy = new class {
            public int $askedFor = 0;
            public function list(int $v): array { $this->askedFor = $v; return [['id' => 9, 'name' => 'My Shop']]; }
        };
        Services::injectMock('vendorShopRepository', $spy);
        $r = $this->ajaxGet($this->vendorSess(), 'vendor/lookup/shops');
        $r->assertStatus(200);
        $this->assertSame(1, $spy->askedFor);   // forced to the logged-in vendor (id 1), never from input
    }

    public function testVendorAttributesRejectsForeignCategory(): void
    {
        $this->grantVendor();
        Services::injectMock('adminProductRepository', new class {
            public function allowedCategories(int $v): array { return [['id' => 10, 'name' => 'Allowed']]; } // 99 not allowed
        });
        $r = $this->ajaxGet($this->vendorSess(), 'vendor/lookup/categories/99/attributes');
        $r->assertStatus(403);
    }
}
