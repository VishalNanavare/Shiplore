<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\PaymentGatewayController — robust, multi-gateway management. Add / edit /
 * enable / disable / test / delete payment gateways (PayU, Razorpay, Stripe, …).
 * Guarded by `integration.manage`; POSTs CSRF-protected. The payment flow reads
 * the active gateways this panel produces.
 *
 * @see docs/architecture/12-PAYMENT-ARCHITECTURE.md
 */
final class PaymentGatewayController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        return view('admin/payment-gateways/index', [
            'title'     => 'Payment Gateways · Admin',
            'pageTitle' => 'Payment Gateways',
            'active'    => 'payment-gateways',
            'userName'  => session()->get('user_name') ?: 'User',
            'gateways'  => service('paymentGatewayRepository')->list(),
            'providers' => service('paymentGatewayManager')->availableProviders(),
        ]);
    }

    public function new()
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        return $this->form(null);
    }

    public function edit(int $id)
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $gw = service('paymentGatewayRepository')->findById($id);
        if ($gw === null) {
            return redirect()->to('admin/payment-gateways')->with('error', 'Gateway not found.');
        }

        return $this->form($gw);
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        [$provider, $channel, $status, $priority, $config] = $this->input();
        if (service('paymentGatewayManager')->driver($provider) === null) {
            return redirect()->back()->withInput()->with('error', 'Unsupported provider.');
        }

        service('paymentGatewayRepository')->create($provider, $channel, $status, $priority, $config, (int) session()->get('user_id'));

        return redirect()->to('admin/payment-gateways')->with('success', 'Gateway added.');
    }

    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $repo = service('paymentGatewayRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/payment-gateways')->with('error', 'Gateway not found.');
        }

        [, $channel, $status, $priority, $config] = $this->input();
        $repo->update($id, $channel, $status, $priority, $config, (int) session()->get('user_id'));

        return redirect()->to('admin/payment-gateways')->with('success', 'Gateway updated.');
    }

    public function enable(int $id): RedirectResponse
    {
        return $this->setStatus($id, 'active', 'Gateway enabled.');
    }

    public function disable(int $id): RedirectResponse
    {
        return $this->setStatus($id, 'inactive', 'Gateway disabled.');
    }

    public function test(int $id): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $gw = service('paymentGatewayRepository')->findById($id);
        if ($gw === null) {
            return redirect()->to('admin/payment-gateways')->with('error', 'Gateway not found.');
        }

        $result = service('paymentGatewayManager')->test($gw['provider'], $gw['config_arr'] ?? []);

        return redirect()->to('admin/payment-gateways')->with($result['ok'] ? 'success' : 'error', 'Test · ' . $result['message']);
    }

    public function delete(int $id): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        service('paymentGatewayRepository')->delete($id, (int) session()->get('user_id'));

        return redirect()->to('admin/payment-gateways')->with('success', 'Gateway removed.');
    }

    // ---- helpers ----

    private function form(?array $gw): string
    {
        return view('admin/payment-gateways/form', [
            'title'     => ($gw ? 'Edit' : 'New') . ' Gateway · Admin',
            'pageTitle' => ($gw ? 'Edit' : 'New') . ' Payment Gateway',
            'active'    => 'payment-gateways',
            'userName'  => session()->get('user_name') ?: 'User',
            'gateway'   => $gw,
            'manager'   => service('paymentGatewayManager'),
        ]);
    }

    /** @return array{0:string,1:string,2:string,3:int,4:array<string,mixed>} */
    private function input(): array
    {
        $provider = (string) $this->request->getPost('provider');
        $channel  = in_array($this->request->getPost('channel'), ['online', 'pos', 'payout'], true) ? (string) $this->request->getPost('channel') : 'online';
        $status   = in_array($this->request->getPost('status'), ['active', 'inactive', 'sandbox'], true) ? (string) $this->request->getPost('status') : 'sandbox';
        $priority = (int) $this->request->getPost('priority');

        $config = [];
        $driver = service('paymentGatewayManager')->driver($provider);
        $posted = (array) $this->request->getPost('config');
        if ($driver !== null) {
            foreach ($driver->fields() as $f) {
                $config[$f['key']] = trim((string) ($posted[$f['key']] ?? ''));
            }
        }

        return [$provider, $channel, $status, max(0, $priority), $config];
    }

    private function setStatus(int $id, string $status, string $msg): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $repo = service('paymentGatewayRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/payment-gateways')->with('error', 'Gateway not found.');
        }
        $repo->updateStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/payment-gateways')->with('success', $msg);
    }

    private function guard(): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), 'integration.manage')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
