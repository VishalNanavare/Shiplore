<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Audit L8 — the action filter on `audit_logs` (append-only, ever-growing) must be
 * a prefix match, not a leading-wildcard LIKE. Action codes are dotted prefixes
 * (`order.`, `product.`, `monline.`), so `like(..., 'after')` produces `action%`,
 * which `idx_audit_action` can serve as a range scan instead of a full scan.
 *
 * This is a deliberate semantic narrowing (searching `login` no longer matches
 * `auth.login`), accepted per the audit's own regression-risk note.
 */
final class AuditLogFilterTest extends CIUnitTestCase
{
    public function testActionFilterUsesPrefixMatchNotSubstringScan(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Models/AuditLogRepository.php');

        $this->assertStringContainsString(
            "->like('a.action', \$action, 'after')",
            $src,
            'a leading-wildcard LIKE (the like() default) cannot use idx_audit_action and forces a full scan on a table that only grows',
        );
    }
}
