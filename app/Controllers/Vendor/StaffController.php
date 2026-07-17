<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Vendor\StaffController — the vendor"s own staff (create/edit/suspend, assign to
 * shops, grant a shop-scoped role) and delivery riders. Staff management is the
 * owner's job (or a manager with vendor_staff.manage); shop assignments are
 * constrained to the vendor"s own shops.
 */
final class StaffController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $repo = service('vendorStaffRepository');
        $vid  = $this->vendorId();

        return $this->render('vendor/staff/index', 'staff', 'Staff & Riders', [
            'staff'    => $repo->staffWithShops($vid),
            'riders'   => $repo->riders($vid),
            // Owners act directly; managers see the same buttons but their actions
            // are submitted to the vendor for approval.
            'canManage'  => $this->canManageStaff() || $this->canRequestStaff(),
            'asRequest'  => ! $this->canManageStaff() && $this->canRequestStaff(),
        ]);
    }

    public function new()
    {
        if ($denied = $this->guardStaffAccess()) {
            return $denied;
        }

        // Preselect a shop when adding staff from a shop console.
        $preselect = (int) $this->request->getGet('shop');
        $assigned  = $preselect > 0 && in_array($preselect, $this->allowedShopIds(), true) ? [$preselect] : [];

        return $this->render('vendor/staff/form', 'staff', 'Add Staff', [
            'staff' => null, 'shops' => $this->shops(), 'assigned' => $assigned,
            'asRequest' => ! $this->canManageStaff(),
        ]);
    }

    public function create(): RedirectResponse
    {
        if ($denied = $this->guardStaffAccess()) {
            return $denied;
        }
        [$data, $err] = $this->validated();
        if ($err !== null) {
            return redirect()->to('vendor/staff/new')->withInput()->with('error', $err);
        }

        // X3 (AR-09): a manager without vendor_staff.manage REQUESTS the hire;
        // the owner (or permitted manager) creates directly.
        if (! $this->canManageStaff()) {
            [$type, $msg] = $this->requestFlash($this->submitChangeRequest('staff', 'create', null, null, ['data' => $data], 'staff-' . md5(strtolower((string) ($data['email'] ?? $data['phone'] ?? '')))));

            return redirect()->to('vendor/staff')->with($type, $msg);
        }

        $id = service('vendorStaffRepository')->createStaff($this->vendorId(), $data, (int) session()->get('user_id'));

        return redirect()->to('vendor/staff')->with($id ? 'success' : 'error', $id ? 'Staff member created.' : 'Could not create the staff member.');
    }

    public function edit(int $id)
    {
        if ($denied = $this->guardStaffAccess()) {
            return $denied;
        }
        $repo  = service('vendorStaffRepository');
        $staff = $repo->findStaff($id, (int) $this->vendorId());
        if ($staff === null) {
            return redirect()->to('vendor/staff')->with('error', 'Staff member not found.');
        }

        return $this->render('vendor/staff/form', 'staff', 'Edit Staff', [
            'staff' => $staff, 'shops' => $this->shops(), 'assigned' => $repo->staffShops($id),
            'asRequest' => ! $this->canManageStaff(),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->guardStaffAccess()) {
            return $denied;
        }
        [$data, $err] = $this->validated($id);
        if ($err !== null) {
            return redirect()->to('vendor/staff/' . $id . '/edit')->withInput()->with('error', $err);
        }

        // X3 (AR-10): role change / branch transfer by a non-permitted manager
        // becomes a request.
        if (! $this->canManageStaff()) {
            [$type, $msg] = $this->requestFlash($this->submitChangeRequest('staff', 'role_change', $id, null, ['data' => $data]));

            return redirect()->to('vendor/staff')->with($type, $msg);
        }

        $ok = service('vendorStaffRepository')->updateStaff($id, (int) $this->vendorId(), $data, (int) session()->get('user_id'));

        return redirect()->to('vendor/staff')->with($ok ? 'success' : 'error', $ok ? 'Staff member updated.' : 'Could not update the staff member.');
    }

    public function suspend(int $id): RedirectResponse
    {
        if ($denied = $this->guardStaffAccess()) {
            return $denied;
        }
        $to = $this->request->getPost('status') === 'active' ? 'active' : 'suspended';

        // X3 (AR-10): a non-permitted manager requests termination/reactivation.
        if (! $this->canManageStaff()) {
            [$type, $msg] = $this->requestFlash($this->submitChangeRequest('staff', 'terminate', $id, null, ['status' => $to]));

            return redirect()->to('vendor/staff')->with($type, $msg);
        }

        $ok = service('vendorStaffRepository')->setStatus($id, (int) $this->vendorId(), $to, (int) session()->get('user_id'));
        if ($ok && $to === 'suspended') {
            $this->revokeStaffSessions($id);
        }

        return redirect()->to('vendor/staff')->with($ok ? 'success' : 'error', $ok ? 'Status updated.' : 'Could not update status.');
    }

    /** GAP-023: suspension/termination revokes live sessions + JWTs immediately. */
    private function revokeStaffSessions(int $staffId): void
    {
        try {
            $staff = service('vendorStaffRepository')->findStaff($staffId, (int) $this->vendorId());
            if ($staff !== null && (int) ($staff['user_id'] ?? 0) > 0) {
                $db = \Config\Database::connect();
                $db->table('auth_tokens')->where('user_id', (int) $staff['user_id'])->update(['status' => 'revoked']);
                $db->table('sessions')->where('user_id', (int) $staff['user_id'])->delete();
            }
        } catch (\Throwable) {
        }
    }

    public function addRider(): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $name  = trim((string) $this->request->getPost('name'));
        $phone = trim((string) $this->request->getPost('phone'));
        if ($name === '' || ! preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
            return redirect()->to('vendor/staff')->with('error', 'Rider name and a valid phone (10-15 digits) are required.');
        }
        $repo = service('vendorStaffRepository');
        if ($repo->phoneExists($phone)) {
            return redirect()->to('vendor/staff')->with('error', 'That phone number is already registered.');
        }
        $rid = $repo->addRider($this->vendorId(), [
            'name' => $name, 'phone' => $phone, 'email' => trim((string) $this->request->getPost('email')),
            'password' => (string) $this->request->getPost('password'),
            'vehicle_type' => (string) $this->request->getPost('vehicle_type'),
            'vehicle_no' => trim((string) $this->request->getPost('vehicle_no')),
            'max_active_orders' => (int) $this->request->getPost('max_active_orders'),
        ], (int) session()->get('user_id'));

        return redirect()->to('vendor/staff')->with($rid ? 'success' : 'error', $rid ? 'Delivery rider added.' : 'Could not add rider.');
    }

    // ---- internals ----------------------------------------------------------

    private function canManageStaff(): bool
    {
        return $this->isOwner() || $this->can('vendor_staff.manage');
    }

    /** A branch manager may initiate staff changes (routed to vendor approval). */
    private function canRequestStaff(): bool
    {
        return $this->can('staff.request');
    }

    /** Access to the staff section: direct managers OR requesting managers. */
    private function guardStaffAccess(): ?RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->canManageStaff() && ! $this->canRequestStaff()) {
            return redirect()->to('vendor/staff')->with('error', "You don't have permission to manage staff.");
        }

        return null;
    }

    /** @return list<array<string,mixed>> the vendor's own shops, for the assignment multi-select */
    private function shops(): array
    {
        return service('vendorShopRepository')->list((int) $this->vendorId());
    }

    /**
     * Validate + normalise the staff form. Shop ids are constrained to the
     * vendor's own shops. @return array{0:array<string,mixed>,1:?string}
     */
    private function validated(?int $exceptStaffId = null): array
    {
        $name  = trim((string) $this->request->getPost('name'));
        $email = trim((string) $this->request->getPost('email'));
        $type  = (string) $this->request->getPost('staff_type');
        $shopIds = array_values(array_intersect(
            array_map('intval', (array) $this->request->getPost('shop_ids')),
            $this->allowedShopIds(),
        ));
        if ($name === '') {
            return [[], 'Name is required.'];
        }
        if (! in_array($type, ['branch_manager', 'cashier', 'packer', 'helper'], true)) {
            return [[], 'Pick a valid role.'];
        }
        if ($shopIds === []) {
            return [[], 'Assign the staff member to at least one of your shops.'];
        }
        $repo      = service('vendorStaffRepository');
        $exceptUid = null;
        if ($exceptStaffId !== null) {
            $staff     = $repo->findStaff($exceptStaffId, (int) $this->vendorId());
            $exceptUid = $staff !== null ? (int) $staff['user_id'] : null;
        }
        if ($email !== '' && $repo->emailExists($email, $exceptUid)) {
            return [[], 'That email is already registered.'];
        }
        if ($exceptStaffId === null && $email === '') {
            return [[], 'A login email is required for new staff.'];
        }
        $primary = (int) $this->request->getPost('primary_shop');

        return [[
            'name' => $name, 'email' => $email, 'phone' => trim((string) $this->request->getPost('phone')),
            'password' => (string) $this->request->getPost('password'),
            'staff_type' => $type, 'employee_code' => trim((string) $this->request->getPost('employee_code')),
            'designation' => trim((string) $this->request->getPost('designation')),
            'shop_ids' => $shopIds, 'primary_shop' => in_array($primary, $shopIds, true) ? $primary : ($shopIds[0] ?? 0),
        ], null];
    }
}
