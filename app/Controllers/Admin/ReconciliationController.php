<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\ReconciliationController — review gateway reconciliation rows and resolve
 * / flag mismatches. View needs `settlement.view`; acting needs `settlement.run`.
 */
final class ReconciliationController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('settlement.view')) {
            return $denied;
        }
        $repo   = service('reconciliationRepository');
        $status = $this->request->getGet('status');

        return view('admin/reconciliations/index', [
            'title'     => 'Reconciliation · Admin',
            'pageTitle' => 'Payment Reconciliation',
            'active'    => 'settlements',
            'userName'  => session()->get('user_name') ?: 'User',
            'rows'      => $repo->list(is_string($status) ? $status : null),
            'counts'    => $repo->countsByStatus(),
            'status'    => (string) ($status ?? ''),
        ]);
    }

    public function resolve(int $id): RedirectResponse
    {
        return $this->act($id, 'resolved', 'Reconciliation marked resolved.');
    }

    public function dispute(int $id): RedirectResponse
    {
        return $this->act($id, 'chargeback', 'Reconciliation flagged as a chargeback.');
    }

    private function act(int $id, string $status, string $okMessage): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        $ok = service('reconciliationRepository')->updateStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/reconciliations')->with($ok ? 'success' : 'error', $ok ? $okMessage : 'Could not update the record.');
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
