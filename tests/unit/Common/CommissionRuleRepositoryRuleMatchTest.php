<?php

declare(strict_types=1);

use App\Models\CommissionRuleRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

final class CommissionRuleRepositoryRuleMatchTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureCommissionRulesTable();
        Database::connect()->table('commission_rules')->where('commission_plan_id', 1)->delete();
    }

    private function insertRule(array $overrides): void
    {
        Database::connect()->table('commission_rules')->insert(array_merge([
            'commission_plan_id' => 1, 'rate' => '10.00', 'commission_type' => 'percentage', 'priority' => 0,
        ], $overrides));
    }

    public function testMatchesByProductWithinGmvRange(): void
    {
        $this->insertRule(['product_id' => 501, 'rate' => '8.00']);

        $repo = new CommissionRuleRepository();
        $row  = $repo->ruleForProduct(501, 1000.0);

        $this->assertNotNull($row);
        $this->assertSame(8.0, (float) $row['rate']);
    }

    public function testProductRuleOutsideGmvRangeDoesNotMatch(): void
    {
        $this->insertRule(['product_id' => 501, 'rate' => '8.00', 'min_gmv' => '5000']);

        $repo = new CommissionRuleRepository();
        $this->assertNull($repo->ruleForProduct(501, 1000.0));
    }

    public function testHighestPriorityWinsAmongProductTies(): void
    {
        $this->insertRule(['product_id' => 502, 'rate' => '5.00', 'priority' => 1]);
        $this->insertRule(['product_id' => 502, 'rate' => '9.00', 'priority' => 5]);

        $repo = new CommissionRuleRepository();
        $row  = $repo->ruleForProduct(502, 0.0);

        $this->assertSame(9.0, (float) $row['rate']);
    }

    public function testMatchesByCategory(): void
    {
        $this->insertRule(['category_id' => 55, 'rate' => '7.50']);

        $repo = new CommissionRuleRepository();
        $row  = $repo->ruleForCategory(55, 0.0);

        $this->assertNotNull($row);
        $this->assertSame(7.5, (float) $row['rate']);
    }

    public function testMatchesByBusinessType(): void
    {
        $this->insertRule(['business_type_id' => 3, 'rate' => '6.00']);

        $repo = new CommissionRuleRepository();
        $row  = $repo->ruleForBusinessType(3, 0.0);

        $this->assertNotNull($row);
        $this->assertSame(6.0, (float) $row['rate']);
    }

    public function testFixedTypeRuleReturnsFixedAmount(): void
    {
        $this->insertRule(['category_id' => 55, 'commission_type' => 'fixed', 'fixed_amount' => '25.0000', 'rate' => '0']);

        $repo = new CommissionRuleRepository();
        $row  = $repo->ruleForCategory(55, 0.0);

        $this->assertSame('fixed', $row['commission_type']);
        $this->assertSame(25.0, (float) $row['fixed_amount']);
    }

    public function testExpiredRuleDoesNotMatch(): void
    {
        $this->insertRule(['category_id' => 56, 'effective_to' => '2020-01-01']);

        $repo = new CommissionRuleRepository();
        $this->assertNull($repo->ruleForCategory(56, 0.0));
    }

    public function testSlabAndExceptionTypesAreNeverMatched(): void
    {
        $this->insertRule(['category_id' => 57, 'commission_type' => 'slab']);
        $this->insertRule(['category_id' => 57, 'commission_type' => 'exception']);

        $repo = new CommissionRuleRepository();
        $this->assertNull($repo->ruleForCategory(57, 0.0));
    }
}
