<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * ReturnPolicyRepository — resolves the return-window days that gate
 * commission accrual (X2a). Most-specific active row wins:
 * vendor+category > vendor > category > global, then priority DESC.
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §8 C1
 */
final class ReturnPolicyRepository
{
    public function windowDays(?int $vendorId = null, ?int $categoryId = null): ?int
    {
        $rows = Database::connect()->table('return_policies')
            ->where('status', 'active')
            ->groupStart()->where('vendor_id', $vendorId)->orWhere('vendor_id IS NULL', null, false)->groupEnd()
            ->groupStart()->where('category_id', $categoryId)->orWhere('category_id IS NULL', null, false)->groupEnd()
            ->get()->getResultArray();

        if ($rows === []) {
            return null;
        }

        usort($rows, static function (array $a, array $b) use ($vendorId, $categoryId): int {
            $score = static fn (array $r): int => (($r['vendor_id'] !== null && (int) $r['vendor_id'] === $vendorId) ? 2 : 0)
                + (($r['category_id'] !== null && (int) $r['category_id'] === $categoryId) ? 1 : 0);

            return [$score($b), (int) $b['priority']] <=> [$score($a), (int) $a['priority']];
        });

        return (int) $rows[0]['days'];
    }
}
