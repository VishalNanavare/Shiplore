<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * BaseManufacturerController — shared base for the manufacturer panel.
 *
 * Mirrors the shape of BaseVendorController without touching it: the acting
 * manufacturer is resolved from the session user (owner OR active staff), never from
 * request input, and every lookup is additionally constrained to
 * vendors.party_type = 'manufacturer'.
 *
 * Two isolation boundaries, both enforced here:
 *   1. tenant — a manufacturer only ever sees its own data;
 *   2. unit   — staff are limited to their assigned mshops, exactly as a vendor branch
 *      manager is limited to assigned shops. Owning the manufacturer is NOT enough to
 *      act on a unit; requireMshopAccess() is the single guard for every unit id.
 *
 * The route group also carries `webAuth:manufacturer`, but that pin is log-only until
 * auth.enforcePrincipalType is turned on — so this class must not rely on it. The
 * party_type constraint in ManufacturerAccountRepository is the real gate.
 *
 * @see App\Controllers\Vendor\BaseVendorController — the vendor counterpart
 */
abstract class BaseManufacturerController extends BaseController
{
    /** @var array<string,mixed>|null */
    private ?array $manufacturerRow = null;
    private bool $resolved = false;
    /** @var list<int>|null */
    private ?array $mshopIds = null;

    /** @return array<string,mixed>|null */
    protected function manufacturer(): ?array
    {
        if (! $this->resolved) {
            $this->resolved = true;
            $uid            = (int) session()->get('user_id');
            if ($uid > 0) {
                $repo  = service('manufacturerAccountRepository');
                $owned = $repo->findByOwnerUserId($uid);
                if ($owned !== null) {
                    $owned['is_owner']            = true;
                    $owned['vendor_staff_id']     = null;
                    $this->manufacturerRow        = $owned;
                } else {
                    $row = $repo->findStaffManufacturer($uid);
                    if ($row !== null) {
                        $row['is_owner'] = false;
                    }
                    $this->manufacturerRow = $row;
                }
            }
        }

        return $this->manufacturerRow;
    }

    protected function manufacturerId(): ?int
    {
        $m = $this->manufacturer();

        return $m !== null ? (int) $m['id'] : null;
    }

    protected function isOwner(): bool
    {
        return (bool) ($this->manufacturer()['is_owner'] ?? false);
    }

    protected function manufacturerStaffId(): ?int
    {
        return $this->manufacturer()['vendor_staff_id'] ?? null;
    }

    /**
     * Unit ids the current user may act on: the owner gets every unit, staff get only
     * their assigned ones.
     *
     * @return list<int>
     */
    protected function allowedMshopIds(): array
    {
        if ($this->mshopIds === null) {
            $repo           = service('manufacturerAccountRepository');
            $this->mshopIds = $this->isOwner()
                ? $repo->mshopIdsForManufacturer((int) $this->manufacturerId())
                : $repo->mshopIdsForStaff((int) $this->manufacturerStaffId());
        }

        return $this->mshopIds;
    }

    /** RBAC check against the permissions resolved into the ScopeContext. */
    protected function can(string $permission): bool
    {
        return service('policyEngine')->can(service('scopeContext')->all(), $permission);
    }

    /**
     * The single guard for every unit-id action. Ownership of the manufacturer is not
     * enough — a store keeper assigned to unit A must be blocked from unit B. Call it
     * immediately after requireManufacturer() in any method taking a unit id.
     *
     * CONVENTION: name the controller parameter `$mshopId`, never a generic `$id`.
     * ManufacturerPanelIsolationTest::testEveryMshopIdActionChecksUnitAccess() finds
     * unit-scoped actions by that parameter name and asserts each one calls this
     * method — an action that takes a unit id under another name is invisible to that
     * sweep and ships unguarded.
     */
    protected function requireMshopAccess(int $mshopId): ?RedirectResponse
    {
        if (! in_array($mshopId, $this->allowedMshopIds(), true)) {
            return redirect()->to('manufacturer/units')->with('error', 'Unit not found.');
        }

        return null;
    }

    /** The unit selected via ?mshop_id=, only if allowed; else null (= all allowed units). */
    protected function requestedMshopId(): ?int
    {
        $id = (int) $this->request->getGet('mshop_id');

        return $id > 0 && in_array($id, $this->allowedMshopIds(), true) ? $id : null;
    }

    /**
     * The unit a list should be scoped to: the requested unit if allowed; else the whole
     * manufacturer for an owner (null), or a staff member's own unit. -1 means "staff
     * with no unit" and must yield an empty result rather than everything.
     */
    protected function effectiveMshopId(): ?int
    {
        $id = $this->requestedMshopId();
        if ($id !== null) {
            return $id;
        }
        if ($this->isOwner()) {
            return null;
        }

        return $this->allowedMshopIds()[0] ?? -1;
    }

    /** Governance role of the acting user: manufacturer (owner) · manager · staff. */
    protected function actorRole(): string
    {
        if ($this->isOwner()) {
            return 'manufacturer';
        }

        return (($this->manufacturer()['staff_type'] ?? '') === 'branch_manager') ? 'manager' : 'staff';
    }

    /** Guard: logged in AND linked to a manufacturer (owner or active staff). */
    protected function requireManufacturer(): ?RedirectResponse
    {
        if (! session()->get('isLoggedIn')) {
            return redirect()->to('login');
        }
        if ($this->manufacturerId() === null) {
            return redirect()->to('login')->with('error', 'This login is not linked to a manufacturer account.');
        }

        return null;
    }

    /**
     * Permission gate. Returns a redirect when the actor lacks the permission, so
     * callers keep the `if ($denied = $this->guard('x')) { return $denied; }` shape used
     * across the admin panel.
     */
    protected function guard(string $permission): ?RedirectResponse
    {
        if (! $this->can($permission)) {
            return redirect()->to('manufacturer/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }

    /** id => name for the units the user may filter by. @return array<int,string> */
    protected function mshopOptions(): array
    {
        $allowed = $this->allowedMshopIds();
        if ($allowed === []) {
            return [];
        }
        $out = [];
        foreach (service('manufacturerUnitRepository')->list((int) $this->manufacturerId()) as $u) {
            if (in_array((int) $u['id'], $allowed, true)) {
                $out[(int) $u['id']] = $u['name'];
            }
        }

        return $out;
    }

    /** Render a manufacturer view with the common chrome variables. */
    protected function render(string $view, string $active, string $pageTitle, array $data = []): string
    {
        $m     = $this->manufacturer();
        $perms = service('scopeContext')->all()['permissions'] ?? [];
        $opts  = $this->mshopOptions();
        $unit  = $this->effectiveMshopId();

        return view($view, array_merge([
            'title'            => $pageTitle . ' · Manufacturer',
            'pageTitle'        => $pageTitle,
            'active'           => $active,
            'userName'         => session()->get('user_name') ?: 'Manufacturer',
            'manufacturerName' => $m['display_name'] ?? 'Manufacturer',
            'manufacturer'     => $m,
            'navIsOwner'       => $this->isOwner(),
            'navPerms'         => array_flip($perms),
            'isUnitStaff'      => ! $this->isOwner(),
            'activeMshopId'    => $unit !== null && $unit > 0 ? $unit : null,
            'activeMshopName'  => $unit !== null && $unit > 0 ? ($opts[$unit] ?? null) : null,
            'unitSwitch'       => $this->isOwner() ? [] : $opts,
        ], $data));
    }
}
