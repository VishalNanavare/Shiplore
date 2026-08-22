<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\CategoryController — category-tree governance. View needs
 * `category.view`; activate / deactivate needs `category.update`. POSTs
 * CSRF-protected at the route.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md
 */
final class CategoryController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('category.view')) {
            return $denied;
        }

        $status = $this->request->getGet('status');

        return view('admin/categories/index', [
            'title'      => 'Categories · Admin',
            'pageTitle'  => 'Categories',
            'active'     => 'categories',
            'userName'   => session()->get('user_name') ?: 'User',
            'categories' => service('categoryRepository')->list(is_string($status) ? $status : null),
        ]);
    }

    public function new()
    {
        if ($denied = $this->guard('category.create')) {
            return $denied;
        }

        return $this->form(null);
    }

    public function edit(int $id)
    {
        if ($denied = $this->guard('category.update')) {
            return $denied;
        }
        $row = service('categoryRepository')->findById($id);
        if ($row === null) {
            return redirect()->to('admin/categories')->with('error', 'Category not found.');
        }

        return $this->form($row);
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->guard('category.create')) {
            return $denied;
        }
        $name = trim((string) $this->request->getPost('name'));
        if ($name === '') {
            return redirect()->back()->withInput()->with('error', 'Name is required.');
        }
        service('categoryRepository')->create([
            'name' => $name, 'parent_id' => $this->request->getPost('parent_id'),
            'default_commission_rate' => trim((string) $this->request->getPost('default_commission_rate')),
            'sort_order' => (int) $this->request->getPost('sort_order'),
        ], (int) session()->get('user_id'));

        return redirect()->to('admin/categories')->with('success', 'Category created.');
    }

    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->guard('category.update')) {
            return $denied;
        }
        if (service('categoryRepository')->findById($id) === null) {
            return redirect()->to('admin/categories')->with('error', 'Category not found.');
        }
        $name = trim((string) $this->request->getPost('name'));
        if ($name === '') {
            return redirect()->back()->withInput()->with('error', 'Name is required.');
        }
        service('categoryRepository')->update($id, [
            'name' => $name,
            'default_commission_rate' => trim((string) $this->request->getPost('default_commission_rate')),
            'sort_order' => (int) $this->request->getPost('sort_order'),
        ], (int) session()->get('user_id'));

        return redirect()->to('admin/categories')->with('success', 'Category updated.');
    }

    /** @param array<string,mixed>|null $row */
    private function form(?array $row): string
    {
        return view('admin/categories/form', [
            'title' => ($row ? 'Edit' : 'New') . ' Category · Admin',
            'pageTitle' => $row ? 'Edit Category' : 'New Category',
            'active' => 'categories', 'userName' => session()->get('user_name') ?: 'User',
            'row' => $row, 'options' => service('categoryRepository')->options(),
        ]);
    }

    public function attributesForm(int $id)
    {
        if ($denied = $this->guard('category.update')) {
            return $denied;
        }
        $category = service('categoryRepository')->findById($id);
        if ($category === null) {
            return redirect()->to('admin/categories')->with('error', 'Category not found.');
        }

        return view('admin/categories/attributes', [
            'title'     => 'Attributes · ' . $category['name'] . ' · Admin',
            'pageTitle' => 'Attributes — ' . $category['name'],
            'active'    => 'categories',
            'userName'  => session()->get('user_name') ?: 'User',
            'category'  => $category,
            'attributes' => service('attributeRepository')->list('active'),
            'mappedIds'  => service('categoryRepository')->mappedAttributeIds($id),
        ]);
    }

    public function saveAttributes(int $id): RedirectResponse
    {
        if ($denied = $this->guard('category.update')) {
            return $denied;
        }
        if (service('categoryRepository')->findById($id) === null) {
            return redirect()->to('admin/categories')->with('error', 'Category not found.');
        }

        $ids  = array_filter(array_map('intval', (array) $this->request->getPost('attribute_ids')));
        $saved = service('categoryRepository')->setAttributeMapping($id, $ids);

        return $saved
            ? redirect()->to('admin/categories/' . $id . '/attributes')->with('success', 'Attribute mapping saved.')
            : redirect()->to('admin/categories/' . $id . '/attributes')->with('error', 'Could not save the attribute mapping. Nothing was changed.');
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->transition($id, 'active', 'Category activated.');
    }

    public function deactivate(int $id): RedirectResponse
    {
        return $this->transition($id, 'inactive', 'Category deactivated.');
    }

    private function transition(int $id, string $status, string $okMessage): RedirectResponse
    {
        if ($denied = $this->guard('category.update')) {
            return $denied;
        }

        $repo = service('categoryRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/categories')->with('error', 'Category not found.');
        }

        $repo->updateStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/categories')->with('success', $okMessage);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
