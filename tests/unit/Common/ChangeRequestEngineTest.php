<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Governance/ChangeRequestStore.php';
require_once __DIR__ . '/../../../app/Libraries/Governance/ApplierRegistry.php';
require_once __DIR__ . '/../../../app/Libraries/Governance/ChangeRequestEngine.php';

use App\Libraries\Governance\ApplierRegistry;
use App\Libraries\Governance\ChangeRequestEngine;
use App\Libraries\Governance\ChangeRequestStore;

/** X1 — the universal approval spine (doc 44 §1: chains, G1–G7). */
final class ChangeRequestEngineTest extends TestCase
{
    private object $store;
    private ApplierRegistry $appliers;
    private array $audits  = [];
    private array $notices = [];

    protected function setUp(): void
    {
        $this->store = new class () implements ChangeRequestStore {
            public array $rows      = [];
            public array $decisions = [];
            private int $nextId     = 1;

            public function create(array $row): int
            {
                $id              = $this->nextId++;
                $row['id']       = $id;
                $this->rows[$id] = $row;

                return $id;
            }

            public function find(int $id): ?array
            {
                return $this->rows[$id] ?? null;
            }

            public function update(int $id, array $fields): void
            {
                $this->rows[$id] = array_merge($this->rows[$id], $fields);
            }

            public function addDecision(array $row): void
            {
                $this->decisions[] = $row;
            }

            public function hasOpen(string $entityType, ?int $entityId, string $fieldGroup, ?int $excludeId = null): bool
            {
                foreach ($this->rows as $r) {
                    if ($r['entity_type'] === $entityType && ($r['entity_id'] ?? null) === $entityId
                        && $r['field_group'] === $fieldGroup && ($excludeId === null || $r['id'] !== $excludeId)
                        && in_array($r['status'], ['pending_l1', 'pending_l2', 'changes_requested', 'approved', 'apply_failed'], true)) {
                        return true;
                    }
                }

                return false;
            }

            public function expireOverdue(string $nowSql): array
            {
                $out = [];
                foreach ($this->rows as $id => $r) {
                    if (in_array($r['status'], ['pending_l1', 'pending_l2'], true)
                        && ($r['sla_due_at'] ?? null) !== null && $r['sla_due_at'] < $nowSql) {
                        $this->rows[$id]['status'] = 'expired';
                        $out[] = $this->rows[$id];
                    }
                }

                return $out;
            }
        };

        $this->appliers = new ApplierRegistry();
        $this->audits   = [];
        $this->notices  = [];
    }

    private function engine(?callable $rules = null): ChangeRequestEngine
    {
        return new ChangeRequestEngine(
            $this->store,
            $rules,
            $this->appliers,
            function (array $e): void { $this->audits[] = $e; },
            function (string $event, array $req): void { $this->notices[] = $event; },
        );
    }

    private function staffSubmit(ChangeRequestEngine $e, array $over = []): array
    {
        return $e->submit($over + [
            'entity_type' => 'product', 'action' => 'price_change', 'entity_id' => 42,
            'payload_old' => ['price' => 100], 'payload_new' => ['price' => 120], 'vendor_id' => 7,
        ], ['user_id' => 11, 'role' => 'staff', 'vendor_id' => 7], new DateTimeImmutable('2026-06-12 10:00:00'));
    }

    public function testStaffSubmitEntersLevelOne(): void
    {
        $r = $this->staffSubmit($this->engine());

        $this->assertTrue($r['ok']);
        $this->assertSame('pending_l1', $r['status']);
        $row = $this->store->rows[$r['id']];
        $this->assertSame(['vendor', 'admin'], $row['required_levels']);
        $this->assertSame('2026-06-14 10:00:00', $row['sla_due_at']); // default 48h SLA
        $this->assertContains('request.submitted', $this->notices);
        $this->assertSame('request.submitted', $this->audits[0]['action']);
    }

