<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * CommissionRuleRepository — data access for the Track B commission-depth build:
 * commission_rules (product/category/business_type scoped rules with GMV tiers and
 * priority) and vendor_commission_overrides (negotiated per-vendor rates). Both tables
 * existed, fully designed, entirely unwired before this build — confirmed dead via a
 * full git log --all -S search during Track A's investigation.
 *
 * @see \App\Libraries\Commission\CommissionRuleResolver — the pure orchestrator that
 *      calls this in priority order.
 */
final class CommissionRuleRepository
{
    /**
     * Trailing-N-day GMV for a vendor, computed at resolution time (not a maintained
     * counter) — self-correcting if historical orders are refunded/cancelled later.
     * Excludes cancelled/returned orders: they never completed a sale.
     */
    public function trailingGmv(int $vendorId, int $days = 30): float
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $row = Database::connect()->table('sub_orders')
            ->selectSum('grand_total', 'total')
            ->where('vendor_id', $vendorId)
            ->where('created_at >=', $since)
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->get()->getRowArray();

        return (float) ($row['total'] ?? 0.0);
    }

    /**
     * Best-matching active override for this vendor: a row scoped to the product's
     * leaf category beats a vendor-wide (category_id IS NULL) row when both exist.
     * @return array<string,mixed>|null
     */
    public function activeVendorOverride(int $vendorId, int $categoryId): ?array
    {
        $today = date('Y-m-d');
        $base  = Database::connect()->table('vendor_commission_overrides')
            ->where('vendor_id', $vendorId)->where('status', 'active')->where('deleted_at', null)
            ->where('valid_from <=', $today)
            ->groupStart()->where('valid_to', null)->orWhere('valid_to >=', $today)->groupEnd();

        $specific = (clone $base)->where('category_id', $categoryId)->get()->getRowArray();
        if ($specific !== null) {
            return $specific;
        }

        return $base->where('category_id', null)->get()->getRowArray();
    }

    public function ruleForProduct(int $productId, float $gmv): ?array
    {
        return $this->matchRule('product_id', $productId, $gmv);
    }

    public function ruleForCategory(int $categoryId, float $gmv): ?array
    {
        return $this->matchRule('category_id', $categoryId, $gmv);
    }

    public function ruleForBusinessType(int $businessTypeId, float $gmv): ?array
    {
        return $this->matchRule('business_type_id', $businessTypeId, $gmv);
    }

    /**
     * Shared matcher for the three commission_rules scopes. Only 'percentage'/'fixed'
     * commission_type rows are ever matched — 'slab'/'exception' are out of scope for
     * this resolver (no design exists for them), so they are simply excluded here,
     * never half-applied.
     * @return array<string,mixed>|null
     */
    private function matchRule(string $scopeColumn, int $scopeId, float $gmv): ?array
    {
        $today = date('Y-m-d');

        return Database::connect()->table('commission_rules')
            ->where($scopeColumn, $scopeId)->where('deleted_at', null)
            ->whereIn('commission_type', ['percentage', 'fixed'])
            ->groupStart()->where('effective_from', null)->orWhere('effective_from <=', $today)->groupEnd()
            ->groupStart()->where('effective_to', null)->orWhere('effective_to >=', $today)->groupEnd()
            ->groupStart()->where('min_gmv', null)->orWhere('min_gmv <=', $gmv)->groupEnd()
            ->groupStart()->where('max_gmv', null)->orWhere('max_gmv >=', $gmv)->groupEnd()
            ->orderBy('priority', 'DESC')->orderBy('id', 'ASC')
            ->get()->getRowArray();
    }

    /** @return list<array<string,mixed>> */
    public function listRules(): array
    {
        return Database::connect()->table('commission_rules')->where('deleted_at', null)
            ->orderBy('priority', 'DESC')->orderBy('id', 'DESC')->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findRule(int $id): ?array
    {
        $row = Database::connect()->table('commission_rules')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();

        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function createRule(array $data, ?int $actorId): int
    {
        $db = Database::connect();
        $db->table('commission_rules')->insert(array_merge($data, ['created_by' => $actorId]));

        return (int) $db->insertID();
    }

    /** @param array<string,mixed> $data */
    public function updateRule(int $id, array $data, ?int $actorId): bool
    {
        return Database::connect()->table('commission_rules')->where('id', $id)->where('deleted_at', null)
            ->update(array_merge($data, ['updated_by' => $actorId]));
    }

    public function deleteRule(int $id, ?int $actorId = null): bool
    {
        return Database::connect()->table('commission_rules')->where('id', $id)->where('deleted_at', null)
            ->update(['deleted_at' => date('Y-m-d H:i:s'), 'updated_by' => $actorId]);
    }
}
