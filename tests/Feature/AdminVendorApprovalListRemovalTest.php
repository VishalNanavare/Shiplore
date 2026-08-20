<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * The general admin/vendors LIST no longer renders Approve/Reject at all, regardless
 * of status — they moved to the dedicated "Pending Approval → Vendor Approval" queue
 * (admin/vendor-approvals). This is the fix for the actual reported bug: Reject stayed
 * clickable on an already-approved/active vendor row (only Approve was ever disabled
 * there), which a live screenshot showed. Removing the buttons entirely — rather than
 * fixing the disabled condition — means there is no per-row status logic left on this
 * page to get wrong.
 */
final class AdminVendorApprovalListRemovalTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');

        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => ['vendor.view'], 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
        Services::injectMock('vendorRepository', new class {
            public function list(array $f = [], int $limit = 50, int $offset = 0): array
            {
                return [
                    ['id' => 1, 'display_name' => 'Approved Co', 'slug' => 'approved-co', 'gstin' => null, 'gstin_status' => 'unverified', 'status' => 'approved', 'business_type' => 'Grocery'],
                    ['id' => 2, 'display_name' => 'Pending Co', 'slug' => 'pending-co', 'gstin' => null, 'gstin_status' => 'unverified', 'status' => 'submitted', 'business_type' => 'Grocery'],
                ];
            }

            public function countList(array $f = []): int { return 2; }
        });
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Admin', 'principal_type' => 'platform'];
    }

    public function testNeitherApproveNorRejectFormsAppearOnTheGeneralList(): void
    {
        $html = (string) $this->withSession($this->sess())->get('admin/vendors')->getBody();

        $this->assertStringNotContainsString("vendors/1/approve", $html);
        $this->assertStringNotContainsString("vendors/1/reject", $html);
        $this->assertStringNotContainsString("vendors/2/approve", $html);
        $this->assertStringNotContainsString("vendors/2/reject", $html);
    }

    /** Positive anchor — the removal must not have silently broken the whole page. */
    public function testTheListStillRendersAndStillShowsOtherActions(): void
    {
        $r = $this->withSession($this->sess())->get('admin/vendors');

        $r->assertStatus(200);
        $html = (string) $r->getBody();
        $this->assertStringContainsString('vendors/1/edit', $html);
        $this->assertStringContainsString('vendors/1/documents', $html);
    }
}
