<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\CustomerController — customer oversight. View needs `customer.view`;
 * block / unblock needs `customer.update`. POSTs CSRF-protected at the route.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md §8
 */
final class CustomerController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('customer.view')) {
            return $denied;
        }

        $status = $this->request->getGet('status');

        return view('admin/customers/index', [
            'title'     => 'Customers · Admin',
            'pageTitle' => 'Customers',
            'active'    => 'customers',
            'userName'  => session()->get('user_name') ?: 'User',
            'customers' => service('customerRepository')->list(is_string($status) ? $status : null),
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->guard('customer.view')) {
            return $denied;
        }

        $repo     = service('customerRepository');
        $customer = $repo->findById($id);
        if ($customer === null) {
            return redirect()->to('admin/customers')->with('error', 'Customer not found.');
        }

        return view('admin/customers/show', [
            'title'     => 'Customer · Admin',
            'pageTitle' => $customer['name'] ?? 'Customer',
            'active'    => 'customers',
            'userName'  => session()->get('user_name') ?: 'User',
            'customer'  => $customer,
            'orders'    => $repo->recentOrders($id),
        ]);
    }

    public function block(int $id): RedirectResponse
    {
        return $this->transition($id, 'blocked', 'Customer blocked.');
    }

    public function unblock(int $id): RedirectResponse
    {
        return $this->transition($id, 'active', 'Customer unblocked.');
    }

    private function transition(int $id, string $status, string $okMessage): RedirectResponse
    {
        if ($denied = $this->guard('customer.update')) {
            return $denied;
        }

        $repo = service('customerRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/customers')->with('error', 'Customer not found.');
        }

        $repo->updateStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/customers/' . $id)->with('success', $okMessage);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
