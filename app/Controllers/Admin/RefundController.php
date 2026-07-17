<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\RefundController — refund oversight + processing. View needs
 * `payment.view`; marking a refund completed/failed needs `payment.refund`.
 * POSTs CSRF-protected at the route.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md §6
 */
final class RefundController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('payment.view')) {
            return $denied;
        }

        $status = $this->request->getGet('status');

        return view('admin/refunds/index', [
            'title'     => 'Refunds · Admin',
            'pageTitle' => 'Refunds',
            'active'    => 'refunds',
            'userName'  => session()->get('user_name') ?: 'User',
            'refunds'   => service('refundRepository')->list(is_string($status) ? $status : null),
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->guard('payment.view')) {
            return $denied;
        }
        $refund = service('refundRepository')->detail($id);
        if ($refund === null) {
            return redirect()->to('admin/refunds')->with('error', 'Refund not found.');
        }

        return view('admin/refunds/show', [
            'title'     => 'Refund #' . $id,
            'pageTitle' => 'Refund #' . $id,
            'active'    => 'refunds',
            'userName'  => session()->get('user_name') ?: 'User',
            'refund'    => $refund,
        ]);
    }

    public function process(int $id): RedirectResponse
    {
        if ($denied = $this->guard('payment.refund')) {
            return $denied;
        }
        if (service('refundRepository')->findById($id) === null) {
            return redirect()->to('admin/refunds')->with('error', 'Refund not found.');
        }

        // Full processing: balanced ledger entries + GST credit note + notify.
        $res = service('refundService')->complete($id, (int) session()->get('user_id'));

        return redirect()->to('admin/refunds')->with(
            $res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Refund processed — credit note ' . ($res['credit_note_no'] ?? '') . ' issued.' : 'Could not process refund: ' . ($res['reason'] ?? 'error'),
        );
    }

    public function fail(int $id): RedirectResponse
    {
        return $this->transition($id, 'failed', 'Refund marked failed.');
    }

    private function transition(int $id, string $status, string $okMessage): RedirectResponse
    {
        if ($denied = $this->guard('payment.refund')) {
            return $denied;
        }

        $repo = service('refundRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/refunds')->with('error', 'Refund not found.');
        }

        $repo->updateStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/refunds')->with('success', $okMessage);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
