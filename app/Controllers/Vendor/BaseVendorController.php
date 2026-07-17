<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * BaseVendorController — shared base for the vendor panel. Enforces tenant
 * isolation: the acting vendor is resolved from the session user (owner OR
 * active staff), never from request input. Staff are additionally shop-scoped:
 * allowedShopIds() limits what they can see, and can() gates actions by the
 * shop-scoped permissions already resolved into the ScopeContext.
 *
 * @see docs/architecture/25-VENDOR-PANEL.md
 */
abstract class BaseVendorController extends BaseController
{
    /** @var array<string,mixed>|null */
    private ?array $vendorRow = null;
    private bool $resolved = false;
    /** @var list<int>|null */
    private ?array $shopIds = null;

    /** @return array<string,mixed>|null */
    protected function vendor(): ?array
    {
        if (! $this->resolved) {
            $this->resolved = true;
            $uid = (int) session()->get('user_id');
            if ($uid > 0) {
                $repo  = service('vendorAccountRepository');
                $owned = $repo->findByOwnerUserId($uid);
                if ($owned !== null) {
                    $owned['is_owner']        = true;
                    $owned['vendor_staff_id'] = null;
                    $this->vendorRow          = $owned;
                } else {
                    $row = $repo->findStaffVendor($uid);
                    if ($row !== null) {
                        $row['is_owner'] = false;
                    }
                    $this->vendorRow = $row;
                }
            }
        }

        return $this->vendorRow;
    }

    protected function vendorId(): ?int
    {
        $v = $this->vendor();

        return $v !== null ? (int) $v['id'] : null;
    }

    /** True when the logged-in user owns the vendor (vs. being assigned staff). */
    protected function isOwner(): bool
    {
        return (bool) ($this->vendor()['is_owner'] ?? false);
    }

    protected function vendorStaffId(): ?int
    {
        return $this->vendor()['vendor_staff_id'] ?? null;
    }

    /**
     * Shop ids the current user may act on: the owner sees every shop of the
     * vendor; staff see only their assigned shops.
     * @return list<int>
     */
    protected function allowedShopIds(): array
    {
        if ($this->shopIds === null) {
            $repo = service('vendorAccountRepository');
            $this->shopIds = $this->isOwner()
                ? $repo->shopIdsForVendor((int) $this->vendorId())
                : $repo->shopIdsForStaff((int) $this->vendorStaffId());
        }

        return $this->shopIds;
    }

    /** RBAC check against the shop-scoped permissions in the ScopeContext. */
    protected function can(string $permission): bool
    {
        return service('policyEngine')->can(service('scopeContext')->all(), $permission);
    }

    /** The shop selected via ?shop_id=, only if the user is allowed it; else null (= all allowed shops). */
    protected function requestedShopId(): ?int
    {
        $sid = (int) $this->request->getGet('shop_id');

        return $sid > 0 && in_array($sid, $this->allowedShopIds(), true) ? $sid : null;
    }

    /**
     * The shop a list should be scoped to: the requested shop if allowed; else
     * the whole vendor for an owner (null), or a staff member's own shop. -1 means
     * "a staff member with no shop" → an empty result rather than all.
     */
    protected function effectiveShopId(): ?int
    {
        $sid = $this->requestedShopId();
        if ($sid !== null) {
            return $sid;
        }
        if ($this->isOwner()) {
            return null;
        }

        return $this->allowedShopIds()[0] ?? -1;
    }

    /** id => name for the shops the user may filter by (owner = all; staff = assigned). @return array<int,string> */
    protected function shopOptions(): array
    {
        $allowed = $this->allowedShopIds();
        $out     = [];
        foreach (service('vendorShopRepository')->list((int) $this->vendorId()) as $s) {
            if (in_array((int) $s['id'], $allowed, true)) {
                $out[(int) $s['id']] = $s['name'];
            }
        }

        return $out;
    }

    /**
     * S0 — full shop rows the current user may see: the owner gets every shop
     * of the vendor; staff get ONLY their assigned shops. The list counterpart
     * to allowedShopIds(); use this anywhere a shop list is rendered so a branch
     * manager never sees a shop they are not assigned to.
     * @return list<array<string,mixed>>
     */
    protected function allowedShops(): array
    {
        $allowed = $this->allowedShopIds();

        return array_values(array_filter(
            service('vendorShopRepository')->list((int) $this->vendorId()),
            static fn (array $s): bool => in_array((int) $s['id'], $allowed, true),
        ));
    }

    /**
     * S0 — the single guard for every shop-id action. Returns a redirect when
     * the shop is not in allowedShopIds() (vendor ownership alone is NOT enough
     * — a branch manager of shop A must be blocked from shop B). Call it right
     * after requireVendor() in any method that takes a shop id.
     */
    protected function requireShopAccess(int $shopId): ?RedirectResponse
    {
        if (! in_array($shopId, $this->allowedShopIds(), true)) {
            return redirect()->to('vendor/shops')->with('error', 'Shop not found.');
        }

        return null;
    }

