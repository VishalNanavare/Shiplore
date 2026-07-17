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
            'roles' => service('adminUserRepository')->platformRoles(),
        ]);
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->guard('user.create')) {
            return $denied;
        }
        $name  = trim((string) $this->request->getPost('name'));
        $email = trim((string) $this->request->getPost('email'));
        $pass  = (string) $this->request->getPost('password');
        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            return redirect()->back()->withInput()->with('error', 'Name, valid email and an 8+ char password are required.');
        }
        $repo = service('adminUserRepository');
        if ($repo->emailExists($email)) {
            return redirect()->back()->withInput()->with('error', 'Email already in use.');
        }
        $repo->create([
            'name' => $name, 'email' => $email, 'phone' => trim((string) $this->request->getPost('phone')),
            'password' => $pass, 'role_id' => (int) $this->request->getPost('role_id'),
        ], (int) session()->get('user_id'));

        return redirect()->to('admin/users')->with('success', 'Staff user created.');
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
        service('adminUserRepository')->setStatus($id, $status, (int) session()->get('user_id'));

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
