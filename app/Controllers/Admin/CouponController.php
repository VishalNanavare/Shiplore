<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Controllers\Admin\Concerns\MasterCrud;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;

/** Admin\CouponController — coupon oversight + CRUD. coupon.manage. */
final class CouponController extends BaseController
{
    use MasterCrud;

    public function index()
    {
        if ($denied = $this->guard('coupon.manage')) {
            return $denied;
        }

        return view('admin/coupons/index', [
            'title'     => 'Coupons · Admin',
            'pageTitle' => 'Coupons',
            'active'    => 'coupons',
            'userName'  => session()->get('user_name') ?: 'User',
            'coupons'   => service('couponRepository')->list(),
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->guard('coupon.manage')) {
            return $denied;
        }
        $repo   = service('couponRepository');
        $coupon = $repo->detail($id);
        if ($coupon === null) {
            return redirect()->to('admin/coupons')->with('error', 'Coupon not found.');
        }

        return view('admin/coupons/show', [
            'title'       => 'Coupon ' . ($coupon['code'] ?? ''),
            'pageTitle'   => 'Coupon ' . ($coupon['code'] ?? ''),
            'active'      => 'coupons',
            'userName'    => session()->get('user_name') ?: 'User',
            'coupon'      => $coupon,
            'redemptions' => $repo->redemptions($id),
            'stats'       => $repo->usageStats($id),
        ]);
    }

    /** @return array<string,mixed> */
    protected function masterSpec(): array
    {
        $promos = [];
        foreach (Database::connect()->table('promotions')->select('id, name')->where('deleted_at', null)->orderBy('name')->get()->getResultArray() as $p) {
            $promos[$p['id']] = $p['name'];
        }

        return [
            'table' => 'coupons', 'slug' => 'coupons', 'label' => 'Coupon',
            'permCreate' => 'coupon.manage', 'permUpdate' => 'coupon.manage',
            'fields' => [
                ['promotion_id', 'Promotion', 'select', true, $promos],
                ['code', 'Code', 'text', true],
                ['usage_limit', 'Total usage limit', 'number', false],
                ['per_user_limit', 'Per-user limit', 'number', false],
                ['valid_from', 'Valid from', 'date', true],
                ['valid_to', 'Valid to', 'date', false],
            ],
        ];
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('settingsRepository')->moduleEnabled('coupons')) {
            return redirect()->to('admin/dashboard')->with('error', 'Coupons are turned off in System Settings.');
        }
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
