<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\RoleController — roles list + permission assignment matrix. role.view /
 * role.update. The super_admin role is protected from edits to prevent lockout.
 */
final class RoleController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('role.view')) {
            return $denied;
        }

        return view('admin/roles/index', [
            'title' => 'Roles · Admin', 'pageTitle' => 'Roles & Permissions', 'active' => 'roles',
            'userName' => session()->get('user_name') ?: 'User',
            'roles' => service('roleRepository')->list(),
        ]);
    }

    public function edit(int $id)
    {
        if ($denied = $this->guard('role.update')) {
            return $denied;
        }
        $repo = service('roleRepository');
        $role = $repo->find($id);
        if ($role === null) {
            return redirect()->to('admin/roles')->with('error', 'Role not found.');
        }

        return view('admin/roles/edit', [
            'title' => 'Edit Role · Admin', 'pageTitle' => 'Permissions · ' . $role['name'], 'active' => 'roles',
            'userName' => session()->get('user_name') ?: 'User',
            'role' => $role, 'modules' => $repo->permissionsByModule(), 'assigned' => $repo->assignedPermissionIds($id),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->guard('role.update')) {
            return $denied;
        }
        $repo = service('roleRepository');
        $role = $repo->find($id);
        if ($role === null) {
            return redirect()->to('admin/roles')->with('error', 'Role not found.');
        }
        if ($role['code'] === 'super_admin') {
            return redirect()->to('admin/roles')->with('error', 'The Super Admin role cannot be modified (lockout protection).');
        }
        $ids = (array) $this->request->getPost('perm_ids');
        $repo->syncPermissions($id, array_map('intval', $ids));

        return redirect()->to('admin/roles/' . $id . '/edit')->with('success', 'Permissions updated (' . count($ids) . ' granted).');
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
