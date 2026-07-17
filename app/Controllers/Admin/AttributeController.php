<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Controllers\Admin\Concerns\MasterCrud;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\AttributeController — product-attribute governance + CRUD. View needs
 * `attribute.view`; create/edit/activate needs `attribute.manage`. POSTs
 * CSRF-protected at the route.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md
 */
final class AttributeController extends BaseController
{
    use MasterCrud;

    /** @return array<string,mixed> */
    protected function masterSpec(): array
    {
        return [
            'table' => 'attributes', 'slug' => 'attributes', 'label' => 'Attribute',
            'permCreate' => 'attribute.manage', 'permUpdate' => 'attribute.manage',
            'fields' => [
                ['code', 'Code', 'text', true],
                ['name', 'Name', 'text', true],
                ['type', 'Type', 'select', true, ['select' => 'Select', 'multiselect' => 'Multi-select', 'text' => 'Text', 'number' => 'Number', 'boolean' => 'Boolean']],
                ['is_variant_defining', 'Variant-defining', 'checkbox', false],
            ],
        ];
    }

    public function index()
    {
        if ($denied = $this->guard('attribute.view')) {
            return $denied;
        }

        $status = $this->request->getGet('status');

        return view('admin/attributes/index', [
            'title'      => 'Attributes · Admin',
            'pageTitle'  => 'Attributes',
            'active'     => 'attributes',
            'userName'   => session()->get('user_name') ?: 'User',
            'attributes' => service('attributeRepository')->list(is_string($status) ? $status : null),
        ]);
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->transition($id, 'active', 'Attribute activated.');
    }

    public function deactivate(int $id): RedirectResponse
    {
        return $this->transition($id, 'inactive', 'Attribute deactivated.');
    }

    private function transition(int $id, string $status, string $okMessage): RedirectResponse
    {
        if ($denied = $this->guard('attribute.manage')) {
            return $denied;
        }

        $repo = service('attributeRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/attributes')->with('error', 'Attribute not found.');
        }

        $repo->updateStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/attributes')->with('success', $okMessage);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
