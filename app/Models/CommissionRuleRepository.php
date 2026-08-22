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
}
