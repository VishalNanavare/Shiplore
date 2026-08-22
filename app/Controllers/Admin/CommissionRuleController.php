<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\CommissionRuleController — CRUD for commission_rules (product/category/
 * business_type-scoped commission rows with GMV tiers and priority). No in-use guard
 * on delete, unlike attributes: a rule going away just means the next resolution step
 * applies; there's no "still referenced by a live product" state to protect.
 */
final class CommissionRuleController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('commission_rule.view')) {
            return $denied;
        }

        return view('admin/commission_rules/index', [
            'title' => 'Commission Rules · Admin', 'pageTitle' => 'Commission Rules',
            'active' => 'commission', 'userName' => session()->get('user_name') ?: 'User',
            'rules' => service('commissionRuleRepository')->listRules(),
        ]);
    }

    public function new()
    {
        if ($denied = $this->guard('commission_rule.manage')) {
            return $denied;
        }

        return view('admin/commission_rules/form', [
            'title' => 'New Commission Rule · Admin', 'pageTitle' => 'New Commission Rule',
            'active' => 'commission', 'userName' => session()->get('user_name') ?: 'User', 'row' => null,
        ]);
    }

    public function edit(int $id)
    {
        if ($denied = $this->guard('commission_rule.manage')) {
            return $denied;
        }
        $row = service('commissionRuleRepository')->findRule($id);
        if ($row === null) {
            return redirect()->to('admin/commission-rules')->with('error', 'Rule not found.');
        }

        return view('admin/commission_rules/form', [
            'title' => 'Edit Commission Rule · Admin', 'pageTitle' => 'Edit Commission Rule',
            'active' => 'commission', 'userName' => session()->get('user_name') ?: 'User', 'row' => $row,
        ]);
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->guard('commission_rule.manage')) {
            return $denied;
        }
        [$data, $err] = $this->collect();
        if ($err !== '') {
            return redirect()->back()->withInput()->with('error', $err);
        }
        service('commissionRuleRepository')->createRule($data, (int) session()->get('user_id'));

        return redirect()->to('admin/commission-rules')->with('success', 'Commission rule created.');
    }

    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->guard('commission_rule.manage')) {
            return $denied;
        }
        $repo = service('commissionRuleRepository');
        if ($repo->findRule($id) === null) {
            return redirect()->to('admin/commission-rules')->with('error', 'Rule not found.');
        }
        [$data, $err] = $this->collect();
        if ($err !== '') {
            return redirect()->back()->withInput()->with('error', $err);
        }
        $repo->updateRule($id, $data, (int) session()->get('user_id'));

        return redirect()->to('admin/commission-rules')->with('success', 'Commission rule updated.');
    }

    public function delete(int $id): RedirectResponse
    {
        if ($denied = $this->guard('commission_rule.manage')) {
            return $denied;
        }
        service('commissionRuleRepository')->deleteRule($id, (int) session()->get('user_id'));

        return redirect()->to('admin/commission-rules')->with('success', 'Commission rule deleted.');
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function collect(): array
    {
        $planId = (int) $this->request->getPost('commission_plan_id');
        if ($planId <= 0) {
            return [[], 'Commission plan is required.'];
        }
        $type = (string) $this->request->getPost('commission_type');
        if (! in_array($type, ['percentage', 'fixed'], true)) {
            return [[], 'Commission type must be percentage or fixed.'];
        }

        $data = [
            'commission_plan_id' => $planId,
            'category_id'        => $this->nullableInt('category_id'),
            'product_id'         => $this->nullableInt('product_id'),
            'business_type_id'   => $this->nullableInt('business_type_id'),
            'commission_type'    => $type,
            'rate'               => $type === 'percentage' ? (float) $this->request->getPost('rate') : 0,
            'fixed_amount'       => $type === 'fixed' ? (float) $this->request->getPost('fixed_amount') : null,
            'min_gmv'            => $this->nullableFloat('min_gmv'),
            'max_gmv'            => $this->nullableFloat('max_gmv'),
            'priority'           => (int) ($this->request->getPost('priority') ?: 0),
        ];

        if ($data['category_id'] === null && $data['product_id'] === null && $data['business_type_id'] === null) {
            return [[], 'Pick a scope: category, product, or business type.'];
        }

        return [$data, ''];
    }

    private function nullableInt(string $key): ?int
    {
        $v = $this->request->getPost($key);

        return $v === null || $v === '' ? null : (int) $v;
    }

    private function nullableFloat(string $key): ?float
    {
        $v = $this->request->getPost($key);

        return $v === null || $v === '' ? null : (float) $v;
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
