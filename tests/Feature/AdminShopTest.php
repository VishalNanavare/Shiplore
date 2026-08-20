<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * Phase 6 — Admin Shop management: list (RBAC-guarded), activate (CSRF),
 * permission-denied. Repositories mocked; webAuth session simulated.
 */
final class AdminShopTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        $this->grant(['shop.view', 'shop.update']);

        Services::injectMock('shopRepository', new class {
            public ?array $lastFilters = null;

            public function list(array|string|null $f = null): array
            {
                $this->lastFilters = is_array($f) ? $f : null;

                return [
                    ['id' => 1, 'name' => 'Andheri Outlet', 'code' => 'AND-1', 'pincode' => '400058', 'state_code' => '27', 'gstin_status' => 'verified', 'status' => 'inactive', 'vendor' => 'Acme Foods'],
                    ['id' => 2, 'name' => 'Bandra Outlet', 'code' => 'BAN-1', 'pincode' => '400050', 'state_code' => '27', 'gstin_status' => 'pending', 'status' => 'active', 'vendor' => 'Acme Foods'],
                ];
            }
            public function countList(array $f = []): int { $this->lastFilters = $f; return 2; }
            public function findById(int $id): ?array { return $id === 1 ? ['id' => 1, 'status' => 'inactive'] : null; }
            public function updateStatus(int $id, string $status, ?int $actorId = null): bool { return true; }
        });
        Services::injectMock('vendorRepository', new class {
            public function findById(int $id): ?array
            {
                return $id === 701 ? ['id' => 701, 'display_name' => 'Acme Foods'] : null;
            }
        });
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

    private function adminSession(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Super Admin', 'principal_type' => 'platform'];
    }

    public function testListRequiresLogin(): void
    {
        $this->get('admin/shops')->assertRedirect();
    }

    public function testListRenders(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/shops');
        $result->assertStatus(200);
        $this->assertStringContainsString('Andheri Outlet', (string) $result->getBody());
        $this->assertStringContainsString('shopsTable', (string) $result->getBody());
    }

    public function testListPassesTheVendorIdFilterThrough(): void
    {
        $repo = service('shopRepository');

        $this->withSession($this->adminSession())->get('admin/shops?vendor_id=701');

        $this->assertSame(701, $repo->lastFilters['vendor_id'] ?? null);
    }

    public function testListWithoutAVendorIdParamDoesNotScopeByVendor(): void
    {
        $repo = service('shopRepository');

        $this->withSession($this->adminSession())->get('admin/shops');

        $this->assertEmpty($repo->lastFilters['vendor_id'] ?? null);
    }

    public function testListShowsWhichVendorItIsFilteredTo(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/shops?vendor_id=701');
        $html   = (string) $result->getBody();

        $this->assertStringContainsString('Filtered to vendor:', $html);
        $this->assertStringContainsString('>Acme Foods<', $html);
    }

    public function testListWithoutAVendorFilterDoesNotShowTheVendorBanner(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/shops');

        $this->assertStringNotContainsString('Filtered to vendor:', (string) $result->getBody());
    }

    /**
     * Adversarial audit finding (2026-08-20, sub-project C, medium severity): the
     * "Go to Shop Portal" button built its confirm() message by embedding an
     * esc(...,'attr')-escaped shop name inside an onsubmit="return confirm('...')"
     * JS-string-in-HTML-attribute — HTML-attribute-escaping is the wrong context for
     * text that will be re-parsed as JS, because the browser HTML-decodes the
     * attribute before executing it, so a shop name containing a quote breaks out of
     * the string and executes as script in the viewing admin's session. A shop name
     * is vendor-controlled (a lower-privileged vendor renames their own shop), so
     * this is a real stored-XSS path. Fix: use ajax-forms.js's existing data-confirm
     * attribute convention instead, which never re-parses the text as code.
     *
     * Source-assertion, not an HTTP render: ShopController::show() runs raw SQL
     * ("FROM sub_orders", no DBPrefix substitution on raw query strings) that can't
     * resolve against the test DB's db_-prefixed tables, so this checks the
     * template's structural safety property directly instead.
     */
    public function testShowViewNoLongerBuildsTheConfirmMessageInlineInOnsubmit(): void
    {
        $src = file_get_contents(APPPATH . 'Views/admin/shops/show.php');
        $src = (string) preg_replace('#<\?php\s*/\*.*?\*/\s*\?>#s', '', (string) $src); // strip PHP block comments

        $this->assertStringNotContainsString('onsubmit="return confirm(', $src, 'the vulnerable inline-JS-in-attribute pattern must be gone');
        $this->assertMatchesRegularExpression('/data-confirm="Open the shop portal for[^"]*"/', $src, 'the safe data-confirm attribute must carry the message instead');
    }

    public function testActivateRedirectsBackToList(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/shops/1/activate', $data);
        $result->assertRedirect();
        $this->assertStringContainsString('admin/shops', $result->getRedirectUrl());
    }

    public function testPermissionDeniedRedirectsToDashboard(): void
    {
        $this->grant(['order.view']); // lacks shop.view
        $result = $this->withSession($this->adminSession())->get('admin/shops');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }
}
