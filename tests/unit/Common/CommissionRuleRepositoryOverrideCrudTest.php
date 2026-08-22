<?php

declare(strict_types=1);

use App\Models\CommissionRuleRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

final class CommissionRuleRepositoryOverrideCrudTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureVendorCommissionOverridesTable();
    }

    public function testCreateThenFindOverride(): void
    {
        $repo = new CommissionRuleRepository();
        $id   = $repo->createOverride(['vendor_id' => 12, 'category_id' => null, 'rate' => 4.0, 'valid_from' => '2026-01-01', 'status' => 'active'], 7);

        $row = $repo->findOverride($id);
        $this->assertNotNull($row);
        $this->assertSame(4.0, (float) $row['rate']);
    }

    public function testListOverridesExcludesSoftDeleted(): void
    {
        $repo = new CommissionRuleRepository();
        $id   = $repo->createOverride(['vendor_id' => 13, 'category_id' => null, 'rate' => 1.0, 'valid_from' => '2026-01-01', 'status' => 'active'], 7);
        $repo->deleteOverride($id, 7);

        $this->assertNotContains($id, array_column($repo->listOverrides(), 'id'));
    }

    public function testUpdateOverrideChangesRate(): void
    {
        $repo = new CommissionRuleRepository();
        $id   = $repo->createOverride(['vendor_id' => 14, 'category_id' => null, 'rate' => 1.0, 'valid_from' => '2026-01-01', 'status' => 'active'], 7);

        $this->assertTrue($repo->updateOverride($id, ['rate' => 5.0], 7));
        $this->assertSame(5.0, (float) $repo->findOverride($id)['rate']);
    }

    public function testDeleteOverrideSoftDeletes(): void
    {
        $repo = new CommissionRuleRepository();
        $id   = $repo->createOverride(['vendor_id' => 15, 'category_id' => null, 'rate' => 1.0, 'valid_from' => '2026-01-01', 'status' => 'active'], 7);

        $this->assertTrue($repo->deleteOverride($id, 7));
        $this->assertNull($repo->findOverride($id));
    }
}
