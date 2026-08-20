<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\ShopApprovalController — the "Pending Approval → Shop Approval" queue for a
 * vendor's 2nd+ shop. Mirrors Admin\VendorApprovalController's shape: a filtered list
 * (approval_status='pending') plus approve/reject actions — except here approve/reject
 * ARE new actions (unlike vendors, Admin\ShopController only ever had
 * activate()/deactivate(), which write status alone and know nothing about the
 * approval axis).
 *
 * Session-guarded (`webAuth`); RBAC-checked (`shop.approve`/`shop.reject`, seeded
 * platform-only in database/sql/85_shop_approval.sql — never granted to vendor_owner,
 * unlike the bulk vendor/shop scope grants elsewhere, because a vendor approving their
 * OWN shop would defeat the entire point of this gate).
 */
final class ShopApprovalController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('shop.approve')) {
            return $denied;
        }
        $req = $this->request;
        $q   = trim((string) $req->getGet('q'));

        $f = ['approval_status' => 'pending', 'q' => $q];

        $repo  = service('shopRepository');
        $total = $repo->countList($f);

        $perPage = in_array((int) $req->getGet('per_page'), [10, 20, 50, 100], true) ? (int) $req->getGet('per_page') : 20;
        $page    = max(1, (int) $req->getGet('page'));
        $f['limit']  = $perPage;
        $f['offset'] = ($page - 1) * $perPage;

        return view('admin/shop-approvals/index', [
            'title'     => 'Shop Approval · Admin',
            'pageTitle' => 'Shop Approval',
            'active'    => 'shop-approvals',
            'userName'  => session()->get('user_name') ?: 'User',
            'shops'     => $repo->list($f),
            'filters'   => ['q' => $q],
            'perPage'   => $perPage,
            'page'      => $page,
            'total'     => $total,
        ]);
    }

    public function approve(int $id): RedirectResponse
    {
        if ($denied = $this->guard('shop.approve')) {
            return $denied;
        }
        $ok = service('shopRepository')->approve($id, (int) session()->get('user_id'));

        return redirect()->to('admin/shop-approvals')->with(
            $ok ? 'success' : 'error',
            $ok ? 'Shop approved — it is now live.' : 'Could not approve — it may already have been decided.',
        );
    }

    public function reject(int $id): RedirectResponse
    {
        if ($denied = $this->guard('shop.reject')) {
            return $denied;
        }
        $reason = trim((string) $this->request->getPost('reason'));
        $ok     = service('shopRepository')->reject($id, (int) session()->get('user_id'), $reason !== '' ? $reason : null);

        return redirect()->to('admin/shop-approvals')->with(
            $ok ? 'success' : 'error',
            $ok ? 'Shop rejected.' : 'Could not reject — it may already have been decided.',
        );
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
