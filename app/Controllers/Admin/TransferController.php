<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\TransferController — platform oversight of inter-store transfers across
 * ALL vendors. The admin can monitor every transfer and OVERRIDE its lifecycle
 * (approve / reject / pack / dispatch / receive / cancel / close) when a vendor
 * is stuck. Overrides run through the same TransferService as the vendor flow —
 * so stock actually moves and the action is audited — by resolving the
 * transfer's own vendor_id. transfer.view / transfer.approve.
 */
final class TransferController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('transfer.view')) {
            return $denied;
        }

        return view('admin/transfers/index', [
            'title'     => 'Stock Transfers · Admin',
            'pageTitle' => 'Stock Transfers',
            'active'    => 'transfers',
            'userName'  => session()->get('user_name') ?: 'User',
            'transfers' => service('transferRepository')->list($this->statusFilter()),
        ]);
    }

    public function approve(int $id): RedirectResponse
    {
        return $this->override($id, static fn ($svc, $vid, $actor) => $svc->approve($id, $vid, $actor));
    }

    public function reject(int $id): RedirectResponse
    {
        return $this->override($id, fn ($svc, $vid, $actor) => $svc->reject($id, $vid, $actor, (string) $this->request->getPost('reason')));
    }

    public function pack(int $id): RedirectResponse
    {
        return $this->override($id, static fn ($svc, $vid, $actor) => $svc->pack($id, $vid, $actor));
    }

    public function dispatch(int $id): RedirectResponse
    {
        return $this->override($id, static fn ($svc, $vid, $actor) => $svc->dispatch($id, $vid, $actor));
    }

    public function receive(int $id): RedirectResponse
    {
        return $this->override($id, fn ($svc, $vid, $actor) => $svc->receive($id, $vid, (float) $this->request->getPost('qty_received'), $actor));
    }

    public function close(int $id): RedirectResponse
    {
        return $this->override($id, static fn ($svc, $vid, $actor) => $svc->close($id, $vid, $actor));
    }

    public function cancel(int $id): RedirectResponse
    {
        return $this->override($id, static fn ($svc, $vid, $actor) => $svc->cancel($id, $vid, $actor));
    }

    /** Resolve the transfer's vendor, then drive the real engine as that vendor (admin override). */
    private function override(int $id, callable $fn): RedirectResponse
    {
        if ($denied = $this->guard('transfer.approve')) {
            return $denied;
        }
        $transfer = service('transferRepository')->findById($id);
        if ($transfer === null) {
            return redirect()->to('admin/transfers')->with('error', 'Transfer not found.');
        }
        $res = $fn(service('transferService'), (int) $transfer['vendor_id'], (int) session()->get('user_id'));

        return redirect()->to('admin/transfers')->with($res['ok'] ? 'success' : 'error', $res['message']);
    }

    private function statusFilter(): ?string
    {
        $s = $this->request->getGet('status');

        return is_string($s) ? $s : null;
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
