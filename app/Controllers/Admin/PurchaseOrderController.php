<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\PurchaseOrderController — platform oversight of monline B2B purchase orders.
 *
 * Read-first. A purchase order belongs to its two trading parties, and the platform is
 * not one of them — this screen exists so support can answer "what happened to PO X",
 * not so staff can run the trade.
 *
 * The single write is a force-cancel for an order stuck between two parties who will not
 * move it. It goes through the same StatusMachine gate as every other transition, so it
 * cannot cancel anything already dispatched or received.
 */
final class PurchaseOrderController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('monline.po.oversight.view')) {
            return $denied;
        }

        $req     = $this->request;
        $status  = trim((string) $req->getGet('status'));
        $q       = trim((string) $req->getGet('q'));
        $perRaw  = (string) $req->getGet('per_page');
        $perPage = in_array((int) $perRaw, [25, 50, 100, 200], true) ? (int) $perRaw : 50;
        $page    = max(1, (int) $req->getGet('page'));

        $f    = ['status' => $status ?: null, 'q' => $q];
        $repo = service('purchaseOrderRepository');

        return view('admin/purchase-orders/index', [
            'title'     => 'B2B Purchase Orders · Admin',
            'pageTitle' => 'B2B Purchase Orders',
            'active'    => 'purchase-orders',
            'userName'  => session()->get('user_name') ?: 'User',
            'orders'    => $repo->listForAdmin($f, $perPage, ($page - 1) * $perPage),
            'total'     => $repo->countForAdmin($f),
            'page'      => $page,
            'perPage'   => $perPage,
            'filters'   => ['status' => $status, 'q' => $q],
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->guard('monline.po.oversight.view')) {
            return $denied;
        }

        $found = service('purchaseOrderRepository')->findForAdmin($id);
        if ($found === null) {
            return redirect()->to('admin/purchase-orders')->with('error', 'Purchase order not found.');
        }

        return view('admin/purchase-orders/show', [
            'title'     => $found['po']['po_no'] . ' · Admin',
            'pageTitle' => (string) $found['po']['po_no'],
            'active'    => 'purchase-orders',
            'userName'  => session()->get('user_name') ?: 'User',
            'po'        => $found['po'],
            'items'     => $found['items'],
        ]);
    }

    public function cancel(int $id): RedirectResponse
    {
        if ($denied = $this->guard('monline.po.oversight.cancel')) {
            return $denied;
        }

        $reason = trim((string) $this->request->getPost('reason'));
        if ($reason === '') {
            return redirect()->to('admin/purchase-orders/' . $id)->with('error', 'A reason is required to cancel an order on behalf of the parties.');
        }

        $res = service('purchaseOrderRepository')->adminCancel($id, session()->get('user_id'), $reason);

        return $res['ok']
            ? redirect()->to('admin/purchase-orders/' . $id)->with('success', 'Purchase order cancelled — both parties have been notified.')
            : redirect()->to('admin/purchase-orders/' . $id)->with('error', $res['error']);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
