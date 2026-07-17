<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\PaymentController — read-only oversight of payments. Session-guarded
 * (`webAuth`); RBAC-checked (`payment.view`).
 *
 * @see docs/architecture/24-ADMIN-PANEL.md §5
 */
final class PaymentController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('payment.view')) {
            return $denied;
        }

        $status = (string) $this->request->getGet('status');
        $q      = trim((string) $this->request->getGet('q'));
        $method = (string) $this->request->getGet('method');

        return view('admin/payments/index', [
            'title'     => 'Payments · Admin',
            'pageTitle' => 'Payments',
            'active'    => 'payments',
            'userName'  => session()->get('user_name') ?: 'User',
            'payments'  => service('paymentRepository')->list($status ?: null, $q ?: null, $method ?: null),
            'fStatus'   => $status, 'fQ' => $q, 'fMethod' => $method,
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->guard('payment.view')) {
            return $denied;
        }

        $payment = service('paymentRepository')->findById($id);
        if ($payment === null) {
            return redirect()->to('admin/payments')->with('error', 'Payment not found.');
        }

        return view('admin/payments/show', [
            'title'     => 'Payment · Admin',
            'pageTitle' => 'Payment ' . ($payment['gateway_ref'] ?? $payment['id']),
            'active'    => 'payments',
            'userName'  => session()->get('user_name') ?: 'User',
            'payment'   => $payment,
        ]);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
