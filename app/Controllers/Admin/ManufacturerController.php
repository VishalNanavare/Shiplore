<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\ManufacturerController — platform oversight of manufacturer accounts.
 *
 * Separate from VendorController rather than a branch inside it. Manufacturers share the
 * `vendors` table under party_type='manufacturer', but almost none of the vendor detail
 * page applies to them: their locations are `mshops` (not `shops`), they accrue no
 * settlements, and they have no delivery configuration. Pointing the vendor screen at a
 * manufacturer produced an empty "No shops" panel and a broken portal button.
 *
 * Permissions are distinct (manufacturer.*) rather than reusing vendor.* — sharing them
 * would silently hand every existing vendor-approver the ability to approve
 * manufacturers too.
 */
final class ManufacturerController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('manufacturer.view')) {
            return $denied;
        }

        $req     = $this->request;
        $status  = trim((string) $req->getGet('status'));
        $q       = trim((string) $req->getGet('q'));
        $perRaw  = (string) $req->getGet('per_page');
        $perPage = in_array((int) $perRaw, [25, 50, 100, 200], true) ? (int) $perRaw : 50;
        $page    = max(1, (int) $req->getGet('page'));

        $f     = ['status' => $status ?: null, 'q' => $q, 'party_type' => 'manufacturer'];
        $repo  = service('vendorRepository');
        $total = $repo->countList($f);
        $f['limit']  = $perPage;
        $f['offset'] = ($page - 1) * $perPage;

        return view('admin/manufacturers/index', [
            'title'         => 'Manufacturers · Admin',
            'pageTitle'     => 'Manufacturers',
            'active'        => 'manufacturers',
            'userName'      => session()->get('user_name') ?: 'User',
            'manufacturers' => $repo->list($f),
            'total'         => $total,
            'page'          => $page,
            'perPage'       => $perPage,
            'filters'       => ['status' => $status, 'q' => $q],
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->guard('manufacturer.view')) {
            return $denied;
        }

        $m = service('vendorRepository')->find($id);
        if ($m === null || ($m['party_type'] ?? 'vendor') !== 'manufacturer') {
            return redirect()->to('admin/manufacturers')->with('error', 'Manufacturer not found.');
        }

        $poRepo = service('purchaseOrderRepository');
        $orders = $poRepo->listForSeller($id);

        return view('admin/manufacturers/show', [
            'title'        => $m['display_name'] . ' · Admin',
            'pageTitle'    => (string) $m['display_name'],
            'active'       => 'manufacturers',
            'userName'     => session()->get('user_name') ?: 'User',
            'manufacturer' => $m,
            'units'        => service('manufacturerUnitRepository')->list($id),
            'orders'       => array_slice($orders, 0, 10),
            'openOrders'   => count(array_filter(
                $orders,
                static fn ($o) => ! in_array((string) $o['status'], ['received', 'closed', 'cancelled', 'rejected'], true),
            )),
            'gst'          => $poRepo->sellerGstSummary($id),
        ]);
    }

    public function approve(int $id): RedirectResponse
    {
        return $this->transition($id, 'manufacturer.approve', 'approved', 'Manufacturer approved.');
    }

    public function reject(int $id): RedirectResponse
    {
        return $this->transition($id, 'manufacturer.reject', 'rejected', 'Manufacturer rejected.');
    }

    private function transition(int $id, string $permission, string $status, string $okMessage): RedirectResponse
    {
        if ($denied = $this->guard($permission)) {
            return $denied;
        }

        $repo = service('vendorRepository');
        $m    = $repo->findById($id);
        if ($m === null || ($m['party_type'] ?? 'vendor') !== 'manufacturer') {
            return redirect()->to('admin/manufacturers')->with('error', 'Manufacturer not found.');
        }

        $repo->updateStatus($id, $status, session()->get('user_id'));

        if (! empty($m['owner_user_id'])) {
            service('notificationService')->notify(
                (int) $m['owner_user_id'],
                'approval_update',
                ['kind' => 'Manufacturer account', 'decision' => $status],
            );
        }

        return redirect()->to('admin/manufacturers')->with('success', $okMessage);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
