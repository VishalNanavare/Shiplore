<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * VendorStatusGate — the single place every "is this vendor allowed to operate right
 * now" question is answered. Vendor status lifecycle, phase 3 (phases 1-2 shipped:
 * schema fix for 'rejected', admin Activate/Deactivate writing vendors.status only).
 *
 * A shop is operational only if BOTH its own status AND its vendor's status are
 * 'active' — computed here at read time, never cascaded onto shops.status on vendor
 * deactivate. A vendor can already toggle their own shops independently
 * (Vendor\ShopController::open()/close()); a cascading write would silently overwrite
 * that choice and then "restore" it wrong on reactivate.
 *
 * ROLLOUT — log-only by default, mirroring WebAuthFilter::checkPrincipal()'s
 * established mechanics for auth.enforcePrincipalType exactly: an inactive vendor is
 * always logged (grep "vendor-status gate"), but shouldBlockForVendorStatus() only
 * returns true once the operator has set
 *
 *     vendor.enforceStatusGate = true
 *
 * in .env, after reviewing a full traffic day of "would block" lines. Both flipping and
 * rolling back are env-only, no deploy. Phases 4-6 wire this into the storefront, both
 * apps, and every login path — none of them do yet; this class has no callers.
 */
final class VendorStatusGate
{
    /** @param array<string,mixed>|null $vendor */
    public function isVendorActive(?array $vendor): bool
    {
        return ($vendor['status'] ?? '') === 'active';
    }

    /**
     * @param array<string,mixed>|null $shop
     * @param array<string,mixed>|null $vendor
     */
    public function isShopActive(?array $shop, ?array $vendor): bool
    {
        // No `$shop !== null &&` guard: `??` already resolves a null $shop's missing
        // 'status' key to '', which fails the 'active' comparison the same way a
        // genuinely inactive shop would — a mutation run showed the explicit null
        // check was indistinguishable from not having it.
        return ($shop['status'] ?? '') === 'active' && $this->isVendorActive($vendor);
    }

    public function isEnforcing(): bool
    {
        return filter_var(env('vendor.enforceStatusGate', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The staged decision. An active vendor is never blocked, regardless of the flag —
     * there is nothing to log or gate. An inactive (or missing) vendor is always logged;
     * whether the caller should actually act on it depends on the flag.
     *
     * @param array<string,mixed>|null $vendor
     */
    public function shouldBlockForVendorStatus(?array $vendor, string $context): bool
    {
        if ($this->isVendorActive($vendor)) {
            return false;
        }

        $enforcing = $this->isEnforcing();
        log_message(
            $enforcing ? 'warning' : 'notice',
            sprintf(
                'vendor-status gate [%s]: vendor #%s status="%s" — %s',
                $enforcing ? 'BLOCKED' : 'would block',
                (string) ($vendor['id'] ?? '?'),
                (string) ($vendor['status'] ?? '(none)'),
                $context,
            ),
        );

        return $enforcing;
    }

    /**
     * The staged decision for a SHOP, covering both halves in one call: its own status
     * AND its vendor's. For call sites (a direct shop page, the location-scoped nearby
     * list) that need the combined answer rather than composing isShopActive()/
     * shouldBlockForVendorStatus() themselves. Same flag, same log tags as the
     * vendor-only decision — one gate, not two.
     *
     * @param array<string,mixed>|null $shop
     * @param array<string,mixed>|null $vendor
     */
    public function shouldBlockForShopStatus(?array $shop, ?array $vendor, string $context): bool
    {
        if ($this->isShopActive($shop, $vendor)) {
            return false;
        }

        $enforcing = $this->isEnforcing();
        log_message(
            $enforcing ? 'warning' : 'notice',
            sprintf(
                'vendor-status gate [%s]: shop #%s status="%s" vendor #%s status="%s" — %s',
                $enforcing ? 'BLOCKED' : 'would block',
                (string) ($shop['id'] ?? '?'),
                (string) ($shop['status'] ?? '(none)'),
                (string) ($vendor['id'] ?? '?'),
                (string) ($vendor['status'] ?? '(none)'),
                $context,
            ),
        );

        return $enforcing;
    }

    /**
     * The bulk equivalent, for LISTING call sites — nearby() can return up to 500 rows
     * per call, the catalog far more. Logging per row there would flood the log file on
     * a routine storefront page view, so this logs ONCE per call, aggregated with a
     * count, not once per excluded row. Log-only keeps every row (nothing is actually
     * filtered yet); enforcing drops the inactive ones.
     *
     * @param list<array<string,mixed>>                                 $rows
     * @param callable(array<string,mixed>):(array<string,mixed>|null)  $vendorOf maps a
     *        row to its vendor's ['id'=>...,'status'=>...] (or null)
     *
     * @return list<array<string,mixed>>
     */
    public function filterByVendorStatus(array $rows, callable $vendorOf, string $context): array
    {
        if ($rows === []) {
            return $rows;
        }

        $enforcing = $this->isEnforcing();
        $kept      = [];
        $excluded  = 0;

        foreach ($rows as $row) {
            if ($this->isVendorActive($vendorOf($row))) {
                $kept[] = $row;

                continue;
            }
            $excluded++;
            if (! $enforcing) {
                $kept[] = $row;
            }
        }

        if ($excluded > 0) {
            log_message(
                $enforcing ? 'warning' : 'notice',
                sprintf(
                    'vendor-status gate [%s]: %d of %d rows excluded for inactive vendors — %s',
                    $enforcing ? 'BLOCKED' : 'would block',
                    $excluded,
                    count($rows),
                    $context,
                ),
            );
        }

        return $kept;
    }
}
