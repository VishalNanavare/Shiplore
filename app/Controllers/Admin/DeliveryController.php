<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/** Admin\DeliveryController — delivery monitoring + manual status control. */
final class DeliveryController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('delivery.view')) {
            return $denied;
        }
        $status = $this->request->getGet('status');

        return view('admin/deliveries/index', [
            'title'      => 'Delivery Monitoring · Admin',
            'pageTitle'  => 'Delivery Monitoring',
            'active'     => 'deliveries',
            'userName'   => session()->get('user_name') ?: 'User',
            'deliveries' => service('deliveryRepository')->list(is_string($status) ? $status : null),
            'status'     => (string) ($status ?? ''),
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->guard('delivery.view')) {
            return $denied;
        }
        $delivery = service('deliveryRepository')->detail($id);
        if ($delivery === null) {
            return redirect()->to('admin/deliveries')->with('error', 'Delivery not found.');
        }

        return view('admin/deliveries/show', [
            'title'     => 'Delivery #' . $id,
            'pageTitle' => 'Delivery #' . $id,
            'active'    => 'deliveries',
            'userName'  => session()->get('user_name') ?: 'User',
            'delivery'  => $delivery,
            'next'      => \App\Libraries\Workflow\StatusMachine::allowedNextDelivery((string) $delivery['status']),
        ]);
    }

    public function updateStatus(int $id): RedirectResponse
    {
        // No separate delivery.manage permission exists; delivery.view gates the panel.
        if ($denied = $this->guard('delivery.view')) {
            return $denied;
        }
        $to = (string) $this->request->getPost('status');
        $ok = service('deliveryRepository')->updateStatus($id, $to, (int) session()->get('user_id'));

        return redirect()->to('admin/deliveries/' . $id)->with($ok ? 'success' : 'error',
            $ok ? 'Delivery moved to ' . str_replace('_', ' ', $to) . '.' : 'That status change is not allowed.');
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
