<?php

declare(strict_types=1);

namespace App\Libraries\Governance;

use DateTimeImmutable;
use Throwable;

/**
 * ChangeRequestEngine — the universal staff→vendor(→admin) approval spine
 * (Phase X1). One engine serves product changes, shop settings, staff
 * lifecycle, transfers, combos and POS promotions; per-action chains come
 * from the canonical AR-matrix below, overridable per vendor/category via
 * the approval_rules table.
 *
 * Governance contract (doc 44 §1.3): no self-approval (G1); one open request
 * per entity+field-group (G2); SLA expiry (G3); withdraw (G4); admin override
 * is itself a recorded+audited request (G6); apply executes the SAME domain
 * service as the direct path, via ApplierRegistry (G7).
 *
 * Everything is injected (store, rule resolver, appliers, audit, notify) so
 * the state machine is fully unit-testable without a database.
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §1
 */
final class ChangeRequestEngine
{
    /** Canonical default chains (AR-01…AR-15). Key: "entity.action". */
    public const CHAINS = [
        'product.create'          => ['vendor', 'admin'],
        'product.update'          => ['vendor', 'admin'],
        'product.submit'          => ['vendor', 'admin'],
        'product.delete'          => ['vendor', 'admin'],
        'product.restore'         => ['vendor', 'admin'],
        'product.price_change'    => ['vendor', 'admin'],
        'product.mrp_change'      => ['vendor', 'admin'],
        'product.barcode_change'  => ['vendor', 'admin'],
        'product.gst_change'      => ['vendor', 'admin'],
        'product.category_change' => ['vendor', 'admin'],
        'product.promote_online'  => ['vendor', 'admin'],
        'combo.create_pos'        => ['vendor'],
        'combo.create_online'     => ['vendor', 'admin'],
        'inventory.receive'       => ['vendor'],
        'inventory.adjust'        => ['vendor'],
        'shop.settings'           => ['vendor'],
        'shop.hours'              => ['vendor'],
        'shop.holiday'            => ['vendor'],
        'shop.online_status'      => ['vendor'],
        'staff.create'            => ['vendor'],
        'staff.role_change'       => ['vendor'],
        'staff.transfer'          => ['vendor'],
        'staff.terminate'         => ['vendor'],
        'rider.create'            => ['vendor'],
        // Manufacturer staff get their OWN keys rather than reusing staff.* above.
        // Those three are registered in Config\Services::governanceAppliers() against
        // vendorStaffRepository, so a manufacturer request approved under them would
        // write to the VENDOR staff tables — a silent cross-panel write, not a no-op.
        // Same single tenant-owner level; different applier.
        'mfg_staff.create'        => ['vendor'],
        'mfg_staff.role_change'   => ['vendor'],
        'mfg_staff.terminate'     => ['vendor'],
        // A branch manager pulling stock from a sibling store needs the vendor's
        // sign-off (the vendor owns all the stock). A dedicated source-shop
        // manager level is a future refinement.
        'transfer.request'        => ['vendor'],
    ];

    /** Actor role that satisfies each chain level. */
    private const ROLE_FOR_LEVEL = ['vendor' => 'vendor', 'admin' => 'admin', 'source_manager' => 'manager'];

    private const DEFAULT_SLA_HOURS = 48;

    /** @var callable|null fn(entity, action, ?vendorId, ?categoryId): ?array{levels:?array,sla_hours:?int,auto_approve:bool} */
    private $ruleResolver;

    /** @var callable|null fn(array auditEntry): mixed */
    private $audit;

    /** @var callable|null fn(string event, array request): mixed */
    private $notify;

    public function __construct(
        private readonly ChangeRequestStore $store,
        ?callable $ruleResolver = null,
        private readonly ApplierRegistry $appliers = new ApplierRegistry(),
        ?callable $audit = null,
        ?callable $notify = null,
    ) {
        $this->ruleResolver = $ruleResolver;
        $this->audit        = $audit;
        $this->notify       = $notify;
    }

