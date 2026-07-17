<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\ProductApprovalController — review queue for vendor-submitted products.
 * Lists products awaiting review and approves / rejects them. Session-guarded
 * (`webAuth`); RBAC-checked (`product.review` to view, `product.approve` /
 * `product.reject` to decide); POSTs CSRF-protected at the route.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md §3
 */
final class ProductApprovalController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('product.review')) {
            return $denied;
        }
        $req = $this->request;
        $f   = [
            'status'      => trim((string) $req->getGet('status')),
            'vendor_id'   => is_numeric($req->getGet('vendor_id')) ? (int) $req->getGet('vendor_id') : null,
            'category_id' => is_numeric($req->getGet('category_id')) ? (int) $req->getGet('category_id') : null,
            'q'           => trim((string) $req->getGet('q')),
        ];
        $perPage = in_array((int) $req->getGet('per_page'), [10, 20, 50, 100], true) ? (int) $req->getGet('per_page') : 20;
        $page    = max(1, (int) $req->getGet('page'));

        $repo  = service('productApprovalRepository');
        $total = $repo->countList($f);

        $f['limit']  = $perPage;
        $f['offset'] = ($page - 1) * $perPage;

        return view('admin/product-approvals/index', [
            'title'      => 'Product Approvals · Admin',
            'pageTitle'  => 'Product Approvals',
            'active'     => 'product-approvals',
            'userName'   => session()->get('user_name') ?: 'User',
            'products'   => $repo->list($f),
            'vendors'    => service('shopRepository')->vendorsForSelect(),
            'categories' => service('categoryRepository')->options(),
            'statuses'   => ['submitted', 'under_review', 'approved', 'published', 'rejected', 'unpublished'],
            'filters'    => $f,
            'perPage'    => $perPage,
            'page'       => $page,
            'total'      => $total,
        ]);
    }

    /** Apply one decision (approve/reject/publish/unpublish) to many selected products. */
    public function bulk(): RedirectResponse
    {
        if ($denied = $this->guard('product.approve')) {
            return $denied;
        }
        $action = (string) $this->request->getPost('bulk_action');
        $ids    = array_values(array_filter(array_map('intval', (array) $this->request->getPost('ids'))));
        if ($action === '' || $ids === []) {
            return redirect()->to('admin/product-approvals')->with('error', 'Select one or more products and an action.');
        }
        $repo   = service('productApprovalRepository');
        $uid    = (int) session()->get('user_id');
        $reason = trim((string) $this->request->getPost('reason')) ?: null;
        $ok     = 0;
        $skip   = 0;
        foreach ($ids as $id) {
            $p = $repo->findById($id);
            if ($p === null) {
                $skip++;
                continue;
            }
            $st   = (string) ($p['status'] ?? '');
            $done = match ($action) {
                'approve'   => in_array($st, ['submitted', 'under_review'], true) && $repo->approve($id, $uid),
                'reject'    => in_array($st, ['submitted', 'under_review'], true) && $repo->reject($id, $uid, $reason),
                'publish'   => in_array($st, ['approved', 'unpublished'], true) && $repo->publish($id, $uid),
                'unpublish' => $st === 'published' && $repo->unpublish($id, $uid, $reason),
                default     => false,
            };
            $done ? $ok++ : $skip++;
        }
        $verb = ['approve' => 'approved', 'reject' => 'rejected', 'publish' => 'published', 'unpublish' => 'unpublished'][$action] ?? 'updated';

        return redirect()->to('admin/product-approvals')->with($ok > 0 ? 'success' : 'error',
            $ok . ' product(s) ' . $verb . '.' . ($skip > 0 ? ' ' . $skip . ' skipped (not eligible).' : ''));
    }

    public function approve(int $id): RedirectResponse
    {
        if ($denied = $this->guard('product.approve')) {
            return $denied;
        }

        $repo = service('productApprovalRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/product-approvals')->with('error', 'Product not found.');
        }

        $repo->approve($id, (int) session()->get('user_id'));

        return redirect()->to('admin/product-approvals')->with('success', 'Product approved.');
    }

    public function reject(int $id): RedirectResponse
    {
        if ($denied = $this->guard('product.reject')) {
            return $denied;
        }

        $repo = service('productApprovalRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/product-approvals')->with('error', 'Product not found.');
        }

        $reason = trim((string) $this->request->getPost('reason'));
        $repo->reject($id, (int) session()->get('user_id'), $reason !== '' ? $reason : null);

        return redirect()->to('admin/product-approvals')->with('success', 'Product rejected.');
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }

    public function publish(int $id): RedirectResponse
    {
        if ($denied = $this->guard('product.approve')) {
            return $denied;
        }
        $ok = service('productApprovalRepository')->publish($id, (int) session()->get('user_id'));

        return redirect()->to('admin/product-approvals')->with($ok ? 'success' : 'error', $ok ? 'Product published — now live on the storefront.' : 'Only approved/unpublished products can be published.');
    }

    public function unpublish(int $id): RedirectResponse
    {
        if ($denied = $this->guard('product.approve')) {
            return $denied;
        }
        $ok = service('productApprovalRepository')->unpublish($id, (int) session()->get('user_id'), trim((string) $this->request->getPost('reason')) ?: null);

        return redirect()->to('admin/product-approvals')->with($ok ? 'success' : 'error', $ok ? 'Product unpublished.' : 'Only published products can be unpublished.');
    }

    public function requestChanges(int $id): RedirectResponse
    {
        if ($denied = $this->guard('product.reject')) {
            return $denied;
        }
        $reason = trim((string) $this->request->getPost('reason'));
        $ok = service('productApprovalRepository')->requestChanges($id, (int) session()->get('user_id'), $reason !== '' ? $reason : null);

        return redirect()->to('admin/product-approvals')->with($ok ? 'success' : 'error', $ok ? 'Sent back to the vendor for changes.' : 'Cannot request changes from the current status.');
    }
}
