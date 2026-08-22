<?php

declare(strict_types=1);

use App\Libraries\Commission\CommissionRuleResolver;
use App\Models\CommissionRuleRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * The operator's own reported example, and the 3-level case that was the actual bug:
 * a category nested three or more levels deep with no rate on the leaf silently skipped
 * any rate on an intermediate ancestor.
 */
final class CommissionRuleResolverTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureProductsTable();
        $this->ensureCategoriesTable();
        $this->ensureVendorsTable();
        $this->ensureBusinessTypesTable();
        $this->ensureCommissionPlansTable();
        $this->ensureCommissionRulesTable();
        $this->ensureVendorCommissionOverridesTable();
        $this->ensureSubOrdersTable();

        $db = Database::connect();
        $db->table('commission_plans')->where('id', 1)->delete();
        $db->table('commission_plans')->insert(['id' => 1, 'name' => 'Standard', 'default_rate' => '10.00', 'valid_from' => '2024-01-01', 'status' => 'active']);
    }

    /** Women Clothes (5%, root) -> Short Top (8%, child) -> resolves to 8%. */
    public function testTwoLevelCaseFromTheOperatorsOwnExample(): void
    {
        $db = Database::connect();
        $db->table('categories')->insert(['id' => 10, 'name' => 'Women Clothes', 'slug' => 'women-clothes', 'path' => '/10/', 'level' => 0]);
        $db->table('categories')->insert(['id' => 11, 'name' => 'Short Top', 'slug' => 'short-top', 'parent_id' => 10, 'path' => '/10/11/', 'level' => 1]);
        $db->table('commission_rules')->insertBatch([
            ['commission_plan_id' => 1, 'category_id' => 10, 'rate' => '5.00'],
            ['commission_plan_id' => 1, 'category_id' => 11, 'rate' => '8.00'],
        ]);
        $db->table('vendors')->insert(['id' => 1, 'legal_name' => 'v', 'display_name' => 'V', 'status' => 'active']);
        $db->table('products')->insert(['id' => 1, 'vendor_id' => 1, 'category_id' => 11, 'title' => 'Short Top A', 'status' => 'published', 'is_online_enabled' => 0]);

        $resolved = (new CommissionRuleResolver(new CommissionRuleRepository()))->resolveForProduct(1);

        $this->assertSame(8.0, $resolved['value']);
        $this->assertSame('rule:category', $resolved['level']);
    }

    /** Women Clothes (5%) -> Tops (no rate) -> Short Top (no rate) -> resolves to 5%, not global. */
    public function testThreeLevelCaseWalksPastUnsetAncestors(): void
    {
        $db = Database::connect();
        $db->table('categories')->insert(['id' => 20, 'name' => 'Women Clothes', 'slug' => 'wc2', 'path' => '/20/', 'level' => 0]);
        $db->table('categories')->insert(['id' => 21, 'name' => 'Tops', 'slug' => 'tops', 'parent_id' => 20, 'path' => '/20/21/', 'level' => 1]);
        $db->table('categories')->insert(['id' => 22, 'name' => 'Short Top', 'slug' => 'short-top-2', 'parent_id' => 21, 'path' => '/20/21/22/', 'level' => 2]);
        $db->table('commission_rules')->insert(['commission_plan_id' => 1, 'category_id' => 20, 'rate' => '5.00']);
        $db->table('vendors')->insert(['id' => 2, 'legal_name' => 'v', 'display_name' => 'V', 'status' => 'active']);
        $db->table('products')->insert(['id' => 2, 'vendor_id' => 2, 'category_id' => 22, 'title' => 'Short Top B', 'status' => 'published', 'is_online_enabled' => 0]);

        $resolved = (new CommissionRuleResolver(new CommissionRuleRepository()))->resolveForProduct(2);

        $this->assertSame(5.0, $resolved['value']);
        $this->assertSame('rule:category', $resolved['level']);
    }

    public function testProductLevelRuleOutranksCategory(): void
    {
        $db = Database::connect();
        $db->table('categories')->insert(['id' => 30, 'name' => 'Cat30', 'slug' => 'cat30', 'path' => '/30/', 'level' => 0]);
        $db->table('commission_rules')->insert(['commission_plan_id' => 1, 'category_id' => 30, 'rate' => '5.00']);
        $db->table('commission_rules')->insert(['commission_plan_id' => 1, 'product_id' => 3, 'rate' => '12.00']);
        $db->table('vendors')->insert(['id' => 3, 'legal_name' => 'v', 'display_name' => 'V', 'status' => 'active']);
        $db->table('products')->insert(['id' => 3, 'vendor_id' => 3, 'category_id' => 30, 'title' => 'P3', 'status' => 'published', 'is_online_enabled' => 0]);

        $resolved = (new CommissionRuleResolver(new CommissionRuleRepository()))->resolveForProduct(3);

        $this->assertSame(12.0, $resolved['value']);
        $this->assertSame('rule:product', $resolved['level']);
    }

    public function testVendorOverrideOutranksProductLevelRule(): void
    {
        $db = Database::connect();
        $db->table('categories')->insert(['id' => 40, 'name' => 'Cat40', 'slug' => 'cat40', 'path' => '/40/', 'level' => 0]);
        $db->table('commission_rules')->insert(['commission_plan_id' => 1, 'product_id' => 4, 'rate' => '12.00']);
        $db->table('vendor_commission_overrides')->insert(['vendor_id' => 4, 'category_id' => 40, 'rate' => '3.00', 'valid_from' => '2024-01-01', 'status' => 'active']);
        $db->table('vendors')->insert(['id' => 4, 'legal_name' => 'v', 'display_name' => 'V', 'status' => 'active']);
        $db->table('products')->insert(['id' => 4, 'vendor_id' => 4, 'category_id' => 40, 'title' => 'P4', 'status' => 'published', 'is_online_enabled' => 0]);

        $resolved = (new CommissionRuleResolver(new CommissionRuleRepository()))->resolveForProduct(4);

        $this->assertSame(3.0, $resolved['value']);
        $this->assertSame('vendor_override', $resolved['level']);
    }

    public function testExpiredOverrideFallsThroughToRules(): void
    {
        $db = Database::connect();
        $db->table('categories')->insert(['id' => 50, 'name' => 'Cat50', 'slug' => 'cat50', 'path' => '/50/', 'level' => 0]);
        $db->table('commission_rules')->insert(['commission_plan_id' => 1, 'category_id' => 50, 'rate' => '6.00']);
        $db->table('vendor_commission_overrides')->insert(['vendor_id' => 5, 'category_id' => 50, 'rate' => '3.00', 'valid_from' => '2020-01-01', 'valid_to' => '2020-12-31', 'status' => 'active']);
        $db->table('vendors')->insert(['id' => 5, 'legal_name' => 'v', 'display_name' => 'V', 'status' => 'active']);
        $db->table('products')->insert(['id' => 5, 'vendor_id' => 5, 'category_id' => 50, 'title' => 'P5', 'status' => 'published', 'is_online_enabled' => 0]);

        $resolved = (new CommissionRuleResolver(new CommissionRuleRepository()))->resolveForProduct(5);

        $this->assertSame(6.0, $resolved['value']);
        $this->assertSame('rule:category', $resolved['level']);
    }

    public function testBusinessTypeRuleAppliesWhenNoProductOrCategoryMatch(): void
    {
        $db = Database::connect();
        $db->table('business_types')->insert(['id' => 6, 'code' => 'bt6', 'name' => 'BT6']);
        $db->table('categories')->insert(['id' => 60, 'name' => 'Cat60', 'slug' => 'cat60', 'path' => '/60/', 'level' => 0]);
        $db->table('commission_rules')->insert(['commission_plan_id' => 1, 'business_type_id' => 6, 'rate' => '4.00']);
        $db->table('vendors')->insert(['id' => 6, 'legal_name' => 'v', 'display_name' => 'V', 'business_type_id' => 6, 'status' => 'active']);
        $db->table('products')->insert(['id' => 6, 'vendor_id' => 6, 'category_id' => 60, 'title' => 'P6', 'status' => 'published', 'is_online_enabled' => 0]);

        $resolved = (new CommissionRuleResolver(new CommissionRuleRepository()))->resolveForProduct(6);

        $this->assertSame(4.0, $resolved['value']);
        $this->assertSame('rule:business_type', $resolved['level']);
    }

    public function testFallsThroughToGlobalPlanDefault(): void
    {
        $db = Database::connect();
        $db->table('categories')->insert(['id' => 70, 'name' => 'Cat70', 'slug' => 'cat70', 'path' => '/70/', 'level' => 0]);
        $db->table('vendors')->insert(['id' => 7, 'legal_name' => 'v', 'display_name' => 'V', 'status' => 'active']);
        $db->table('products')->insert(['id' => 7, 'vendor_id' => 7, 'category_id' => 70, 'title' => 'P7', 'status' => 'published', 'is_online_enabled' => 0]);

        $resolved = (new CommissionRuleResolver(new CommissionRuleRepository()))->resolveForProduct(7);

        $this->assertSame(10.0, $resolved['value']);
        $this->assertSame('plan:global', $resolved['level']);
    }

    public function testGmvTierMatchingPicksTheRangeTheVendorFallsInto(): void
    {
        $db = Database::connect();
        $db->table('categories')->insert(['id' => 80, 'name' => 'Cat80', 'slug' => 'cat80', 'path' => '/80/', 'level' => 0]);
        $db->table('commission_rules')->insert(['commission_plan_id' => 1, 'category_id' => 80, 'rate' => '5.00', 'max_gmv' => '9999']);
        $db->table('commission_rules')->insert(['commission_plan_id' => 1, 'category_id' => 80, 'rate' => '3.00', 'min_gmv' => '10000']);
        $db->table('vendors')->insert(['id' => 8, 'legal_name' => 'v', 'display_name' => 'V', 'status' => 'active']);
        $db->table('products')->insert(['id' => 8, 'vendor_id' => 8, 'category_id' => 80, 'title' => 'P8', 'status' => 'published', 'is_online_enabled' => 0]);
        $recent = date('Y-m-d H:i:s', strtotime('-2 days'));
        $db->table('sub_orders')->insert(['order_id' => 1, 'vendor_id' => 8, 'shop_id' => 1, 'grand_total' => '15000', 'place_of_supply' => 'MH', 'status' => 'delivered', 'created_at' => $recent]);

        $resolved = (new CommissionRuleResolver(new CommissionRuleRepository()))->resolveForProduct(8);

        $this->assertSame(3.0, $resolved['value']);
    }
}