    /**
     * S0 — the staff member's active shop context. The owner operates
     * vendor-wide (null) unless they pick a ?shop_id filter. Staff are pinned to
     * a shop: a valid ?shop_id switches and persists it; otherwise the previously
     * pinned shop (if still allowed) or the first assigned shop is used. Returns
     * null only when a staff member has no assigned shop.
     */
    protected function activeShopId(): ?int
    {
        if ($this->isOwner()) {
            return $this->requestedShopId();
        }

        $allowed = $this->allowedShopIds();
        if ($allowed === []) {
            return null;
        }

        $requested = $this->requestedShopId();
        if ($requested !== null) {
            session()->set('vendor_active_shop_id', $requested);

            return $requested;
        }

        $pinned = (int) session()->get('vendor_active_shop_id');
        if ($pinned > 0 && in_array($pinned, $allowed, true)) {
            return $pinned;
        }

        return $allowed[0];
    }

    /** Governance role of the acting user: vendor (owner) · manager · staff. */
    protected function actorRole(): string
    {
        if ($this->isOwner()) {
            return 'vendor';
        }

        return (($this->vendor()['staff_type'] ?? '') === 'branch_manager') ? 'manager' : 'staff';
    }

    /**
     * X3 — convert a denied direct write into a governance change request
     * (doc 44 §1.3). The applier executes the SAME repository call the owner
     * path uses once the chain approves.
     */
    protected function submitChangeRequest(string $entityType, string $action, ?int $entityId, ?array $old, array $new, string $fieldGroup = 'default', ?string $reason = null): array
    {
        return service('changeRequestEngine')->submit([
            'entity_type' => $entityType, 'action' => $action, 'entity_id' => $entityId,
            'field_group' => $fieldGroup, 'payload_old' => $old, 'payload_new' => $new,
            'reason'      => $reason, 'vendor_id' => $this->vendorId(),
            // the shop a request belongs to: the shop entity itself, or a shop_id
            // carried in the payload (inventory, POS-product, …) for the inbox.
            'shop_id'     => $entityType === 'shop' ? $entityId : ($new['shop_id'] ?? null),
        ], [
            'user_id'   => (int) session()->get('user_id'),
            'role'      => $this->actorRole(),
            'vendor_id' => $this->vendorId(),
            'ip'        => $this->request->getIPAddress(),
        ]);
    }

    /** Flash tuple for a submitChangeRequest() result. @return array{0:string,1:string} */
    protected function requestFlash(array $r): array
    {
        if ($r['ok'] ?? false) {
            return ['success', 'Your change was submitted for approval.'];
        }

        return ['error', match ($r['error'] ?? '') {
            'duplicate_open' => 'A request for this change is already awaiting approval.',
            'unknown_action' => 'This change cannot be requested.',
            default          => 'Could not submit the request.',
        }];
    }

    /** Guard: must be logged in AND belong to a vendor (owner or active staff). */
    protected function requireVendor(): ?RedirectResponse
    {
        if (! session()->get('isLoggedIn')) {
            return redirect()->to('login');
        }
        if ($this->vendorId() === null) {
            return redirect()->to('login')->with('error', 'This login is not linked to a vendor account.');
        }

        return null;
    }

    /** Render a vendor view with the common chrome variables. */
    /** UUID of the vendor's logo media asset (for the chrome), or null. */
    protected function vendorLogoUuid(): ?string
    {
        $v = $this->vendor();
        if (empty($v['logo_media_id'])) {
            return null;
        }
        $row = \Config\Database::connect()->table('media_assets')->select('uuid')
            ->where('id', (int) $v['logo_media_id'])->where('status', 'active')->where('deleted_at', null)
            ->get()->getRowArray();

        return $row['uuid'] ?? null;
    }

    protected function render(string $view, string $active, string $pageTitle, array $data = []): string
    {
        $v     = $this->vendor();
        $perms = service('scopeContext')->all()['permissions'] ?? [];

        // S1 — shop context for the chrome: staff operate an active shop (a chip,
        // or a switcher when assigned to more than one). The owner is vendor-wide.
        $opts       = $this->shopOptions();
        $activeShop = $this->activeShopId();

        return view($view, array_merge([
            'title'      => $pageTitle . ' · Vendor',
            'pageTitle'  => $pageTitle,
            'active'     => $active,
            'userName'   => session()->get('user_name') ?: 'Vendor',
            'vendorName' => $v['display_name'] ?? 'Vendor',
            'vendorLogoUuid' => $this->vendorLogoUuid(),
            'vendor'     => $v,
            'navIsOwner' => $this->isOwner(),
            'navPerms'   => array_flip($perms),
            // shop-context chrome (consumed by partials/_topbar.php)
            'isShopStaff'    => ! $this->isOwner(),
            'activeShopId'   => $activeShop,
            'activeShopName' => $activeShop !== null ? ($opts[$activeShop] ?? null) : null,
            'shopSwitch'     => $this->isOwner() ? [] : $opts,
        ], $data));
    }
}
