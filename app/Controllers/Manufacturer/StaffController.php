<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use App\Models\ManufacturerStaffRepository;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Manufacturer\StaffController — the manufacturer's own staff: create, edit,
 * suspend, and assign to manufacturing units.
 *
 * This closes the gap that made every other unit-scoped guard in this panel dead
 * code: `mfg_staff_assignments` shipped in 70_manufacturer.sql and nothing ever
 * wrote to it, so unit staff could not exist, so BaseManufacturerController's whole
 * unit-isolation model had nothing to isolate.
 *
 * Owner-only. The vendor panel additionally lets a branch manager REQUEST staff
 * changes through the governance engine (X3/AR-09); that path is deliberately not
 * mirrored here yet, because the manufacturer panel has no approvals inbox to
 * receive such a request — building the request half without the approve half would
 * strand it. Kept as a single ownership gate until that inbox exists.
 *
 * @see \App\Controllers\Vendor\StaffController — the vendor counterpart
 */
final class StaffController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.staff.view')) {
            return $denied;
        }

        return $this->render('manufacturer/staff/index', 'staff', 'Staff', [
            'staff'     => service('manufacturerStaffRepository')->staffWithUnits((int) $this->manufacturerId()),
            'canManage' => $this->isOwner(),
        ]);
    }

    public function new()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guardManage()) {
            return $denied;
        }

        return $this->render('manufacturer/staff/form', 'staff', 'Add Staff', [
            'staff'    => null,
            'units'    => $this->units(),
            'assigned' => [],
            'types'    => ManufacturerStaffRepository::types(),
        ]);
    }

    public function create(): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guardManage()) {
            return $denied;
        }
        [$data, $err] = $this->validated();
        if ($err !== null) {
            return redirect()->to('manufacturer/staff/new')->withInput()->with('error', $err);
        }

        $id = service('manufacturerStaffRepository')
            ->createStaff((int) $this->manufacturerId(), $data, (int) session()->get('user_id'));

        return redirect()->to('manufacturer/staff')
            ->with($id ? 'success' : 'error', $id ? 'Staff member created.' : 'Could not create the staff member.');
    }

    public function edit(int $id)
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guardManage()) {
            return $denied;
        }
        $repo  = service('manufacturerStaffRepository');
        $staff = $repo->findStaff($id, (int) $this->manufacturerId());
        if ($staff === null) {
            return redirect()->to('manufacturer/staff')->with('error', 'Staff member not found.');
        }

        return $this->render('manufacturer/staff/form', 'staff', 'Edit Staff', [
            'staff'    => $staff,
            'units'    => $this->units(),
            'assigned' => $repo->staffUnits($id),
            'types'    => ManufacturerStaffRepository::types(),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guardManage()) {
            return $denied;
        }
        [$data, $err] = $this->validated($id);
        if ($err !== null) {
            return redirect()->to('manufacturer/staff/' . $id . '/edit')->withInput()->with('error', $err);
        }

        $ok = service('manufacturerStaffRepository')
            ->updateStaff($id, (int) $this->manufacturerId(), $data, (int) session()->get('user_id'));

        return redirect()->to('manufacturer/staff')
            ->with($ok ? 'success' : 'error', $ok ? 'Staff member updated.' : 'Could not update the staff member.');
    }

    public function suspend(int $id): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guardManage()) {
            return $denied;
        }
        $to = $this->request->getPost('status') === 'active' ? 'active' : 'suspended';
        $ok = service('manufacturerStaffRepository')
            ->setStatus($id, (int) $this->manufacturerId(), $to, (int) session()->get('user_id'));

        if ($ok && $to === 'suspended') {
            $this->revokeTokens($id);
        }

        return redirect()->to('manufacturer/staff')
            ->with($ok ? 'success' : 'error', $ok ? 'Status updated.' : 'Could not update status.');
    }

    // ---- internals ---------------------------------------------------------

    /**
     * Suspension revokes API tokens immediately. The browser session is NOT killed
     * here: CI4 sessions are files under writable/session, not DB rows. WebAuthFilter
     * re-reads users.status on every request and destroys the session as soon as the
     * account stops being active, which is what setStatus() just wrote.
     */
    private function revokeTokens(int $staffId): void
    {
        try {
            $staff = service('manufacturerStaffRepository')->findStaff($staffId, (int) $this->manufacturerId());
            if ($staff !== null && (int) ($staff['user_id'] ?? 0) > 0) {
                \Config\Database::connect()->table('auth_tokens')
                    ->where('user_id', (int) $staff['user_id'])->update(['status' => 'revoked']);
            }
        } catch (\Throwable) {
            // Token revocation is best-effort; users.status is the authoritative gate.
        }
    }

    /**
     * Permission + ownership for every write on this screen.
     *
     * Deliberately does NOT call requireManufacturer(): each public method calls that
     * itself, first and by name. Hiding it behind a helper would satisfy the code but
     * defeat ManufacturerPanelIsolationTest, which reads method bodies as text and
     * cannot follow the indirection — and a guard the sweep cannot see is a guard that
     * silently stops being enforced the day someone forgets it. UnitController and
     * ProductController spell it out the same way.
     */
    private function guardManage(): ?RedirectResponse
    {
        if ($denied = $this->guard('mfg.staff.manage')) {
            return $denied;
        }

        return $this->requireOwner('manufacturer/staff', 'Only the owner can manage staff.');
    }

    /** @return list<array<string,mixed>> the manufacturer's own units, for the assignment picker */
    private function units(): array
    {
        return service('manufacturerUnitRepository')->list((int) $this->manufacturerId());
    }

    /**
     * Validate and normalise the staff form.
     *
     * Unit ids are intersected with allowedMshopIds() rather than trusted from the
     * post, so a submitted id belonging to another manufacturer is dropped instead of
     * assigned. @return array{0:array<string,mixed>,1:?string}
     */
    private function validated(?int $exceptStaffId = null): array
    {
        $name  = trim((string) $this->request->getPost('name'));
        $email = trim((string) $this->request->getPost('email'));
        $type  = (string) $this->request->getPost('staff_type');

        $unitIds = array_values(array_intersect(
            array_map('intval', (array) $this->request->getPost('mshop_ids')),
            $this->allowedMshopIds(),
        ));

        if ($name === '') {
            return [[], 'Name is required.'];
        }
        if (! in_array($type, ManufacturerStaffRepository::types(), true)) {
            return [[], 'Pick a valid role.'];
        }
        if ($unitIds === []) {
            return [[], 'Assign the staff member to at least one of your units.'];
        }

        $repo      = service('manufacturerStaffRepository');
        $exceptUid = null;
        if ($exceptStaffId !== null) {
            $staff     = $repo->findStaff($exceptStaffId, (int) $this->manufacturerId());
            $exceptUid = $staff !== null ? (int) $staff['user_id'] : null;
        }
        if ($email !== '' && $repo->emailExists($email, $exceptUid)) {
            return [[], 'That email is already registered.'];
        }
        if ($exceptStaffId === null && $email === '') {
            return [[], 'A login email is required for new staff.'];
        }

        $primary = (int) $this->request->getPost('primary_unit');

        return [[
            'name'          => $name,
            'email'         => $email,
            'phone'         => trim((string) $this->request->getPost('phone')),
            'password'      => (string) $this->request->getPost('password'),
            'staff_type'    => $type,
            'employee_code' => trim((string) $this->request->getPost('employee_code')),
            'designation'   => trim((string) $this->request->getPost('designation')),
            'mshop_ids'     => $unitIds,
            'primary_unit'  => in_array($primary, $unitIds, true) ? $primary : ($unitIds[0] ?? 0),
        ], null];
    }
}
