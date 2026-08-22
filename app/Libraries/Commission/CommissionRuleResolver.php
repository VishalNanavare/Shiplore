<?php

declare(strict_types=1);

namespace App\Libraries\Commission;

use App\Models\CommissionRuleRepository;
use Config\Database;

/**
 * CommissionRuleResolver — the Track B 5-step resolution order:
 *   1. vendor_commission_overrides (active, category-specific beats vendor-wide)
 *   2. commission_rules matched to product_id
 *   3. commission_rules matched to category_id, walking leaf -> every ancestor
 *   4. commission_rules matched to business_type_id
 *   5. commission_plans.default_rate (global fallback)
 * Each commission_rules step is itself GMV-tier + priority aware (delegated to
 * CommissionRuleRepository). Pure orchestration: no writes, no side effects.
 *
 * Deliberately NOT final (unlike this codebase's usual repository/resolver
 * convention) — CommissionServiceTest stubs it via an anonymous subclass rather
 * than faking the underlying CommissionRuleRepository, since that repository IS
 * final and already has its own direct SQLite-backed tests. Re-testing its query
 * logic through a second layer of mocks here would be redundant, not more correct.
 */
class CommissionRuleResolver
{
    public function __construct(private readonly CommissionRuleRepository $rules) {}

    /** @return array{type:string,value:float,level:string} */
    public function resolveForProduct(int $productId): array
    {
        $db = Database::connect();
        $product = $db->table('products')->select('category_id, vendor_id')->where('id', $productId)->get()->getRowArray();
        if ($product === null) {
            return ['type' => 'percentage', 'value' => 0.0, 'level' => 'none'];
        }

        $vendor = $db->table('vendors')->select('business_type_id')->where('id', $product['vendor_id'])->get()->getRowArray();
        $leafCategoryId = (int) $product['category_id'];

        // Step 1: vendor override.
        $override = $this->rules->activeVendorOverride((int) $product['vendor_id'], $leafCategoryId);
        if ($override !== null) {
            return ['type' => 'percentage', 'value' => (float) $override['rate'], 'level' => 'vendor_override'];
        }

        $gmv = $this->rules->trailingGmv((int) $product['vendor_id']);

        // Step 2: product-scoped rule.
        $rule = $this->rules->ruleForProduct($productId, $gmv);
        if ($rule !== null) {
            return $this->fromRule($rule, 'rule:product');
        }

        // Step 3: category-scoped rule, leaf -> every ancestor.
        foreach ($this->categoryChain($leafCategoryId) as $categoryId) {
            $rule = $this->rules->ruleForCategory($categoryId, $gmv);
            if ($rule !== null) {
                return $this->fromRule($rule, 'rule:category');
            }
        }

        // Step 4: business-type-scoped rule.
        if ($vendor !== null && $vendor['business_type_id'] !== null) {
            $rule = $this->rules->ruleForBusinessType((int) $vendor['business_type_id'], $gmv);
            if ($rule !== null) {
                return $this->fromRule($rule, 'rule:business_type');
            }
        }

        // Step 5: global plan default.
        $plan = $db->table('commission_plans')->select('default_rate')
            ->where('status', 'active')->where('deleted_at', null)->orderBy('id', 'ASC')->get()->getRowArray();
        if ($plan !== null && $plan['default_rate'] !== null) {
            return ['type' => 'percentage', 'value' => (float) $plan['default_rate'], 'level' => 'plan:global'];
        }

        return ['type' => 'percentage', 'value' => 0.0, 'level' => 'none'];
    }

    /** @return array{type:string,value:float,level:string} */
    private function fromRule(array $rule, string $level): array
    {
        return $rule['commission_type'] === 'fixed'
            ? ['type' => 'fixed', 'value' => (float) $rule['fixed_amount'], 'level' => $level]
            : ['type' => 'percentage', 'value' => (float) $rule['rate'], 'level' => $level];
    }

    /**
     * Leaf category id first, then every ancestor up to root, from categories.path
     * (materialized path of ids, e.g. "/5/12/47/", root->leaf).
     * @return list<int>
     */
    private function categoryChain(int $leafCategoryId): array
    {
        $row = Database::connect()->table('categories')->select('path')->where('id', $leafCategoryId)->get()->getRowArray();
        if ($row === null || ($row['path'] ?? '') === '') {
            return [$leafCategoryId];
        }

        $ids = array_values(array_filter(explode('/', (string) $row['path'])));

        return array_reverse(array_map('intval', $ids));
    }
}
