<?php

declare(strict_types=1);

use App\Models\CommissionRuleRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

final class CommissionRuleRepositoryCrudTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureCommissionRulesTable();
    }

    public function testCreateThenFindRule(): void
    {
        $repo = new CommissionRuleRepository();
        $id   = $repo->createRule(['commission_plan_id' => 1, 'category_id' => 5, 'rate' => 6.5, 'commission_type' => 'percentage', 'priority' => 0], 7);

        $row = $repo->findRule($id);
        $this->assertNotNull($row);
        $this->assertSame(6.5, (float) $row['rate']);
    }

    public function testListRulesExcludesSoftDeleted(): void
    {
        $repo = new CommissionRuleRepository();
        $id   = $repo->createRule(['commission_plan_id' => 1, 'category_id' => 6, 'rate' => 1.0, 'commission_type' => 'percentage'], 7);
        $repo->deleteRule($id, 7);

        $ids = array_column($repo->listRules(), 'id');
        $this->assertNotContains($id, $ids);
    }

    public function testUpdateRuleChangesRate(): void
    {
        $repo = new CommissionRuleRepository();
        $id   = $repo->createRule(['commission_plan_id' => 1, 'category_id' => 7, 'rate' => 1.0, 'commission_type' => 'percentage'], 7);

        $this->assertTrue($repo->updateRule($id, ['rate' => 9.0], 7));
        $this->assertSame(9.0, (float) $repo->findRule($id)['rate']);
    }

    public function testDeleteRuleSoftDeletes(): void
    {
        $repo = new CommissionRuleRepository();
        $id   = $repo->createRule(['commission_plan_id' => 1, 'category_id' => 8, 'rate' => 1.0, 'commission_type' => 'percentage'], 7);

        $this->assertTrue($repo->deleteRule($id, 7));
        $this->assertNull($repo->findRule($id));
    }
}
