<?php

declare(strict_types=1);

use App\Libraries\Governance\ChangeRequestEngine;
use App\Libraries\Governance\ChangeRequestStore;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The manufacturer approvals flow, checked against the REAL engine.
 *
 * Every one of these covers a defect that shipped and that the controller tests did
 * not catch — because those mock changeRequestEngine, and a mock happily accepts a
 * role the real engine rejects, a chain key the real engine has never heard of, and a
 * foreign key the real database enforces. Mocking the collaborator is exactly what
 * hid three blocking bugs here, so this file deliberately does not.
 */
final class ManufacturerGovernanceWiringTest extends CIUnitTestCase
{
    private function baseSrc(): string
    {
        return (string) file_get_contents(APPPATH . 'Controllers/Manufacturer/BaseManufacturerController.php');
    }

    /** Source with comments stripped — these docblocks discuss the very strings asserted on. */
    private function code(string $rel): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents(APPPATH . $rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }

        return $out;
    }

    /**
     * DEFECT 1 — the owner deadlock.
     *
     * The engine's tenant-owner level is the literal string 'vendor'.
     * selfSkips('vendor', $role) is true ONLY for 'vendor', so an owner submitting with
     * role 'manufacturer' does not skip their own approval level: the request sits at
     * pending_l1, G1 forbids the owner deciding their own request, and a manager fails
     * ROLE_FOR_LEVEL. The request can never be approved by anyone.
     */
    public function testOwnerSubmissionsSkipTheirOwnApprovalLevel(): void
    {
        // A real in-memory ChangeRequestStore, so the ENGINE under test is the real one.
        $engine = new ChangeRequestEngine(new class implements ChangeRequestStore {
            /** @var array<int,array<string,mixed>> */
            public array $rows = [];

            public function create(array $row): int
            {
                $this->rows[1] = $row + ['id' => 1];

                return 1;
            }

            public function find(int $id): ?array { return $this->rows[$id] ?? null; }

            public function update(int $id, array $fields): void
            {
                $this->rows[$id] = ($this->rows[$id] ?? []) + $fields;
            }

            public function addDecision(array $row): void {}

            public function hasOpen(string $entityType, ?int $entityId, string $fieldGroup, ?int $excludeId = null): bool
            {
                return false;
            }

            public function expireOverdue(string $nowSql): array { return []; }
        });

        $res = $engine->submit(
            ['entity_type' => 'mfg_staff', 'action' => 'create', 'payload_new' => ['data' => []], 'vendor_id' => 7],
            ['user_id' => 1, 'role' => 'vendor', 'vendor_id' => 7],
        );

        $this->assertTrue($res['ok'], 'the owner submit must be accepted');
        $this->assertNotSame(
            'pending_l1',
            $res['status'] ?? null,
            "an owner's own request must not queue for its own approval — nobody could ever decide it",
        );
    }

    /** ...and the base controller must actually return that role for an owner. */
    public function testActorRoleReturnsTheEnginesOwnerLevel(): void
    {
        $src = $this->code('Controllers/Manufacturer/BaseManufacturerController.php');

        $this->assertMatchesRegularExpression(
            '/isOwner\(\)\)\s*\{\s*return\s+\'vendor\';/',
            $src,
            "actorRole() must return 'vendor' for the owner — 'manufacturer' matches no engine level",
        );
    }

    /**
     * DEFECT 2 — the foreign key.
     *
     * change_requests.shop_id FKs `shops(id)` (30_governance.sql). An mshop is not a
     * shop, and the two share a numeric id space, so writing an mshop id there either
     * violates the constraint or silently tags the request with an unrelated vendor's
     * shop.
     */
    public function testChangeRequestsNeverCarryAnMshopIdInTheShopColumn(): void
    {
        $src = $this->code('Controllers/Manufacturer/BaseManufacturerController.php');

        $this->assertMatchesRegularExpression(
            "/'shop_id'\s*=>\s*null,/",
            $src,
            'shop_id must be null for a manufacturer request — it is a foreign key to `shops`',
        );
        $this->assertDoesNotMatchRegularExpression(
            "/'shop_id'\s*=>[^,]*mshop/i",
            $src,
            'an mshop id must never be written into change_requests.shop_id',
        );

        // And the constraint that makes this matter must still exist.
        $sql = (string) file_get_contents(ROOTPATH . 'database/sql/30_governance.sql');
        $this->assertStringContainsString('FOREIGN KEY (`shop_id`) REFERENCES `shops`', $sql);
    }

    /**
     * DEFECT 3 — the cross-panel write, the worst of the three.
     *
     * staff.create / staff.role_change / staff.terminate are registered in
     * Config\Services::governanceAppliers() against vendorStaffRepository. A
     * manufacturer request approved under those keys writes to the VENDOR staff
     * tables — silently, with no error, into another panel's data.
     */
    public function testManufacturerStaffRequestsUseTheirOwnChainKeys(): void
    {
        $ctrl = $this->code('Controllers/Manufacturer/StaffController.php');

        foreach (['create', 'role_change', 'terminate'] as $action) {
            $this->assertMatchesRegularExpression(
                "/'mfg_staff',\s*\n?\s*'{$action}'|'mfg_staff',\s*'{$action}'/",
                $ctrl,
                "StaffController must submit mfg_staff.{$action}, not the vendor staff.{$action} key",
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            "/submitChangeRequest\(\s*\n?\s*'staff',/",
            $ctrl,
            'submitting the bare staff.* key routes a manufacturer approval into the vendor staff tables',
        );
    }

    /** The new keys must exist in the chain map, or every submit returns unknown_action. */
    public function testTheNewChainKeysAreRegistered(): void
    {
        foreach (['mfg_staff.create', 'mfg_staff.role_change', 'mfg_staff.terminate'] as $key) {
            $this->assertArrayHasKey($key, ChangeRequestEngine::CHAINS, "{$key} is missing from CHAINS");
            $this->assertSame(['vendor'], ChangeRequestEngine::CHAINS[$key], "{$key} is decided by the tenant owner alone");
        }
    }

    /**
     * ONE applier's closure body, bounded by the NEXT registration.
     *
     * Not a fixed-size window: these registrations sit back to back, so a window wide
     * enough to hold one body reaches into the next one — and the neighbour mentions
     * the same repository, so the assertion passes even when the body under test has
     * been repointed. A mutation run caught exactly that. Scope to the real boundary.
     */
    private function applierBody(string $services, string $key): string
    {
        $needle = "register('{$key}'";
        $from   = strpos($services, $needle);
        $this->assertNotFalse($from, "no applier registered for {$key}");

        $next = strpos($services, '$registry->register(', (int) $from + strlen($needle));

        return $next === false
            ? substr($services, (int) $from)
            : substr($services, (int) $from, $next - (int) $from);
    }

    /** ...and each must have an applier pointing at the MANUFACTURER repository. */
    public function testTheNewKeysApplyThroughTheManufacturerRepository(): void
    {
        $services = $this->code('Config/Services.php');

        foreach (['create', 'role_change', 'terminate'] as $action) {
            $body = $this->applierBody($services, "mfg_staff.{$action}");

            $this->assertStringContainsString(
                'manufacturerStaffRepository()',
                $body,
                "mfg_staff.{$action} must apply through manufacturerStaffRepository",
            );
            $this->assertStringNotContainsString(
                'vendorStaffRepository()',
                $body,
                "mfg_staff.{$action} must NOT touch the vendor staff tables — that is the cross-panel write this key exists to prevent",
            );
        }
    }

    /** The vendor appliers must be left exactly as they were. */
    public function testVendorStaffAppliersAreUntouched(): void
    {
        $services = $this->code('Config/Services.php');

        foreach (['staff.create', 'staff.role_change', 'staff.terminate'] as $key) {
            $body = $this->applierBody($services, $key);

            $this->assertStringContainsString(
                'vendorStaffRepository()',
                $body,
                "{$key} must still apply through vendorStaffRepository",
            );
            $this->assertStringNotContainsString(
                'manufacturerStaffRepository()',
                $body,
                "{$key} is the VENDOR key and must not have been repointed",
            );
        }
    }
}
