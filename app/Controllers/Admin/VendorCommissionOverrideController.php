<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/** Admin\VendorCommissionOverrideController — CRUD for vendor_commission_overrides. */
final class VendorCommissionOverrideController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('vendor_commission_override.view')) {
            return $denied;
        }

        return view('admin/vendor_commission_overrides/index', [
            'title' => 'Vendor Commission Overrides · Admin', 'pageTitle' => 'Vendor Commission Overrides',
            'active' => 'commission', 'userName' => session()->get('user_name') ?: 'User',
            'overrides' => service('commissionRuleRepository')->listOverrides(),
        ]);
    }

    public function new()
    {
        if ($denied = $this->guard('vendor_commission_override.manage')) {
            return $denied;
        }

        return view('admin/vendor_commission_overrides/form', [
            'title' => 'New Override · Admin', 'pageTitle' => 'New Override',
            'active' => 'commission', 'userName' => session()->get('user_name') ?: 'User', 'row' => null,
        ]);
    }

    public function edit(int $id)
    {
        if ($denied = $this->guard('vendor_commission_override.manage')) {
            return $denied;
        }
        $row = service('commissionRuleRepository')->findOverride($id);
        if ($row === null) {
            return redirect()->to('admin/vendor-commission-overrides')->with('error', 'Override not found.');
        }

        return view('admin/vendor_commission_overrides/form', [
            'title' => 'Edit Override · Admin', 'pageTitle' => 'Edit Override',
            'active' => 'commission', 'userName' => session()->get('user_name') ?: 'User', 'row' => $row,
        ]);
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->guard('vendor_commission_override.manage')) {
            return $denied;
        }
        [$data, $err] = $this->collect();
        if ($err !== '') {
            return redirect()->back()->withInput()->with('error', $err);
        }
        service('commissionRuleRepository')->createOverride($data, (int) session()->get('user_id'));

        return redirect()->to('admin/vendor-commission-overrides')->with('success', 'Override created.');
    }

    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->guard('vendor_commission_override.manage')) {
            return $denied;
        }
        $repo = service('commissionRuleRepository');
        if ($repo->findOverride($id) === null) {
            return redirect()->to('admin/vendor-commission-overrides')->with('error', 'Override not found.');
        }
        [$data, $err] = $this->collect();
        if ($err !== '') {
            return redirect()->back()->withInput()->with('error', $err);
        }
        $repo->updateOverride($id, $data, (int) session()->get('user_id'));

        return redirect()->to('admin/vendor-commission-overrides')->with('success', 'Override updated.');
    }

    public function delete(int $id): RedirectResponse
    {
        if ($denied = $this->guard('vendor_commission_override.manage')) {
            return $denied;
        }
        service('commissionRuleRepository')->deleteOverride($id, (int) session()->get('user_id'));

        return redirect()->to('admin/vendor-commission-overrides')->with('success', 'Override deleted.');
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function collect(): array
    {
        $vendorId = (int) $this->request->getPost('vendor_id');
        if ($vendorId <= 0) {
            return [[], 'Vendor is required.'];
        }
        $rate = $this->request->getPost('rate');
        if ($rate === null || $rate === '') {
            return [[], 'Rate is required.'];
        }
        $validFrom = trim((string) $this->request->getPost('valid_from'));
        if ($validFrom === '') {
            return [[], 'Valid-from date is required.'];
        }

        $catRaw = $this->request->getPost('category_id');

        return [[
            'vendor_id'   => $vendorId,
            'category_id' => $catRaw === null || $catRaw === '' ? null : (int) $catRaw,
            'rate'        => (float) $rate,
            'valid_from'  => $validFrom,
            'valid_to'    => (trim((string) $this->request->getPost('valid_to')) ?: null),
            'status'      => 'active',
        ], ''];
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
