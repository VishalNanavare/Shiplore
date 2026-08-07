<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Controllers\Admin\Concerns\MasterCrud;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\BusinessTypeController — business-type governance + CRUD. Guarded by
 * `businesstype.manage`. POSTs CSRF-protected at the route.
 *
 * @see docs/architecture/31-BUSINESS-TYPE-COMMISSION.md
 */
final class BusinessTypeController extends BaseController
{
    use MasterCrud;

    /** @return array<string,mixed> */
    protected function masterSpec(): array
    {
        return [
            'table' => 'business_types', 'slug' => 'business-types', 'label' => 'Business Type',
            'permCreate' => 'businesstype.manage', 'permUpdate' => 'businesstype.manage',
            'hasUuid' => true, 'slugFrom' => 'name',
            'fields' => [
                ['code', 'Code', 'text', true],
                ['name', 'Name', 'text', true],
                ['description', 'Description', 'textarea', false],
                ['default_commission_rate', 'Default commission %', 'number', false],
            ],
        ];
    }

    public function index()
    {
        if ($denied = $this->guard('businesstype.manage')) {
            return $denied;
        }

        $status = $this->request->getGet('status');

        return view('admin/business-types/index', [
            'title'     => 'Business Types · Admin',
            'pageTitle' => 'Business Types',
            'active'    => 'business-types',
            'userName'  => session()->get('user_name') ?: 'User',
            'types'     => service('businessTypeRepository')->list(is_string($status) ? $status : null),
        ]);
    }

    /** Override MasterCrud::edit() to also load category mappings. */
    public function edit(int $id)
    {
        if ($denied = $this->guard('businesstype.manage')) {
            return $denied;
        }
        $row = \Config\Database::connect()->table('business_types')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
        if ($row === null) {
            return redirect()->to('admin/business-types')->with('error', 'Business type not found.');
        }

        $repo       = service('businessTypeRepository');
        $mapped     = array_column($repo->categoriesFor($id), 'id');
        $allCats    = \Config\Database::connect()->table('categories c')
            ->select('c.id, c.name, c.parent_id, p.name AS parent_name')
            ->join('categories p', 'p.id = c.parent_id', 'left')
            ->where('c.status', 'active')->where('c.deleted_at', null)
            ->orderBy('p.name IS NULL', 'DESC', false)->orderBy('p.name')->orderBy('c.name')
            ->get()->getResultArray();

        return view('admin/business-types/edit', [
            'title'      => 'Edit Business Type · Admin',
            'pageTitle'  => 'Edit: ' . esc($row['name']),
            'active'     => 'business-types',
            'userName'   => session()->get('user_name') ?: 'User',
            'type'       => $row,
            'allCats'    => $allCats,
            'mappedIds'  => $mapped,
        ]);
    }

    /** Override MasterCrud::update() to also save category mappings. */
    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->guard('businesstype.manage')) {
            return $denied;
        }
        $db = \Config\Database::connect();
        if ($db->table('business_types')->where('id', $id)->where('deleted_at', null)->countAllResults() === 0) {
            return redirect()->to('admin/business-types')->with('error', 'Business type not found.');
        }

        $raw = $this->request->getPost('category_ids');
        $ids = is_array($raw) ? array_map('intval', $raw) : [];
        // Stop before the settings update if the mapping did not save: reporting one
        // success for two writes, only one of which happened, is how an admin ends up
        // trusting a mapping that is not there.
        if (! service('businessTypeRepository')->syncCategories($id, $ids)) {
            return redirect()->to('admin/business-types/' . $id . '/edit')
                ->with('error', 'Could not save the category mapping. No changes were made.');
        }

        $description = trim((string) $this->request->getPost('description'));
        $commission  = $this->request->getPost('default_commission_rate');
        $db->table('business_types')->where('id', $id)->update([
            'description'            => $description !== '' ? $description : null,
            'default_commission_rate' => ($commission !== '' && $commission !== null) ? $commission : null,
            'updated_by'             => (int) session()->get('user_id'),
        ]);

        return redirect()->to('admin/business-types/' . $id . '/edit')->with('success', 'Category mapping and settings saved.');
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->transition($id, 'active', 'Business type activated.');
    }

    public function deactivate(int $id): RedirectResponse
    {
        return $this->transition($id, 'inactive', 'Business type deactivated.');
    }

    private function transition(int $id, string $status, string $okMessage): RedirectResponse
    {
        if ($denied = $this->guard('businesstype.manage')) {
            return $denied;
        }

        $repo = service('businessTypeRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/business-types')->with('error', 'Business type not found.');
        }

        $repo->updateStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/business-types')->with('success', $okMessage);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
