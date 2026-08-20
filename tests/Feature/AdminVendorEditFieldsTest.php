<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * VendorController::update() — vendor edit-form completeness. Before this, the edit
 * form only wrote legal_name/display_name/business_type_id (+logo); pan, state_code,
 * commission_plan_id, payout_cycle and is_composition existed as real vendors columns
 * with no admin UI at all. This proves the controller reads, normalises and forwards
 * the five new fields to VendorRepository::update(); the write itself (and the
 * payout_cycle whitelist) is covered against a real DB by
 * VendorRepositoryUpdateFieldsTest.
 */
final class AdminVendorEditFieldsTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use MinimalSchema;

    private object $repo;

    protected function setUp(): void
    {
        parent::setUp();
        // VendorController::update() always checks AdminProductRepository::allowedCategories()
        // against the real DB (not the mocked vendorRepository) to warn about now-disallowed
        // product categories — unrelated to what this test covers, but it must not error.
        $this->ensureCategoriesTable();
        $this->ensureVendorsTable();
        $this->ensureBusinessTypesTable();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');

        $this->repo = new class {
            public array $vendor = ['id' => 501, 'party_type' => 'vendor', 'status' => 'active', 'business_type_id' => 2, 'legal_name' => 'Acme Pvt Ltd', 'display_name' => 'Acme', 'gstin' => '27AAAAA0000A1Z5'];
            public ?array $lastUpdate = null;

            public function find(int $id): ?array { return $id === $this->vendor['id'] ? $this->vendor : null; }

            public function update(int $id, array $d, ?int $actorId = null): bool
            {
                $this->lastUpdate = $d;

                return true;
            }
        };
        Services::injectMock('vendorRepository', $this->repo);
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
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Admin', 'principal_type' => 'platform'];
    }

    private function submit(array $post): \CodeIgniter\Test\TestResponse
    {
        return $this->withSession($this->sess())->post('admin/vendors/501/update', $post + [csrf_token() => csrf_hash()]);
    }

    public function testUpdateForwardsTheNewProfileFieldsNormalised(): void
    {
        $this->grant(['vendor.settings.manage']);

        $this->submit([
            'legal_name' => 'Acme Pvt Ltd', 'display_name' => 'Acme', 'business_type_id' => '3',
            'pan' => 'abcde1234f', 'state_code' => 'mh', 'commission_plan_id' => '7',
            'payout_cycle' => 'monthly', 'is_composition' => '1',
        ]);

        $this->assertSame([
            'legal_name' => 'Acme Pvt Ltd', 'display_name' => 'Acme', 'business_type_id' => 3,
            'pan' => 'ABCDE1234F', 'state_code' => 'MH', 'commission_plan_id' => 7,
            'payout_cycle' => 'monthly', 'is_composition' => true,
        ], $this->repo->lastUpdate);
    }

    /**
     * The controller passes raw (trimmed/uppercased) input through; blank-to-null and
     * checkbox-to-0/1 normalisation happens in VendorRepository::update() itself (see
     * VendorRepositoryUpdateFieldsTest) so every caller gets it, not just this one.
     */
    public function testBlankOptionalFieldsPassThroughUnnormalised(): void
    {
        $this->grant(['vendor.settings.manage']);

        $this->submit([
            'legal_name' => 'Acme', 'display_name' => 'Acme', 'business_type_id' => '3',
            'pan' => '', 'state_code' => '', 'commission_plan_id' => '', 'payout_cycle' => 'weekly',
        ]);

        $this->assertSame('', $this->repo->lastUpdate['pan']);
        $this->assertSame('', $this->repo->lastUpdate['state_code']);
        $this->assertSame(0, $this->repo->lastUpdate['commission_plan_id']);
        $this->assertFalse($this->repo->lastUpdate['is_composition'], 'an unchecked checkbox must not be sent at all');
    }

    public function testAnEmptyPayoutCycleDefaultsToWeeklyRatherThanBeingSentBlank(): void
    {
        $this->grant(['vendor.settings.manage']);

        $this->submit([
            'legal_name' => 'Acme', 'display_name' => 'Acme', 'business_type_id' => '3',
            'payout_cycle' => '',
        ]);

        $this->assertSame('weekly', $this->repo->lastUpdate['payout_cycle']);
    }

    public function testEditFormRendersTheNewFieldsPreFilled(): void
    {
        $this->grant(['vendor.settings.manage']);
        $this->repo->vendor += ['pan' => 'ABCDE1234F', 'state_code' => '27', 'commission_plan_id' => 4, 'payout_cycle' => 'biweekly', 'is_composition' => 1];
        Services::injectMock('commissionRepository', new class {
            public function list(?string $status = null): array { return [['id' => 4, 'name' => 'Standard 10%'], ['id' => 5, 'name' => 'Premium 5%']]; }
        });
        Services::injectMock('businessTypeRepository', new class {
            public function list(?string $status = null): array { return [['id' => 2, 'name' => 'Retail']]; }
        });

        // form() joins media_assets for the logo preview via a raw query, not a
        // mockable repository, so it has to exist for real.
        $this->ensureMediaAssetsTable();

        $r = $this->withSession($this->sess())->get('admin/vendors/501/edit');

        $r->assertStatus(200);
        $html = (string) $r->getBody();
        $this->assertStringContainsString('name="pan"', $html);
        $this->assertStringContainsString('value="ABCDE1234F"', $html);
        $this->assertStringContainsString('name="state_code"', $html);
        $this->assertStringContainsString('name="commission_plan_id"', $html);
        $this->assertStringContainsString('Standard 10%', $html);
        $this->assertStringContainsString('name="payout_cycle"', $html);
        $this->assertStringContainsString('name="is_composition"', $html);
    }

    public function testARepositoryUpdateFailureIsSurfacedAsAnError(): void
    {
        $this->grant(['vendor.settings.manage']);
        $failing = new class {
            public array $vendor = ['id' => 501, 'party_type' => 'vendor', 'business_type_id' => 2];

            public function find(int $id): ?array { return $this->vendor; }

            public function update(int $id, array $d, ?int $actorId = null): bool { return false; }
        };
        Services::injectMock('vendorRepository', $failing);

        $r = $this->submit(['legal_name' => 'Acme', 'display_name' => 'Acme', 'business_type_id' => '3']);

        $r->assertRedirect();
        $this->assertNotNull(session()->getFlashdata('error'));
    }
}
