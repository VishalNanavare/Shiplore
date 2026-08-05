<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\CommissionController — commission-plan management. View needs
 * `commission.view`; toggling a plan active/inactive needs `commission.manage`.
 * POSTs CSRF-protected at the route.
 *
 * @see docs/architecture/31-BUSINESS-TYPE-COMMISSION.md
 */
final class CommissionController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('commission.view')) {
            return $denied;
        }

        $status = $this->request->getGet('status');

        return view('admin/commission/index', [
            'title'     => 'Commission Plans · Admin',
            'pageTitle' => 'Commission Plans',
            'active'    => 'commission',
            'userName'  => session()->get('user_name') ?: 'User',
            'plans'     => service('commissionRepository')->list(is_string($status) ? $status : null),
        ]);
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->transition($id, 'active', 'Commission plan activated.');
    }

    public function deactivate(int $id): RedirectResponse
    {
        return $this->transition($id, 'inactive', 'Commission plan deactivated.');
    }

    private function transition(int $id, string $status, string $okMessage): RedirectResponse
    {
        if ($denied = $this->guard('commission.manage')) {
            return $denied;
        }

        $repo = service('commissionRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/commission')->with('error', 'Plan not found.');
        }

        $repo->updateStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/commission')->with('success', $okMessage);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
