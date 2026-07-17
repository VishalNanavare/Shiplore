<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\Sync\SyncRegistry;

/**
 * SyncController — the generic sync endpoint used by POS terminals and mobile
 * apps for any entity. `pull` returns deltas since a cursor; `push` applies an
 * idempotent batch with conflict detection. JWT-authenticated.
 *
 * @see docs/architecture/35-SYNC-ENGINE.md
 */
final class SyncController extends BaseApiController
{
    public function pull()
    {
        $registry = new SyncRegistry();
        $entity   = (string) $this->request->getGet('entity');
        if (! $registry->isKnown($entity)) {
            return $this->failWith('VALIDATION_ERROR', 'Unknown sync entity: ' . $entity);
        }
        // Generic sync is terminal-scoped. Entities with no tenant column (customers,
        // orders, payments, refunds, …) are cross-tenant PII and are NOT pullable here.
        if ($registry->tenantColumn($entity) === null) {
            return $this->failWith('FORBIDDEN', 'Entity "' . $entity . '" is not available via the generic sync.');
        }
        $term = $this->terminal();
        if ($term === null) {
            return $this->failWith('NOT_FOUND', 'Unknown or unauthorized terminal.');
        }
        $since = $this->request->getGet('since');
        $res   = service('syncEngineRepository')->pull(
            $entity,
            is_string($since) ? $since : null,
            (int) $term['vendor_id'],
            (int) $term['shop_id'],
        );

        return $this->ok($res);
    }

    public function push()
    {
        $in    = $this->input();
        $key   = (string) ($in['idempotency_key'] ?? '');
        $items = (array) ($in['items'] ?? []);
        if ($key === '') {
            return $this->failWith('VALIDATION_ERROR', 'idempotency_key is required.');
        }
        if ($items === []) {
            return $this->failWith('VALIDATION_ERROR', 'items[] is empty.');
        }
        // A valid, actor-bound terminal is required (sync_batches/sync_conflicts.terminal_id NOT NULL).
        $term = $this->terminal();
        if ($term === null) {
            return $this->failWith('NOT_FOUND', 'A valid terminal_id bound to your account is required.');
        }
        $summary = service('syncEngineRepository')->applyBatch((int) $term['id'], $key, $items, $this->userId());

        return $this->ok($summary, ['replay' => $summary['replay']]);
    }

    /**
     * Resolve the terminal from the request, BOUND to the caller's vendor scope
     * (a token can only sync its own vendor's terminal). @return array<string,mixed>|null
     */
    private function terminal(): ?array
    {
        $id = (int) ($this->input()['terminal_id'] ?? $this->request->getGet('terminal_id') ?? 0);
        if ($id <= 0) {
            return null;
        }
        $term = service('posSyncRepository')->terminal($id);
        if ($term === null || $term['status'] !== 'active') {
            return null;
        }
        if (! $this->scope()->isPlatform() && ! in_array((int) $term['vendor_id'], $this->callerVendorIds(), true)) {
            return null;
        }

        return $term;
    }

    /** Vendor ids the caller may act for: owner OR staff (+ explicit scope). @return list<int> */
    private function callerVendorIds(): array
    {
        $repo = service('vendorAccountRepository');
        $ids  = $this->scope()->vendorIds();
        if (($o = $repo->findByOwnerUserId($this->userId())) !== null) {
            $ids[] = (int) $o['id'];
        }
        if (($s = $repo->findStaffVendor($this->userId())) !== null) {
            $ids[] = (int) $s['id'];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function entities()
    {
        return $this->ok(['entities' => (new SyncRegistry())->entities()]);
    }
}
