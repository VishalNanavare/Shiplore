<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Audit\AuditWriter;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;

/**
 * Admin\PortalController — "Go to Portal" impersonation. Lets a platform admin
 * enter a vendor / shop / rider portal acting AS that account (no password), and
 * return to admin afterwards. Every transition is audited (impersonated_by).
 *
 * Session model:
 *  - Vendor/Shop: SWAP the staff session (user_id -> owner user, principal_type
 *    -> 'vendor') so WebAuthFilter resolves the vendor's scope. Originals stashed.
 *  - Rider: ADD the rider session keys (rider_id/rider_name) — a separate
 *    namespace guarded by RiderAuthFilter; the admin identity stays intact.
 *
 * Guarded by webAuth at the route; each enter() additionally requires platform
 * scope + the entity's view permission; all POSTs are CSRF-protected.
 */
final class PortalController extends BaseController
{
    /** Enter a vendor's portal as the vendor owner. */
    public function enterVendor(int $vendorId): RedirectResponse
    {
        if ($denied = $this->adminGuard('vendor.view')) {
            return $denied;
        }
        if ($busy = $this->blockIfImpersonating()) {
            return $busy;
        }

        $db     = Database::connect();
        $vendor = $db->table('vendors')->select('id, display_name, owner_user_id, status')
            ->where('id', $vendorId)->where('deleted_at', null)->get()->getRowArray();
        if ($vendor === null) {
            return redirect()->to('admin/vendors')->with('error', 'Vendor not found.');
        }
        if ($vendor['status'] === 'terminated') {
            return redirect()->to('admin/vendors/' . $vendorId)->with('error', 'This vendor is terminated and cannot be entered.');
        }
        $owner = $this->activeUser((int) $vendor['owner_user_id'], 'vendor');
        if ($owner === null) {
            return redirect()->to('admin/vendors/' . $vendorId)->with('error', 'This vendor has no active owner login to enter as.');
        }

        $this->startStaffImpersonation('vendor', (int) $owner['id'], (string) $owner['name'], 'Vendor · ' . (string) $vendor['display_name'], $vendorId);

        return redirect()->to($this->portalUrl('vendor', 'vendor/dashboard'));
    }

    /** Enter a shop's portal as its vendor, focused on that shop. */
    public function enterShop(int $shopId): RedirectResponse
    {
        if ($denied = $this->adminGuard('shop.view')) {
            return $denied;
        }
        if ($busy = $this->blockIfImpersonating()) {
            return $busy;
        }

        $db   = Database::connect();
        $shop = $db->table('shops s')->select('s.id, s.name, s.status, s.vendor_id, v.display_name AS vendor_name, v.owner_user_id, v.status AS vendor_status')
            ->join('vendors v', 'v.id = s.vendor_id', 'left')
            ->where('s.id', $shopId)->where('s.deleted_at', null)->get()->getRowArray();
        if ($shop === null) {
            return redirect()->to('admin/shops')->with('error', 'Shop not found.');
        }
        if (($shop['vendor_status'] ?? '') === 'terminated') {
            return redirect()->to('admin/shops/' . $shopId)->with('error', 'The owning vendor is terminated; this shop cannot be entered.');
        }
        $owner = $this->activeUser((int) $shop['owner_user_id'], 'vendor');
        if ($owner === null) {
            return redirect()->to('admin/shops/' . $shopId)->with('error', 'This shop has no active vendor login to enter as.');
        }

        $this->startStaffImpersonation('shop', (int) $owner['id'], (string) $owner['name'], 'Shop · ' . (string) $shop['name'], $shopId);

        return redirect()->to($this->portalUrl('shop', 'vendor/dashboard?shop_id=' . $shopId));
    }

    /** Enter a rider's portal (separate rider_id session namespace). */
    public function enterRider(int $riderId): RedirectResponse
    {
        if ($denied = $this->adminGuard('delivery.view')) {
            return $denied;
        }
        if ($busy = $this->blockIfImpersonating()) {
            return $busy;
        }

        $rider = service('riderRepository')->adminFind($riderId);
        if ($rider === null) {
            return redirect()->to('admin/riders')->with('error', 'Rider not found.');
        }
        if (($rider['status'] ?? '') !== 'active') {
            return redirect()->to('admin/riders/' . $riderId)->with('error', 'Only active riders can be entered.');
        }
        $user = $this->activeUser((int) $rider['user_id'], 'rider');
        if ($user === null) {
            return redirect()->to('admin/riders/' . $riderId)->with('error', 'This rider has no active login to enter as.');
        }

        $session   = session();
        $adminId   = (int) $session->get('user_id');
        $adminName = (string) ($session->get('user_name') ?: 'Admin');
        $this->audit('start', 'rider', $riderId, (int) $user['id']);
        $session->regenerate();
        $session->set([
            'is_impersonating'        => true,
            'impersonator_id'         => $adminId,
            'impersonator_name'       => $adminName,
            'impersonation_kind'      => 'rider',
            'impersonation_label'     => 'Rider · ' . (string) ($rider['name'] ?? 'Rider'),
            'impersonation_entity_id' => $riderId,
            'rider_id'                => (int) $user['id'],
            'rider_name'              => (string) ($rider['name'] ?? 'Rider'),
        ]);

        return redirect()->to($this->portalUrl('rider', 'rider/dashboard'));
    }

