<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;

/** Admin\FeatureFlagController — toggle feature flags. featureflag.manage. */
final class FeatureFlagController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $flags = Database::connect()->table('feature_flags')->orderBy('`key`', 'ASC', false)->get()->getResultArray();

        return view('admin/feature-flags/index', [
            'title' => 'Feature Flags · Admin', 'pageTitle' => 'Feature Flags', 'active' => 'feature-flags',
            'userName' => session()->get('user_name') ?: 'User', 'flags' => $flags,
        ]);
    }

    public function toggle(int $id): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $db  = Database::connect();
        $row = $db->table('feature_flags')->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            return redirect()->to('admin/feature-flags')->with('error', 'Flag not found.');
        }
        $db->table('feature_flags')->where('id', $id)->update(['is_enabled' => (int) $row['is_enabled'] === 1 ? 0 : 1, 'updated_by' => (int) session()->get('user_id')]);

        return redirect()->to('admin/feature-flags')->with('success', 'Flag "' . $row['key'] . '" toggled.');
    }

    private function guard(): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'featureflag.manage')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
