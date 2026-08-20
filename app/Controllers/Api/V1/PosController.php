<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;

/**
 * PosController — sync API for the offline-first Windows POS (ASP.NET C#).
 * JWT-authenticated (cashier); all data scoped to the activated terminal's shop
 * (branch-level isolation). Device activation, catalog/stock download, delta
 * pull, idempotent invoice push (offline queue reconciliation), shift open/close
 * (daily closing) and customer lookup.
 *
 * @see docs/architecture/34-POS-WINDOWS.md
 */
final class PosController extends BaseApiController
{
    public function activate()
    {
        $in = $this->input();

        // Every other method in this class resolves the terminal through terminal(),
        // which binds it to callerVendorIds(). activate() did not, and matched on the
        // activation code alone — so any principal with a valid JWT, including a
        // self-registered storefront customer, could bind their device fingerprint to
        // another tenant's POS terminal. Scope the lookup to the caller's own vendors.
        $term = service('posSyncRepository')->activate(
            (string) ($in['activation_code'] ?? ''),
            (string) ($in['device_fingerprint'] ?? ''),
            $this->callerVendorIds(),
        );

        // Same message either way: a caller must not be able to tell "wrong code" from
        // "not your terminal", which would turn this into an activation-code oracle.
        return $term === null ? $this->failWith('NOT_FOUND', 'Invalid or inactive activation code.') : $this->ok($term);
    }

    public function bootstrap()
    {
        $term = $this->terminal();
        if ($term === null) {
            return $this->failWith('NOT_FOUND', 'Unknown terminal.');
        }

        return $this->ok(service('posSyncRepository')->bootstrap($term));
    }

    public function pull()
    {
        $term = $this->terminal();
        if ($term === null) {
            return $this->failWith('NOT_FOUND', 'Unknown terminal.');
        }
        $since = $this->request->getGet('since');

        return $this->ok(service('posSyncRepository')->pull($term, is_string($since) ? $since : null));
    }

    public function push()
    {
        $in   = $this->input();
        $term = $this->terminal();
        if ($term === null) {
            return $this->failWith('NOT_FOUND', 'Unknown terminal.');
        }
        $sales = (array) ($in['sales'] ?? []);
        if ($sales === []) {
            return $this->failWith('VALIDATION_ERROR', 'No sales in payload.');
        }
        $shiftId = isset($in['shift_id']) ? (int) $in['shift_id'] : null;
        $results = service('posSyncRepository')->push($term, $shiftId, $this->userId(), $sales);

        $synced = count(array_filter($results, static fn ($r) => $r['status'] === 'synced'));

        return $this->ok(['results' => $results], ['synced' => $synced, 'received' => count($results)]);
    }

    public function shiftOpen()
    {
        $in   = $this->input();
        $term = $this->terminal();
        if ($term === null) {
            return $this->failWith('NOT_FOUND', 'Unknown terminal.');
        }
        // Idempotent: never open a second concurrent shift on the same terminal.
        $open = service('posSyncRepository')->currentShift((int) $term['id']);
        if ($open !== null) {
            return $this->failWith('CONFLICT', 'A shift is already open on this terminal.', ['shift_id' => (int) $open['id']]);
        }
        $shiftId = service('posSyncRepository')->openShift((int) $term['id'], $this->userId(), (float) ($in['opening_float'] ?? 0));

        return $this->created(['shift_id' => $shiftId]);
    }

    public function shiftClose()
    {
        $in   = $this->input();
        $term = $this->terminal();
        if ($term === null) {
            return $this->failWith('NOT_FOUND', 'Unknown terminal.');
        }
        // Scope the shift to THIS terminal so a cashier can't close/read another shop's Z-report.
        $report = service('posSyncRepository')->closeShift((int) ($in['shift_id'] ?? 0), (float) ($in['counted_cash'] ?? 0), (int) $term['id']);

        return $report === null ? $this->failWith('NOT_FOUND', 'Open shift not found.') : $this->ok($report);
    }

    public function shiftCurrent()
    {
        $term = $this->terminal();
        if ($term === null) {
            return $this->failWith('NOT_FOUND', 'Unknown terminal.');
        }

        return $this->ok(['shift' => service('posSyncRepository')->currentShift((int) $term['id'])]);
    }

    public function customers()
    {
        $term = $this->terminal();
        if ($term === null) {
            return $this->failWith('NOT_FOUND', 'Unknown terminal.');
        }
        $q = trim((string) $this->request->getGet('q'));
        if (mb_strlen($q) < 3) {
            return $this->failWith('VALIDATION_ERROR', 'Query must be at least 3 characters.');
        }
        // PII is scoped to customers who have transacted with this terminal's vendor.
        $items = service('posSyncRepository')->customers($q, (int) $term['vendor_id']);

        return $this->collection($items, ['total' => count($items)]);
    }

    /** POS barcode scan -> sellable variant (scoped to the terminal's vendor + shop). */
    public function scan(string $code)
    {
        $term = $this->terminal();
        if ($term === null) {
            return $this->failWith('FORBIDDEN', 'Unknown or inactive terminal.');
        }
        $hits = service('productBarcodeRepository')->resolveAll($code, (int) $term['vendor_id'], (int) $term['shop_id']);
        $hit  = $hits[0] ?? null;
        if ($hit === null || $hit['resolved_variant_id'] === null) {
            return $this->failWith('NOT_FOUND', 'No product for that barcode in this shop.');
        }

        return $this->ok([
            'product_id' => (int) $hit['product_id'], 'variant_id' => (int) $hit['resolved_variant_id'],
            'title' => $hit['title'], 'barcode_type' => $hit['barcode_type'], 'pack_level' => $hit['pack_level'],
            'matches' => count($hits),
        ]);
    }

    /**
     * Resolve + validate the terminal from the request, BOUND to the caller's scope:
     * the cashier's JWT must be scoped to the terminal's vendor (or be platform).
     * This stops a valid token from operating another tenant's terminal.
     * @return array<string,mixed>|null
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
        // Vendor status lifecycle phase 4 — every POS request funnels through this
        // resolver (scan, sync, customers), so this one check covers all of them.
        // Log-only by default; see VendorStatusGate.
        $vendor = ['id' => $term['vendor_id'] ?? null, 'status' => $term['vendor_status'] ?? null];
        if (service('vendorStatusGate')->shouldBlockForVendorStatus($vendor, 'PosController terminal #' . $id)) {
            return null;
        }
        if (! $this->scope()->isPlatform() && ! in_array((int) $term['vendor_id'], $this->callerVendorIds(), true)) {
            return null; // cross-tenant terminal — not the cashier's vendor
        }

        return $term;
    }

    /**
     * Vendor ids the caller may act for: owner OR vendor staff (cashiers are staff,
     * resolved via vendor_staff — they don't necessarily carry a JWT vendor scope),
     * plus any explicit scope. Used to bind a terminal to its tenant.
     * @return list<int>
     */
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
}
