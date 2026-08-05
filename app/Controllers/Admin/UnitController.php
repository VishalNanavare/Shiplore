<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Controllers\Admin\Concerns\MasterCrud;
use CodeIgniter\HTTP\RedirectResponse;

/** Admin\UnitController — units of measure CRUD. unit.manage / unit.view. */
final class UnitController extends BaseController
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
            'table' => 'units', 'slug' => 'units', 'label' => 'Unit',
            'permView' => 'unit.view', 'permCreate' => 'unit.manage', 'permUpdate' => 'unit.manage',
            'columns' => [['code', 'Code'], ['name', 'Name']],
            'fields' => [
                ['code', 'Code', 'text', true],
                ['name', 'Name', 'text', true],
                ['allow_fraction', 'Allow fractional qty', 'checkbox', false],
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