    /** Return to the admin panel, restoring the original admin session. */
    public function leave(): RedirectResponse
    {
        $session = session();
        if (! $session->get('is_impersonating')) {
            return redirect()->to($this->portalUrl('admin', 'admin/dashboard'));
        }

        $kind           = (string) $session->get('impersonation_kind');
        $impersonatorId = (int) $session->get('impersonator_id');
        $entityId       = (int) $session->get('impersonation_entity_id');
        $this->audit('stop', $kind, $entityId, (int) ($session->get('rider_id') ?: $session->get('user_id')), $impersonatorId);

        $session->regenerate();
        if ($kind === 'rider') {
            $session->remove(['rider_id', 'rider_name']);
        } else {
            // Vendor/shop swapped the staff identity — restore the admin's.
            // Fail safe: a missing/corrupt impersonator stash must never leave a
            // half-restored elevated session — drop it and force a fresh login.
            if ($impersonatorId <= 0) {
                $session->destroy();

                return redirect()->to($this->portalUrl('admin', 'login'))->with('error', 'Session error — please sign in again.');
            }
            $session->set([
                'isLoggedIn'     => true,
                'user_id'        => $impersonatorId,
                'user_name'      => (string) $session->get('impersonator_name'),
                'principal_type' => 'platform',
            ]);
        }
        $session->remove([
            'is_impersonating', 'impersonator_id', 'impersonator_name',
            'impersonation_kind', 'impersonation_label', 'impersonation_entity_id',
        ]);

        return redirect()->to($this->portalUrl('admin', 'admin/dashboard'))->with('success', 'Returned to the admin panel.');
    }

    // ---- helpers -----------------------------------------------------------

    /** Stash the admin, then SWAP the staff session to act as a vendor user. */
    private function startStaffImpersonation(string $kind, int $userId, string $userName, string $label, int $entityId): void
    {
        $session = session();
        $this->audit('start', $kind, $entityId, $userId);
        $adminId   = (int) $session->get('user_id');
        $adminName = (string) ($session->get('user_name') ?: 'Admin');

        $session->regenerate();
        $session->set([
            'isLoggedIn'              => true,
            'is_impersonating'        => true,
            'impersonator_id'         => $adminId,
            'impersonator_name'       => $adminName,
            'impersonation_kind'      => $kind,
            'impersonation_label'     => $label,
            'impersonation_entity_id' => $entityId,
            // Swap the acting identity:
            'user_id'                 => $userId,
            'user_name'               => $userName,
            'principal_type'          => 'vendor',
        ]);
    }

    /**
     * Resolve an active user of the expected principal type.
     *
     * @return array<string,mixed>|null
     */
    private function activeUser(int $userId, string $principalType): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $u = Database::connect()->table('users')->select('id, name, status, principal_type')
            ->where('id', $userId)->where('deleted_at', null)->get()->getRowArray();
        if ($u === null || $u['status'] !== 'active' || $u['principal_type'] !== $principalType) {
            return null;
        }

        return $u;
    }

    private function blockIfImpersonating(): ?RedirectResponse
    {
        if (session()->get('is_impersonating')) {
            return redirect()->back()->with('error', 'You are already in a portal. Return to admin first.');
        }

        return null;
    }

    /** Only platform admins with the entity permission may impersonate. */
    private function adminGuard(string $permission): ?RedirectResponse
    {
        $ctx = service('scopeContext');
        if (! $ctx->isPlatform() || ! service('policyEngine')->can($ctx->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }

    /** Build an absolute URL on a sibling subdomain (domain-agnostic, dev + prod). */
    private function portalUrl(string $sub, string $path): string
    {
        $host  = explode(':', (string) $this->request->getServer('HTTP_HOST'))[0];
        $parts = explode('.', $host);
        if (count($parts) > 2) {
            array_shift($parts); // drop the current subdomain label
        }
        $base   = implode('.', $parts);
        $target = $sub . '.' . $base;
        // Defense-in-depth against Host-header injection: only ever redirect to a
        // host CI4 already trusts ($allowedHostnames). Otherwise stay on the
        // current (validated) host via a relative URL.
        $allowed = config('App')->allowedHostnames ?? [];
        if (strpos($base, '.') === false || (! in_array($target, $allowed, true) && $target !== $host)) {
            return base_url($path);
        }
        $scheme = $this->request->isSecure() ? 'https' : 'http';

        return $scheme . '://' . $target . '/' . ltrim($path, '/');
    }

    /** Append-only audit; never blocks the flow. */
    private function audit(string $phase, string $kind, int $entityId, int $actingUserId, ?int $impersonatorOverride = null): void
    {
        try {
            $session     = session();
            $impersonator = $impersonatorOverride ?? (int) ($session->get('impersonator_id') ?: $session->get('user_id'));
            (new AuditWriter())->log([
                'action'          => 'portal.' . $phase . '_impersonation',
                'entity_type'     => $kind,
                'entity_id'       => $entityId,
                'actor_user_id'   => $actingUserId,
                'principal_type'  => 'platform',
                'impersonated_by' => $impersonator,
                'ip'              => $this->request->getIPAddress(),
                'user_agent'      => (string) $this->request->getUserAgent(),
                'reason'          => $phase === 'start' ? 'Admin entered ' . $kind . ' portal' : 'Admin returned from ' . $kind . ' portal',
            ]);
        } catch (\Throwable) {
            // auditing must never block impersonation
        }
    }
}