    public function testVendorOwnerSubmissionSkipsOwnLevel(): void
    {
        $e = $this->engine();
        $r = $e->submit([
            'entity_type' => 'product', 'action' => 'create', 'payload_new' => ['name' => 'X'], 'vendor_id' => 7,
        ], ['user_id' => 5, 'role' => 'vendor', 'vendor_id' => 7]);

        $this->assertSame('pending_l2', $r['status']); // straight to admin
        $this->assertSame(2, $this->store->rows[$r['id']]['current_level']);
    }

    public function testFullChainApproveAppliesViaApplier(): void
    {
        $applied = [];
        $this->appliers->register('product.price_change', static function (array $req) use (&$applied): void {
            $applied[] = $req['entity_id'];
        });
        $e  = $this->engine();
        $id = $this->staffSubmit($e)['id'];

        $l1 = $e->decide($id, ['user_id' => 5, 'role' => 'vendor', 'vendor_id' => 7], 'approved');
        $this->assertSame('pending_l2', $l1['status']);

        $l2 = $e->decide($id, ['user_id' => 1, 'role' => 'admin'], 'approved');
        $this->assertSame('applied', $l2['status']);
        $this->assertSame([42], $applied);
        $this->assertNotNull($this->store->rows[$id]['applied_at']);
        $this->assertCount(2, $this->store->decisions);
    }

    public function testSelfApprovalForbidden(): void
    {
        $e  = $this->engine();
        $id = $this->staffSubmit($e)['id'];

        $r = $e->decide($id, ['user_id' => 11, 'role' => 'vendor', 'vendor_id' => 7], 'approved');
        $this->assertFalse($r['ok']);
        $this->assertSame('self_approval_forbidden', $r['error']);
    }

    public function testWrongRoleAndWrongVendorBlocked(): void
    {
        $e  = $this->engine();
        $id = $this->staffSubmit($e)['id'];

        $this->assertSame('wrong_approver_role', $e->decide($id, ['user_id' => 1, 'role' => 'admin'], 'approved')['error']);
        $this->assertSame('wrong_vendor', $e->decide($id, ['user_id' => 5, 'role' => 'vendor', 'vendor_id' => 99], 'approved')['error']);
    }

    public function testRejectRequiresReason(): void
    {
        $e  = $this->engine();
        $id = $this->staffSubmit($e)['id'];

        $this->assertSame('reason_required', $e->decide($id, ['user_id' => 5, 'role' => 'vendor', 'vendor_id' => 7], 'rejected')['error']);
        $r = $e->decide($id, ['user_id' => 5, 'role' => 'vendor', 'vendor_id' => 7], 'rejected', 'price too high');
        $this->assertSame('rejected', $r['status']);
    }

    public function testDuplicateOpenBlocked(): void
    {
        $e = $this->engine();
        $this->staffSubmit($e);
        $dup = $this->staffSubmit($e);

        $this->assertFalse($dup['ok']);
        $this->assertSame('duplicate_open', $dup['error']);
    }

    public function testChangesRequestedThenResubmitRestartsChain(): void
    {
        $e  = $this->engine();
        $id = $this->staffSubmit($e)['id'];

        $e->decide($id, ['user_id' => 5, 'role' => 'vendor', 'vendor_id' => 7], 'changes_requested', 'add justification');
        $this->assertSame('changes_requested', $this->store->rows[$id]['status']);

        $r = $e->resubmit($id, ['user_id' => 11], ['price' => 110]);
        $this->assertSame('pending_l1', $r['status']);
        $this->assertSame(['price' => 110], $this->store->rows[$id]['payload_new']);
    }

    public function testWithdrawOnlyByRequesterWhileOpen(): void
    {
        $e  = $this->engine();
        $id = $this->staffSubmit($e)['id'];

        $this->assertSame('not_requester', $e->withdraw($id, ['user_id' => 5])['error']);
        $this->assertSame('withdrawn', $e->withdraw($id, ['user_id' => 11])['status']);
        $this->assertSame('not_open', $e->withdraw($id, ['user_id' => 11])['error']);
    }

