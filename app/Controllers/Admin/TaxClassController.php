<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Controllers\Admin\Concerns\MasterCrud;
use CodeIgniter\HTTP\RedirectResponse;

/** Admin\TaxClassController — GST tax classes CRUD. tax.manage / tax.view. */
final class TaxClassController extends BaseController
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
            'table' => 'tax_classes', 'slug' => 'tax-classes', 'label' => 'Tax Class',
            'permView' => 'tax.view', 'permCreate' => 'tax.manage', 'permUpdate' => 'tax.manage',
            'columns' => [['code', 'Code'], ['name', 'Name']],
            'fields' => [
                ['code', 'Code', 'text', true],
                ['name', 'Name', 'text', true],
                ['description', 'Description', 'text', false],
                ['is_exempt', 'Exempt', 'checkbox', false],
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
