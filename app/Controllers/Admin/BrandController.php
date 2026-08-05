<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Controllers\Admin\Concerns\MasterCrud;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;

/** Admin\BrandController — brand governance + CRUD. brand.view/create/update/approve. */
final class BrandController extends BaseController
{
    use MasterCrud;

    /** @return array<string,mixed> */
    protected function masterSpec(): array
    {
        return [
            'table' => 'brands', 'slug' => 'brands', 'label' => 'Brand',
            'permCreate' => 'brand.create', 'permUpdate' => 'brand.update',
            'hasUuid' => true, 'slugFrom' => 'name',
            'fields' => [['name', 'Brand name', 'text', true]],
        ];
    }

    public function index()
    {
        if ($denied = $this->guard('brand.view')) {
            return $denied;
        }

        return view('admin/brands/index', [
            'title'     => 'Brands · Admin',
            'pageTitle' => 'Brands',
            'active'    => 'brands',
            'userName'  => session()->get('user_name') ?: 'User',
            'brands'    => service('brandRepository')->list($this->statusFilter()),
        ]);
    }

    public function edit(int $id)
    {
        if ($denied = $this->guard('brand.update')) {
            return $denied;
        }
        $db    = Database::connect();
        $brand = $db->table('brands')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
        if ($brand === null) {
            return redirect()->to('admin/brands')->with('error', 'Brand not found.');
        }
        $categories = $db->table('categories')
            ->select('id, name')
            ->where('status', 'active')->where('deleted_at', null)
            ->orderBy('name')->get()->getResultArray();
        $mapped = array_column(
            $db->table('brand_categories')->select('category_id')->where('brand_id', $id)->get()->getResultArray(),
            'category_id'
        );

        return view('admin/brands/edit', [
            'title'      => 'Edit Brand · Admin',
            'pageTitle'  => 'Edit Brand',
            'active'     => 'brands',
            'userName'   => session()->get('user_name') ?: 'User',
            'brand'      => $brand,
            'categories' => $categories,
            'mapped'     => array_map('intval', $mapped),
        ]);
    }

    public function saveCategories(int $id): RedirectResponse
    {
        if ($denied = $this->guard('brand.update')) {
            return $denied;
        }
        $db    = Database::connect();
        $brand = $db->table('brands')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
        if ($brand === null) {
            return redirect()->to('admin/brands')->with('error', 'Brand not found.');
        }
        $selected = $this->request->getPost('categories') ?? [];
        $selected = array_filter(array_map('intval', (array) $selected));

        $db->transStart();
        $db->table('brand_categories')->where('brand_id', $id)->delete();
        if ($selected !== []) {
            $rows = array_map(static fn (int $cid) => ['brand_id' => $id, 'category_id' => $cid], $selected);
            $db->table('brand_categories')->insertBatch($rows);
        }
        $db->transComplete();

        return redirect()->to('admin/brands/' . $id . '/edit')->with('success', 'Category mappings saved.');
    }

    public function approve(int $id): RedirectResponse
    {
        return $this->transition($id, 'active', 'Brand approved.');
    }

    public function deactivate(int $id): RedirectResponse
    {
        return $this->transition($id, 'inactive', 'Brand deactivated.');
    }

    private function transition(int $id, string $status, string $msg): RedirectResponse
    {
        if ($denied = $this->guard('brand.approve')) {
            return $denied;
        }
        $repo = service('brandRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/brands')->with('error', 'Brand not found.');
        }
        $repo->updateStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/brands')->with('success', $msg);
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