    public function testSingleLevelChainAppliesAfterVendorApproval(): void
    {
        $applied = false;
        $this->appliers->register('shop.settings', static function () use (&$applied): void { $applied = true; });
        $e = $this->engine();
        $r = $e->submit([
            'entity_type' => 'shop', 'action' => 'settings', 'entity_id' => 3,
            'payload_new' => ['radius_km' => 8], 'vendor_id' => 7,
        ], ['user_id' => 11, 'role' => 'manager', 'vendor_id' => 7]);

        $this->assertSame('pending_l1', $r['status']);
        $d = $e->decide($r['id'], ['user_id' => 5, 'role' => 'vendor', 'vendor_id' => 7], 'approved');
        $this->assertSame('applied', $d['status']);
        $this->assertTrue($applied);
    }

    public function testMissingApplierParksAtApproved(): void
    {
        $e  = $this->engine();
        $id = $this->staffSubmit($e)['id'];
        $e->decide($id, ['user_id' => 5, 'role' => 'vendor', 'vendor_id' => 7], 'approved');
        $r = $e->decide($id, ['user_id' => 1, 'role' => 'admin'], 'approved');

        $this->assertSame('approved', $r['status']);
        $this->assertSame('no_applier', $r['warning']);
        $this->assertSame('no_applier_registered', $this->store->rows[$id]['apply_error']);
    }

    public function testFailingApplierMarksApplyFailed(): void
    {
        $this->appliers->register('product.price_change', static function (): void {
            throw new RuntimeException('variant gone');
        });
        $e  = $this->engine();
        $id = $this->staffSubmit($e)['id'];
        $e->decide($id, ['user_id' => 5, 'role' => 'vendor', 'vendor_id' => 7], 'approved');
        $r = $e->decide($id, ['user_id' => 1, 'role' => 'admin'], 'approved');

        $this->assertSame('apply_failed', $r['status']);
        $this->assertSame('variant gone', $this->store->rows[$id]['apply_error']);
    }

    public function testAutoApproveRuleAppliesImmediately(): void
    {
        $applied = false;
        $this->appliers->register('product.price_change', static function () use (&$applied): void { $applied = true; });
        $rules = static fn () => ['levels' => null, 'sla_hours' => null, 'auto_approve' => true];

        $r = $this->staffSubmit($this->engine($rules));
        $this->assertSame('applied', $r['status']);
        $this->assertTrue($applied);
    }

    public function testRuleOverridesChainAndSla(): void
    {
        $rules = static fn () => ['levels' => ['vendor'], 'sla_hours' => 12, 'auto_approve' => false];
        $r     = $this->staffSubmit($this->engine($rules));
        $row   = $this->store->rows[$r['id']];

        $this->assertSame(['vendor'], $row['required_levels']);
        $this->assertSame('2026-06-12 22:00:00', $row['sla_due_at']);
    }

    public function testAdminOverrideRequiresReasonAndAudits(): void
    {
        $this->appliers->register('product.delete', static fn () => null);
        $e = $this->engine();

        $bad = $e->submit(['entity_type' => 'product', 'action' => 'delete', 'entity_id' => 1], ['user_id' => 1, 'role' => 'admin']);
        $this->assertSame('reason_required', $bad['error']);

        $ok = $e->submit(['entity_type' => 'product', 'action' => 'delete', 'entity_id' => 1, 'reason' => 'counterfeit listing'], ['user_id' => 1, 'role' => 'admin']);
        $this->assertSame('applied', $ok['status']);
        $this->assertSame('request.submitted.override', $this->audits[0]['action']);
    }

    public function testUnknownActionRejected(): void
    {
        $r = $this->engine()->submit(['entity_type' => 'galaxy', 'action' => 'explode'], ['user_id' => 1, 'role' => 'staff']);
        $this->assertSame('unknown_action', $r['error']);
    }

    public function testExpireDueSweepsOverdueOnly(): void
    {
        $e = $this->engine();
        $this->staffSubmit($e); // sla 2026-06-14 10:00
        $count = $e->expireDue(new DateTimeImmutable('2026-06-13 00:00:00'));
        $this->assertSame(0, $count);

        $count = $e->expireDue(new DateTimeImmutable('2026-06-15 00:00:00'));
        $this->assertSame(1, $count);
        $this->assertSame('expired', $this->store->rows[1]['status']);
        $this->assertContains('request.expired', $this->notices);
    }
}
