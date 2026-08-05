<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/** Admin\WarehouseController — warehouse oversight. warehouse.view / warehouse.manage. */
final class WarehouseController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('warehouse.view')) {
            return $denied;
        }

        return view('admin/warehouses/index', [
            'title'      => 'Warehouses · Admin',
            'pageTitle'  => 'Warehouses',
            'active'     => 'warehouses',
            'userName'   => session()->get('user_name') ?: 'User',
            'warehouses' => service('warehouseRepository')->list($this->statusFilter()),
        ]);
    }

    public function new()
    {
        if ($denied = $this->guard('warehouse.manage')) {
            return $denied;
        }

        return $this->form(null);
    }

    public function edit(int $id)
    {
        if ($denied = $this->guard('warehouse.manage')) {
            return $denied;
        }
        $row = service('warehouseRepository')->findById($id);
        if ($row === null) {
            return redirect()->to('admin/warehouses')->with('error', 'Warehouse not found.');
        }

        return $this->form($row);
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->guard('warehouse.manage')) {
            return $denied;
        }
        $in = $this->whInput(true);
        if ($in['vendor_id'] <= 0 || $in['name'] === '' || $in['code'] === '') {
            return redirect()->back()->withInput()->with('error', 'Vendor, name and code are required.');
        }
        service('warehouseRepository')->create($in, (int) session()->get('user_id'));

        return redirect()->to('admin/warehouses')->with('success', 'Warehouse created.');
    }

    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->guard('warehouse.manage')) {
            return $denied;
        }
        if (service('warehouseRepository')->findById($id) === null) {
            return redirect()->to('admin/warehouses')->with('error', 'Warehouse not found.');
        }
        $in = $this->whInput(false);
        if ($in['name'] === '') {
            return redirect()->back()->withInput()->with('error', 'Name is required.');
        }
        service('warehouseRepository')->update($id, $in, (int) session()->get('user_id'));

        return redirect()->to('admin/warehouses')->with('success', 'Warehouse updated.');
    }

    /** @param array<string,mixed>|null $row */
    private function form(?array $row): string
    {
        $addr = $row !== null ? (json_decode((string) ($row['address_json'] ?? '{}'), true) ?: []) : [];

        return view('admin/warehouses/form', [
            'title' => ($row ? 'Edit' : 'New') . ' Warehouse · Admin',
            'pageTitle' => $row ? 'Edit Warehouse' : 'New Warehouse',
            'active' => 'warehouses', 'userName' => session()->get('user_name') ?: 'User',
            'row' => $row, 'addr' => $addr, 'vendors' => service('warehouseRepository')->vendorsForSelect(),
        ]);
    }

    /** @return array<string,mixed> */
    private function whInput(bool $withVendor): array
    {
        $p = static fn (string $k): string => trim((string) service('request')->getPost($k));
        $in = ['name' => $p('name'), 'code' => $p('code'), 'address' => $p('address'), 'city' => $p('city'),
            'pincode' => $p('pincode'), 'state_code' => $p('state_code'), 'latitude' => $p('latitude'), 'longitude' => $p('longitude')];
        if ($withVendor) {
            $in['vendor_id'] = (int) service('request')->getPost('vendor_id');
        }

        return $in;
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->transition($id, 'active', 'Warehouse activated.');
    }

    public function deactivate(int $id): RedirectResponse
    {
        return $this->transition($id, 'inactive', 'Warehouse deactivated.');
    }

    private function transition(int $id, string $status, string $msg): RedirectResponse
    {
        if ($denied = $this->guard('warehouse.manage')) {
            return $denied;
        }
        $repo = service('warehouseRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/warehouses')->with('error', 'Warehouse not found.');
        }
        $repo->updateStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/warehouses')->with('success', $msg);
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
