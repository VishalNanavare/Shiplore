<?php

declare(strict_types=1);

use App\Models\CommissionRuleRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

final class CommissionRuleRepositoryOverrideTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureVendorCommissionOverridesTable();
        Database::connect()->table('vendor_commission_overrides')->where('vendor_id', 9101)->delete();
    }

    public function testCategorySpecificOverrideBeatsVendorWide(): void
    {
        $db = Database::connect();
        $db->table('vendor_commission_overrides')->insertBatch([
            ['vendor_id' => 9101, 'category_id' => null, 'rate' => '4.00', 'valid_from' => '2026-01-01', 'status' => 'active'],
            ['vendor_id' => 9101, 'category_id' => 55, 'rate' => '3.00', 'valid_from' => '2026-01-01', 'status' => 'active'],
        ]);

        $repo = new CommissionRuleRepository();
        $row  = $repo->activeVendorOverride(9101, 55);

        $this->assertNotNull($row);
        $this->assertSame(3.0, (float) $row['rate']);
    }

    public function testVendorWideOverrideAppliesWhenNoCategorySpecificMatch(): void
    {
        Database::connect()->table('vendor_commission_overrides')->insert(
            ['vendor_id' => 9101, 'category_id' => null, 'rate' => '4.00', 'valid_from' => '2026-01-01', 'status' => 'active'],
        );

        $repo = new CommissionRuleRepository();
        $row  = $repo->activeVendorOverride(9101, 999);

        $this->assertNotNull($row);
        $this->assertSame(4.0, (float) $row['rate']);
    }

    public function testExpiredOverrideIsIgnored(): void
    {
        Database::connect()->table('vendor_commission_overrides')->insert(
            ['vendor_id' => 9101, 'category_id' => 55, 'rate' => '3.00', 'valid_from' => '2020-01-01', 'valid_to' => '2020-12-31', 'status' => 'active'],
        );

        $repo = new CommissionRuleRepository();
        $this->assertNull($repo->activeVendorOverride(9101, 55));
    }

    public function testStatusExpiredIsIgnoredEvenWithinDateRange(): void
    {
        Database::connect()->table('vendor_commission_overrides')->insert(
            ['vendor_id' => 9101, 'category_id' => 55, 'rate' => '3.00', 'valid_from' => '2020-01-01', 'status' => 'expired'],
        );

        $repo = new CommissionRuleRepository();
        $this->assertNull($repo->activeVendorOverride(9101, 55));
    }

    public function testNullWhenNoOverrideAtAll(): void
    {
        $repo = new CommissionRuleRepository();
        $this->assertNull($repo->activeVendorOverride(9101, 55));
    }
}
