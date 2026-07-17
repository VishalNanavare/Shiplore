<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * ApprovalRuleRepository — wires the (previously unused) approval_rules table
 * as the change-request routing matrix. Most-specific rule wins:
 * vendor+category > vendor > category > global, then priority DESC.
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §1.3
 */
final class ApprovalRuleRepository
{
    /**
     * @return array{levels:?list<string>,sla_hours:?int,auto_approve:bool,sensitive_fields:?array}|null
     */
    public function resolve(string $entityType, string $action, ?int $vendorId = null, ?int $categoryId = null): ?array
    {
        $rows = Database::connect()->table('approval_rules')
            ->where('status', 'active')->where('deleted_at', null)
            ->where('entity_type', $entityType)->where('action', $action)
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

        $best = $rows[0];

        return [
            'levels'           => isset($best['levels']) && $best['levels'] !== null ? json_decode((string) $best['levels'], true) : null,
            'sla_hours'        => $best['sla_hours'] !== null ? (int) $best['sla_hours'] : null,
            'auto_approve'     => (bool) $best['auto_approve'],
            'sensitive_fields' => isset($best['sensitive_fields']) && $best['sensitive_fields'] !== null ? json_decode((string) $best['sensitive_fields'], true) : null,
        ];
    }
}
