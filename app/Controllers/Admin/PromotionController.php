<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Controllers\Admin\Concerns\MasterCrud;
use CodeIgniter\HTTP\RedirectResponse;

/** Admin\PromotionController — promotion oversight + CRUD. promotion.view / promotion.manage. */
final class PromotionController extends BaseController
{
    use MasterCrud;

    /** @return array<string,mixed> */
    protected function masterSpec(): array
    {
        return [
            'table' => 'promotions', 'slug' => 'promotions', 'label' => 'Promotion',
            'permCreate' => 'promotion.manage', 'permUpdate' => 'promotion.manage',
            'hasUuid' => true,
            'fields' => [
                ['name', 'Name', 'text', true],
                ['type', 'Type', 'select', true, ['percentage' => 'Percentage', 'flat' => 'Flat', 'bogo' => 'BOGO', 'bundle' => 'Bundle', 'tiered' => 'Tiered', 'free_shipping' => 'Free shipping']],
                ['value', 'Value', 'number', false],
                ['valid_from', 'Valid from', 'date', true],
                ['valid_to', 'Valid to', 'date', false],
            ],
        ];
    }

    public function index()
    {
        if ($denied = $this->guard('promotion.view')) {
            return $denied;
        }

        return view('admin/promotions/index', [
            'title'      => 'Promotions · Admin',
            'pageTitle'  => 'Promotions',
            'active'     => 'promotions',
            'userName'   => session()->get('user_name') ?: 'User',
            'promotions' => service('promotionRepository')->list($this->statusFilter()),
        ]);
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->transition($id, 'active', 'Promotion activated.');
    }

    public function pause(int $id): RedirectResponse
    {
        return $this->transition($id, 'paused', 'Promotion paused.');
    }

    private function transition(int $id, string $status, string $msg): RedirectResponse
    {
        if ($denied = $this->guard('promotion.manage')) {
            return $denied;
        }
        $repo = service('promotionRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/promotions')->with('error', 'Promotion not found.');
        }
        $repo->updateStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/promotions')->with('success', $msg);
    }

    private function statusFilter(): ?string
    {
        $s = $this->request->getGet('status');

        return is_string($s) ? $s : null;
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('settingsRepository')->moduleEnabled('promotions')) {
            return redirect()->to('admin/dashboard')->with('error', 'Promotions are turned off in System Settings.');
        }
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
