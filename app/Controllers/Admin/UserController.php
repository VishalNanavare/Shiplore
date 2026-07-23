<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/** Admin\UserController — platform staff users + role assignment. user.* perms. */
final class UserController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('user.view')) {
            return $denied;
        }

        return view('admin/users/index', [
            'title' => 'Staff Users · Admin', 'pageTitle' => 'Staff Users', 'active' => 'users',
            'userName' => session()->get('user_name') ?: 'User',
            'users' => service('adminUserRepository')->list(),
        ]);
    }

    public function new()
    {
        if ($denied = $this->guard('user.create')) {
            return $denied;
        }

        return view('admin/users/form', [
            'title' => 'New Staff User · Admin', 'pageTitle' => 'New Staff User', 'active' => 'users',
            'userName' => session()->get('user_name') ?: 'User',
            'roles' => service('adminUserRepository')->platformRoles($this->canGrantSuperAdmin()),
            'user'  => null,
        ]);
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->guard('user.create')) {
            return $denied;
        }
        $name   = trim((string) $this->request->getPost('name'));
        $email  = trim((string) $this->request->getPost('email'));
        $pass   = (string) $this->request->getPost('password');
        $roleId = (int) $this->request->getPost('role_id');

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            return redirect()->back()->withInput()->with('error', 'Name, valid email and an 8+ char password are required.');
        }
        $phone = trim((string) $this->request->getPost('phone'));
        if ($phone !== '' && \App\Models\StoreCustomerRepository::normalizePhone($phone) === null) {
            return redirect()->back()->withInput()
                ->with('error', 'Enter a valid 10-digit Indian mobile number, or leave it blank.');
        }
        $repo = service('adminUserRepository');
        if ($repo->emailExists($email)) {
            return redirect()->back()->withInput()->with('error', 'Email already in use.');
        }
        // uq_users_phone is global across principal_type, so a customer already holding
        // this number would make the INSERT throw and create() would return null with only
        // a log line. Check first so the operator gets a message naming the cause.
        if ($phone !== '' && $repo->phoneExists(\App\Models\StoreCustomerRepository::normalizePhone($phone))) {
            return redirect()->back()->withInput()->with('error', 'That mobile number is already used by another account.');
        }
        // A staff account with no role can sign in but can do nothing — it looks broken
        // rather than restricted. Require an explicit choice.
        if ($roleId <= 0) {
            return redirect()->back()->withInput()->with('error', 'Choose a role for this staff user.');
        }
        // Only someone who already holds super_admin may mint another one.
        if ($repo->isSuperAdminRole($roleId) && ! $this->canGrantSuperAdmin()) {
            return redirect()->back()->withInput()->with('error', 'Only a Super Admin can grant the Super Admin role.');
        }

        $newId = $repo->create([
            'name' => $name, 'email' => $email, 'phone' => $phone,
            'password' => $pass, 'role_id' => $roleId,
        ], (int) session()->get('user_id'));

        // create() returns null on failure. Discarding it reported "Staff user created."
        // for a rolled-back transaction, so the operator went looking for a user that
        // was never written.
        if ($newId === null) {
            return redirect()->back()->withInput()
                ->with('error', 'Could not create the staff user. Please try again — see the log for details.');
        }

        return redirect()->to('admin/users')->with('success', 'Staff user created.');
    }

    public function edit(int $id)
    {
        if ($denied = $this->guard('user.update')) {
            return $denied;
        }
        $repo = service('adminUserRepository');
        $user = $repo->find($id);
        if ($user === null) {
            return redirect()->to('admin/users')->with('error', 'User not found.');
        }

        return view('admin/users/form', [
            'title' => 'Edit Staff User · Admin', 'pageTitle' => 'Edit Staff User', 'active' => 'users',
            'userName' => session()->get('user_name') ?: 'User',
            'roles' => $repo->platformRoles($this->canGrantSuperAdmin()),
            'user'  => $user,
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->guard('user.update')) {
            return $denied;
        }
        $repo = service('adminUserRepository');
        $user = $repo->find($id);
        if ($user === null) {
            return redirect()->to('admin/users')->with('error', 'User not found.');
        }

        $name   = trim((string) $this->request->getPost('name'));
        $email  = trim((string) $this->request->getPost('email'));
        $pass   = (string) $this->request->getPost('password');
        $phone  = trim((string) $this->request->getPost('phone'));
        $roleId = (int) $this->request->getPost('role_id');
        $selfId = (int) session()->get('user_id');

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return redirect()->back()->withInput()->with('error', 'Name and a valid email are required.');
        }
        // Reject a bad number here so the failure names the mobile: updateProfile()
        // returns false for email clash, phone clash AND unparseable phone alike.
        if ($phone !== '' && \App\Models\StoreCustomerRepository::normalizePhone($phone) === null) {
            return redirect()->back()->withInput()
                ->with('error', 'Enter a valid 10-digit Indian mobile number, or leave it blank.');
        }
        if ($pass !== '' && strlen($pass) < 8) {
            return redirect()->back()->withInput()->with('error', 'A new password must be at least 8 characters.');
        }
        if ($roleId > 0 && $repo->isSuperAdminRole($roleId) && ! $this->canGrantSuperAdmin()) {
            return redirect()->back()->withInput()->with('error', 'Only a Super Admin can grant the Super Admin role.');
        }
        // Removing the last active Super Admin would lock everyone out of role
        // management permanently, with no way back through the UI.
        if ($repo->hasSuperAdmin($id)
            && ! $repo->isSuperAdminRole($roleId)
            && $repo->activeSuperAdminCount() <= 1) {
            return redirect()->back()->withInput()
                ->with('error', 'This is the last Super Admin — assign the role to someone else first.');
        }
        if ($id === $selfId && $repo->hasSuperAdmin($id) && ! $repo->isSuperAdminRole($roleId)) {
            return redirect()->back()->withInput()
                ->with('error', 'You cannot remove your own Super Admin role.');
        }

        if (! $repo->updateProfile($id, $name, $email, $phone, $selfId)) {
            return redirect()->back()->withInput()
                ->with('error', 'That email or mobile number is already used by another account.');
        }
        if ($pass !== '') {
            $repo->updatePassword($id, password_hash($pass, PASSWORD_BCRYPT));
        }
        if (! $repo->setRole($id, $roleId, $selfId)) {
            return redirect()->back()->withInput()->with('error', 'Saved the profile, but the role could not be updated — see the log.');
        }

        return redirect()->to('admin/users')->with('success', 'Staff user updated.');
    }

    /** Only an actor who holds super_admin may create or grant super_admin. */
    private function canGrantSuperAdmin(): bool
    {
        return service('adminUserRepository')->hasSuperAdmin((int) session()->get('user_id'));
    }

    public function suspend(int $id): RedirectResponse
    {
        return $this->setStatus($id, 'suspended', 'User suspended.');
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->setStatus($id, 'active', 'User activated.');
    }

    private function setStatus(int $id, string $status, string $msg): RedirectResponse
    {
        if ($denied = $this->guard('user.suspend')) {
            return $denied;
        }
        if (service('adminUserRepository')->find($id) === null) {
            return redirect()->to('admin/users')->with('error', 'User not found.');
        }
        if ($id === (int) session()->get('user_id')) {
            return redirect()->to('admin/users')->with('error', 'You cannot change your own status.');
        }
        // Suspending the last active Super Admin locks the platform out of role and
        // user management with no route back through the UI.
        $repo = service('adminUserRepository');
        if ($status === 'suspended' && $repo->hasSuperAdmin($id) && $repo->activeSuperAdminCount() <= 1) {
            return redirect()->to('admin/users')
                ->with('error', 'This is the last active Super Admin — promote someone else before suspending them.');
        }
        $repo->setStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/users')->with('success', $msg);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
