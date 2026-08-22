<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\AttributeValueController — the controlled vocabulary for one attribute
 * (e.g. Color -> Red/Blue/White). Reachable only from admin/attributes/{id}/edit,
 * not a top-level nav item. Same view/manage permissions and in-use guard shape as
 * AttributeController, one level down: a value can be blocked while its sibling
 * values on the same attribute remain free to deactivate/delete.
 */
final class AttributeValueController extends BaseController
{
    public function index(int $attributeId)
    {
        if ($denied = $this->guard('attribute.view')) {
            return $denied;
        }

        $attribute = service('attributeRepository')->findById($attributeId);
        if ($attribute === null) {
            return redirect()->to('admin/attributes')->with('error', 'Attribute not found.');
        }

        return view('admin/attributes/values', [
            'title'     => 'Manage values · ' . $attribute['name'] . ' · Admin',
            'pageTitle' => 'Manage values — ' . $attribute['name'],
            'active'    => 'attributes',
            'userName'  => session()->get('user_name') ?: 'User',
            'attribute' => $attribute,
            'values'    => service('attributeValueRepository')->listForAttribute($attributeId),
        ]);
    }

    public function new(int $attributeId)
    {
        if ($denied = $this->guard('attribute.manage')) {
            return $denied;
        }

        $attribute = $this->requireAttribute($attributeId);
        if ($attribute instanceof RedirectResponse) {
            return $attribute;
        }

        return view('admin/attributes/value_form', [
            'title'     => 'New value · ' . $attribute['name'] . ' · Admin',
            'pageTitle' => 'New value — ' . $attribute['name'],
            'active'    => 'attributes',
            'userName'  => session()->get('user_name') ?: 'User',
            'attribute' => $attribute,
            'row'       => null,
        ]);
    }

    public function store(int $attributeId): RedirectResponse
    {
        if ($denied = $this->guard('attribute.manage')) {
            return $denied;
        }

        $attribute = $this->requireAttribute($attributeId);
        if ($attribute instanceof RedirectResponse) {
            return $attribute;
        }

        $value = trim((string) $this->request->getPost('value'));
        if ($value === '') {
            return redirect()->back()->withInput()->with('error', 'Value is required.');
        }

        service('attributeValueRepository')->create([
            'attribute_id' => $attributeId,
            'value'        => $value,
            'sort_order'   => (int) $this->request->getPost('sort_order'),
        ], (int) session()->get('user_id'));

        return redirect()->to('admin/attributes/' . $attributeId . '/values')->with('success', 'Value created.');
    }

    public function edit(int $attributeId, int $id)
    {
        if ($denied = $this->guard('attribute.manage')) {
            return $denied;
        }

        $attribute = $this->requireAttribute($attributeId);
        if ($attribute instanceof RedirectResponse) {
            return $attribute;
        }

        $row = service('attributeValueRepository')->findById($id);
        if ($row === null || (int) $row['attribute_id'] !== $attributeId) {
            return redirect()->to('admin/attributes/' . $attributeId . '/values')->with('error', 'Value not found.');
        }

        return view('admin/attributes/value_form', [
            'title'     => 'Edit value · ' . $attribute['name'] . ' · Admin',
            'pageTitle' => 'Edit value — ' . $attribute['name'],
            'active'    => 'attributes',
            'userName'  => session()->get('user_name') ?: 'User',
            'attribute' => $attribute,
            'row'       => $row,
        ]);
    }

    public function update(int $attributeId, int $id): RedirectResponse
    {
        if ($denied = $this->guard('attribute.manage')) {
            return $denied;
        }

        $repo = service('attributeValueRepository');
        $row  = $repo->findById($id);
        if ($row === null || (int) $row['attribute_id'] !== $attributeId) {
            return redirect()->to('admin/attributes/' . $attributeId . '/values')->with('error', 'Value not found.');
        }

        $value = trim((string) $this->request->getPost('value'));
        if ($value === '') {
            return redirect()->back()->withInput()->with('error', 'Value is required.');
        }

        $repo->update($id, [
            'value'      => $value,
            'sort_order' => (int) $this->request->getPost('sort_order'),
        ], (int) session()->get('user_id'));

        return redirect()->to('admin/attributes/' . $attributeId . '/values')->with('success', 'Value updated.');
    }

    public function activate(int $attributeId, int $id): RedirectResponse
    {
        return $this->transition($attributeId, $id, 'active', 'Value activated.');
    }

    public function deactivate(int $attributeId, int $id): RedirectResponse
    {
        return $this->transition($attributeId, $id, 'inactive', 'Value deactivated.');
    }

    public function delete(int $attributeId, int $id): RedirectResponse
    {
        if ($denied = $this->guard('attribute.manage')) {
            return $denied;
        }

        $repo = service('attributeValueRepository');
        $row  = $repo->findById($id);
        if ($row === null || (int) $row['attribute_id'] !== $attributeId) {
            return redirect()->to('admin/attributes/' . $attributeId . '/values')->with('error', 'Value not found.');
        }

        $inUse = $repo->inUseBy($id);
        if ($inUse !== []) {
            return redirect()->to('admin/attributes/' . $attributeId . '/values')->with(
                'error',
                'Cannot delete: still in use by ' . implode(', ', $inUse) . '.',
            );
        }

        $repo->delete($id, (int) session()->get('user_id'));

        return redirect()->to('admin/attributes/' . $attributeId . '/values')->with('success', 'Value deleted.');
    }

    private function transition(int $attributeId, int $id, string $status, string $okMessage): RedirectResponse
    {
        if ($denied = $this->guard('attribute.manage')) {
            return $denied;
        }

        $repo = service('attributeValueRepository');
        $row  = $repo->findById($id);
        if ($row === null || (int) $row['attribute_id'] !== $attributeId) {
            return redirect()->to('admin/attributes/' . $attributeId . '/values')->with('error', 'Value not found.');
        }

        if ($status === 'inactive') {
            $inUse = $repo->inUseBy($id);
            if ($inUse !== []) {
                return redirect()->to('admin/attributes/' . $attributeId . '/values')->with(
                    'error',
                    'Cannot deactivate: still in use by ' . implode(', ', $inUse) . '.',
                );
            }
        }

        $repo->updateStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/attributes/' . $attributeId . '/values')->with('success', $okMessage);
    }

    /** @return array<string,mixed>|RedirectResponse */
    private function requireAttribute(int $attributeId)
    {
        $attribute = service('attributeRepository')->findById($attributeId);
        if ($attribute === null) {
            return redirect()->to('admin/attributes')->with('error', 'Attribute not found.');
        }

        return $attribute;
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
