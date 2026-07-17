<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Controllers\Admin\Concerns\MasterCrud;
use CodeIgniter\HTTP\RedirectResponse;

/** Admin\HsnController — HSN/SAC codes CRUD. hsn.manage. */
final class HsnController extends BaseController
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
            'table' => 'hsn_sac_codes', 'slug' => 'hsn-codes', 'label' => 'HSN/SAC Code',
            'permView' => 'hsn.manage', 'permCreate' => 'hsn.manage', 'permUpdate' => 'hsn.manage',
            'columns' => [['code', 'Code'], ['type', 'Type'], ['description', 'Description']],
            'fields' => [
                ['code', 'Code', 'text', true],
                ['type', 'Type', 'select', true, ['hsn' => 'HSN (goods)', 'sac' => 'SAC (services)']],
                ['description', 'Description', 'text', true],
            ],
        ];
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
