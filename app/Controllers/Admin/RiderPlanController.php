<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\RiderPayoutPlanRepository;
use CodeIgniter\HTTP\RedirectResponse;

/** Admin\RiderPlanController — CRUD for rider payout plans (the 5 models). delivery.view. */
final class RiderPlanController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        return view('admin/riders/plans', [
            'title' => 'Rider Payout Plans · Admin', 'pageTitle' => 'Rider Payout Plans', 'active' => 'riders',
            'userName' => session()->get('user_name') ?: 'User',
            'plans'    => service('riderPayoutPlanRepository')->list(),
            'vendors'  => service('shopRepository')->vendorsForSelect(),
            'models'   => RiderPayoutPlanRepository::MODELS,
            'edit'     => null,
        ]);
    }

    public function edit(int $id)
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $plan = service('riderPayoutPlanRepository')->find($id);
        if ($plan === null) {
            return redirect()->to('admin/rider-plans')->with('error', 'Plan not found.');
        }

        return view('admin/riders/plans', [
            'title' => 'Edit Plan · Admin', 'pageTitle' => 'Edit Rider Payout Plan', 'active' => 'riders',
            'userName' => session()->get('user_name') ?: 'User',
            'plans'    => service('riderPayoutPlanRepository')->list(),
            'vendors'  => service('shopRepository')->vendorsForSelect(),
            'models'   => RiderPayoutPlanRepository::MODELS,
            'edit'     => $plan,
        ]);
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        if (trim((string) $this->request->getPost('name')) === '') {
            return redirect()->to('admin/rider-plans')->with('error', 'Plan name is required.');
        }
        service('riderPayoutPlanRepository')->create((array) $this->request->getPost(), (int) session()->get('user_id'));

        return redirect()->to('admin/rider-plans')->with('success', 'Payout plan created.');
    }

    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        service('riderPayoutPlanRepository')->update($id, (array) $this->request->getPost(), (int) session()->get('user_id'));

        return redirect()->to('admin/rider-plans')->with('success', 'Payout plan updated.');
    }

    public function toggle(int $id): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        service('riderPayoutPlanRepository')->toggle($id);

        return redirect()->to('admin/rider-plans')->with('success', 'Plan status changed.');
    }

    private function guard(): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'delivery.view')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
