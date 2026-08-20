<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/**
 * Admin\VendorApprovalController — the "Pending Approval → Vendor Approval" queue.
 * A read-only, filtered VIEW onto the vendor list (status IN submitted/under_review by
 * default); the actual approve/reject WRITES stay on VendorController's existing
 * admin/vendors/{id}/approve and /reject routes — vendor.approve/vendor.reject remain
 * the single source of truth for that permission and transition logic, not duplicated
 * here. The approve/reject buttons on this page target those same routes with
 * data-ajax-refresh, so acting from the queue re-renders the queue in place rather than
 * bouncing to the general vendors list.
 *
 * Session-guarded (`webAuth`); RBAC-checked (`vendor.view` to see the queue — the same
 * permission the general Vendors list already requires).
 */
final class VendorApprovalController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('vendor.view')) {
            return $denied;
        }
        $req = $this->request;
        $q   = trim((string) $req->getGet('q'));

        $f = ['status' => ['submitted', 'under_review'], 'q' => $q, 'party_type' => 'vendor'];

        $repo  = service('vendorRepository');
        $total = $repo->countList($f);

        $perPage = in_array((int) $req->getGet('per_page'), [10, 20, 50, 100], true) ? (int) $req->getGet('per_page') : 20;
        $page    = max(1, (int) $req->getGet('page'));
        $f['limit']  = $perPage;
        $f['offset'] = ($page - 1) * $perPage;

        return view('admin/vendor-approvals/index', [
            'title'     => 'Vendor Approval · Admin',
            'pageTitle' => 'Vendor Approval',
            'active'    => 'vendor-approvals',
            'userName'  => session()->get('user_name') ?: 'User',
            'vendors'   => $repo->list($f),
            'filters'   => ['q' => $q],
            'perPage'   => $perPage,
            'page'      => $page,
            'total'     => $total,
        ]);
    }

    private function guard(string $permission): ?\CodeIgniter\HTTP\RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
