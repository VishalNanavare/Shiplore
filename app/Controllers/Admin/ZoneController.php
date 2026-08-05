<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Controllers\Admin\Concerns\MasterCrud;
use CodeIgniter\HTTP\RedirectResponse;

/** Admin\ZoneController — delivery zones CRUD. zone.manage. */
final class ZoneController extends BaseController
{
    use MasterCrud;

    public function index()
    {
        return $this->masterIndex();
    }

    /** @return array<string,mixed> */
    protected function masterSpec(): array
    {
        return [
            'table' => 'delivery_zones', 'slug' => 'zones', 'label' => 'Delivery Zone',
            'permView' => 'zone.manage', 'permCreate' => 'zone.manage', 'permUpdate' => 'zone.manage',
            'columns' => [['name', 'Name'], ['code', 'Code'], ['pincode_from', 'PIN from'], ['pincode_to', 'PIN to']],
            'fields' => [
                ['name', 'Zone name', 'text', true],
                ['code', 'Code', 'text', false],
                ['pincode_from', 'Pincode from', 'text', false],
                ['pincode_to', 'Pincode to', 'text', false],
                ['zone_type', 'Type', 'select', false, ['standard' => 'Standard', 'express' => 'Express', 'remote' => 'Remote']],
            ],
        ];
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