    /**
     * Submit a change request.
     *
     * @param array<string,mixed> $in    entity_type*, action*, entity_id, field_group,
     *                                   payload_old, payload_new, reason, vendor_id, shop_id, category_id
     * @param array<string,mixed> $actor user_id*, role* (staff|manager|vendor|admin), vendor_id, shop_id, ip, device_id
     * @return array<string,mixed> {ok, id?, status?, error?}
     */
    public function submit(array $in, array $actor, ?DateTimeImmutable $now = null): array
    {
        $now    = $now ?? new DateTimeImmutable('now');
        $key    = $in['entity_type'] . '.' . $in['action'];
        $rule   = $this->ruleResolver !== null
            ? ($this->ruleResolver)($in['entity_type'], $in['action'], $in['vendor_id'] ?? null, $in['category_id'] ?? null)
            : null;
        $levels = $rule['levels'] ?? self::CHAINS[$key] ?? null;

        if ($levels === null || $levels === []) {
            return ['ok' => false, 'error' => 'unknown_action'];
        }
        if ($this->store->hasOpen((string) $in['entity_type'], $in['entity_id'] ?? null, (string) ($in['field_group'] ?? 'default'))) {
            return ['ok' => false, 'error' => 'duplicate_open'];
        }

        // G1 bootstrap: skip leading levels the submitter would decide themself.
        $start = 0;
        $role  = (string) ($actor['role'] ?? 'staff');
        while ($start < count($levels) && $this->selfSkips($levels[$start], $role)) {
            $start++;
        }

        $auto     = $start >= count($levels) || (bool) ($rule['auto_approve'] ?? false);
        $override = $role === 'admin' && $start >= count($levels);
        if ($override && trim((string) ($in['reason'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'reason_required']; // G6: overrides always carry a reason
        }

        $slaHours = (int) ($rule['sla_hours'] ?? self::DEFAULT_SLA_HOURS);
        $row      = [
            'entity_type'     => (string) $in['entity_type'],
            'entity_id'       => $in['entity_id'] ?? null,
            'action'          => (string) $in['action'],
            'field_group'     => (string) ($in['field_group'] ?? 'default'),
            'payload_old'     => $in['payload_old'] ?? null,
            'payload_new'     => $in['payload_new'] ?? null,
            'reason'          => $in['reason'] ?? null,
            'requested_by'    => (int) $actor['user_id'],
            'requester_role'  => $role,
            'vendor_id'       => $in['vendor_id'] ?? ($actor['vendor_id'] ?? null),
            'shop_id'         => $in['shop_id'] ?? ($actor['shop_id'] ?? null),
            'current_level'   => min($start + 1, count($levels)),
            'required_levels' => $levels,
            'status'          => $auto ? 'approved' : 'pending_l' . ($start + 1),
            'sla_due_at'      => $auto ? null : $now->modify("+{$slaHours} hours")->format('Y-m-d H:i:s'),
            'created_at'      => $now->format('Y-m-d H:i:s'),
        ];

        $id        = $this->store->create($row);
        $row['id'] = $id;

        $this->emitAudit('request.submitted' . ($override ? '.override' : ''), $row, $actor, null, $row['reason'] ?? null);

        if ($auto) {
            $result = $this->tryApply($id, $row, $actor, $now);
            $this->emitNotify('request.' . $result['status'], $row + ['status' => $result['status']]);

            return ['ok' => true, 'id' => $id] + $result;
        }

        $this->emitNotify('request.submitted', $row);

        return ['ok' => true, 'id' => $id, 'status' => $row['status']];
    }

    /**
     * Decide the current pending level.
     *
     * @param array<string,mixed> $actor user_id*, role*, vendor_id, ip, device_id
     * @return array<string,mixed> {ok, status?, error?}
     */
    public function decide(int $id, array $actor, string $decision, ?string $reason = null, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now');
        $req = $this->store->find($id);
        if ($req === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (! in_array($req['status'], ['pending_l1', 'pending_l2'], true)) {
            return ['ok' => false, 'error' => 'not_pending'];
        }
        if (! in_array($decision, ['approved', 'rejected', 'changes_requested'], true)) {
            return ['ok' => false, 'error' => 'bad_decision'];
        }
        if (in_array($decision, ['rejected', 'changes_requested'], true) && trim((string) $reason) === '') {
            return ['ok' => false, 'error' => 'reason_required'];
        }

        $level     = (int) $req['current_level'];
        $levels    = (array) $req['required_levels'];
        $levelRole = (string) $levels[$level - 1];

        // G1 — the requester can never decide any level of their own request.
        if ((int) $actor['user_id'] === (int) $req['requested_by']) {
            return ['ok' => false, 'error' => 'self_approval_forbidden'];
        }
        if ((self::ROLE_FOR_LEVEL[$levelRole] ?? '') !== ($actor['role'] ?? '')) {
            return ['ok' => false, 'error' => 'wrong_approver_role'];
        }
        // Vendor-level decisions must come from the request's own vendor.
        if ($levelRole === 'vendor' && ($req['vendor_id'] ?? null) !== null
            && (int) ($actor['vendor_id'] ?? 0) !== (int) $req['vendor_id']) {
            return ['ok' => false, 'error' => 'wrong_vendor'];
        }

        $this->store->addDecision([
            'change_request_id' => $id,
            'level_no'          => $level,
            'approver_role'     => $levelRole,
            'approver_user_id'  => (int) $actor['user_id'],
            'decision'          => $decision,
            'reason'            => $reason,
            'ip'                => $actor['ip'] ?? null,
            'device_id'         => $actor['device_id'] ?? null,
            'decided_at'        => $now->format('Y-m-d H:i:s'),
        ]);

        if ($decision === 'rejected') {
            $this->store->update($id, ['status' => 'rejected']);
            $this->emitAudit('request.rejected', $req, $actor, $level, $reason);
            $this->emitNotify('request.rejected', $req + ['status' => 'rejected']);

            return ['ok' => true, 'status' => 'rejected'];
        }

        if ($decision === 'changes_requested') {
            $this->store->update($id, ['status' => 'changes_requested']);
            $this->emitAudit('request.changes_requested', $req, $actor, $level, $reason);
            $this->emitNotify('request.changes_requested', $req + ['status' => 'changes_requested']);

            return ['ok' => true, 'status' => 'changes_requested'];
        }

        // approved at this level
        $this->emitAudit('request.approved', $req, $actor, $level, $reason);

        if ($level < count($levels)) {
            $next = $level + 1;
            $this->store->update($id, ['status' => 'pending_l' . $next, 'current_level' => $next]);
            $this->emitNotify('request.pending_l' . $next, $req + ['status' => 'pending_l' . $next]);

            return ['ok' => true, 'status' => 'pending_l' . $next];
        }

        $result = $this->tryApply($id, $req, $actor, $now);
        $this->emitNotify('request.' . $result['status'], $req + ['status' => $result['status']]);

        return ['ok' => true] + $result;
    }

    /** Resubmit after changes_requested — restarts the chain with new payload. */
    public function resubmit(int $id, array $actor, array $payloadNew, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now');
        $req = $this->store->find($id);
        if ($req === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ($req['status'] !== 'changes_requested') {
            return ['ok' => false, 'error' => 'not_resubmittable'];
        }
        if ((int) $actor['user_id'] !== (int) $req['requested_by']) {
            return ['ok' => false, 'error' => 'not_requester'];
        }

        $levels = (array) $req['required_levels'];
        $start  = 0;
        while ($start < count($levels) && $this->selfSkips($levels[$start], (string) $req['requester_role'])) {
            $start++;
        }
        $status = 'pending_l' . ($start + 1);
        $this->store->update($id, [
            'payload_new'   => $payloadNew,
            'status'        => $status,
            'current_level' => $start + 1,
            'sla_due_at'    => $now->modify('+' . self::DEFAULT_SLA_HOURS . ' hours')->format('Y-m-d H:i:s'),
        ]);
        $this->emitAudit('request.resubmitted', $req, $actor, null, null);
        $this->emitNotify('request.resubmitted', $req + ['status' => $status]);

        return ['ok' => true, 'status' => $status];
    }

    /** G4 — requester withdraws while still open. */
    public function withdraw(int $id, array $actor): array
    {
        $req = $this->store->find($id);
        if ($req === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ((int) $actor['user_id'] !== (int) $req['requested_by']) {
            return ['ok' => false, 'error' => 'not_requester'];
        }
        if (! in_array($req['status'], ['pending_l1', 'pending_l2', 'changes_requested'], true)) {
            return ['ok' => false, 'error' => 'not_open'];
        }

        $this->store->update($id, ['status' => 'withdrawn']);
        $this->emitAudit('request.withdrawn', $req, $actor, null, null);
        $this->emitNotify('request.withdrawn', $req + ['status' => 'withdrawn']);

        return ['ok' => true, 'status' => 'withdrawn'];
    }

    /** G3 — SLA sweep; runs from the scheduled_tasks handler `governance.expire_sla`. */
    public function expireDue(?DateTimeImmutable $now = null): int
    {
        $now     = $now ?? new DateTimeImmutable('now');
        $expired = $this->store->expireOverdue($now->format('Y-m-d H:i:s'));

        foreach ($expired as $req) {
            $this->emitAudit('request.expired', $req, ['user_id' => null, 'role' => 'system'], null, 'SLA breached');
            $this->emitNotify('request.expired', $req + ['status' => 'expired']);
        }

        return count($expired);
    }

    // ------------------------------------------------------------------

    /** @return array{status:string,warning?:string} */
    private function tryApply(int $id, array $req, array $actor, DateTimeImmutable $now): array
    {
        $key = $req['entity_type'] . '.' . $req['action'];
        $fn  = $this->appliers->get($key);

        if ($fn === null) {
            // G7 staged build: stay 'approved' until a feature registers its applier.
            $this->store->update($id, ['status' => 'approved', 'apply_error' => 'no_applier_registered']);

            return ['status' => 'approved', 'warning' => 'no_applier'];
        }

        try {
            $fn($req + ['id' => $id]);
            $this->store->update($id, ['status' => 'applied', 'apply_error' => null, 'applied_at' => $now->format('Y-m-d H:i:s')]);
            $this->emitAudit('request.applied', $req, $actor, null, null);

            return ['status' => 'applied'];
        } catch (Throwable $e) {
            $this->store->update($id, ['status' => 'apply_failed', 'apply_error' => mb_substr($e->getMessage(), 0, 255)]);
            $this->emitAudit('request.apply_failed', $req, $actor, null, $e->getMessage());

            return ['status' => 'apply_failed', 'warning' => $e->getMessage()];
        }
    }

    /**
     * A leading chain level is skipped when the submitter IS that decider (G1),
     * and an admin submitter outranks every level (G6 — override, reason-gated).
     */
    private function selfSkips(string $levelRole, string $actorRole): bool
    {
        if ($actorRole === 'admin') {
            return true; // platform supremacy: all lower levels subsumed
        }

        return match ($levelRole) {
            'vendor' => $actorRole === 'vendor',
            'admin'  => false,
            default  => false, // source_manager is a different person by construction
        };
    }

    private function emitAudit(string $action, array $req, array $actor, ?int $level, ?string $reason): void
    {
        if ($this->audit === null) {
            return;
        }
        try {
            ($this->audit)([
                'action'         => $action,
                'entity_type'    => 'change_request',
                'entity_id'      => $req['id'] ?? null,
                'actor_user_id'  => $actor['user_id'] ?? null,
                'actor_role'     => $actor['role'] ?? null,
                'principal_type' => ($actor['role'] ?? '') === 'admin' ? 'platform' : 'vendor',
                'scope_type'     => ($req['vendor_id'] ?? null) !== null ? 'vendor' : 'platform',
                'scope_id'       => $req['vendor_id'] ?? null,
                'ip'             => $actor['ip'] ?? null,
                'device_id'      => $actor['device_id'] ?? null,
                'approval_level' => $level,
                'reason'         => $reason,
                'before'         => $req['payload_old'] ?? null,
                'after'          => $req['payload_new'] ?? null,
                'metadata'       => ['request_action' => ($req['entity_type'] ?? '') . '.' . ($req['action'] ?? ''), 'request_no' => $req['request_no'] ?? null],
            ]);
        } catch (Throwable) {
            // audit must never break the business transition; checkpoints reconcile
        }
    }

    private function emitNotify(string $event, array $req): void
    {
        if ($this->notify === null) {
            return;
        }
        try {
            ($this->notify)($event, $req);
        } catch (Throwable) {
            // notification failure is non-fatal
        }
    }
}
