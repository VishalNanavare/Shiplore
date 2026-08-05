<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\ReturnController — Phase X4: the previously-missing returns pipeline
 * (requested → approved → pickup → QC → refund_approved → refunded →
 * restocked / write_off). Refund money + commission cancellation + CN remain
 * with the refunds module — this controls and exposes the RMA workflow.
 */
final class ReturnController extends BaseController
{
    public function index()
    {
        if ($r = $this->guard('return.view')) {
            return $r;
        }
        $repo   = service('returnRepository');
        $status = $this->request->getGet('status') ?: null;

        return view('admin/returns/index', [
            'title'   => 'Returns',
            'returns' => $repo->listForAdmin($status),
            'counts'  => $repo->countsByStatus(),
            'status'  => (string) ($status ?? ''),
            'allowed' => \App\Models\ReturnRepository::ALLOWED,
        ]);
    }

    public function show(int $id)
    {
        if ($r = $this->guard('return.view')) {
            return $r;
        }
        $ret = service('returnRepository')->detail($id);
        if ($ret === null) {
            return redirect()->to('admin/returns')->with('error', 'Return not found.');
        }

        return view('admin/returns/show', [
            'title' => 'Return #' . $id,
            'ret'   => $ret,
            'next'  => \App\Models\ReturnRepository::ALLOWED[$ret['status']] ?? [],
        ]);
    }

    public function transition(int $id): RedirectResponse
    {
        if ($r = $this->guard('return.manage')) {
            return $r;
        }
        $to  = (string) $this->request->getPost('to');
        $res = service('returnRepository')->transition($id, $to, (int) session()->get('user_id'));
        $back = $this->request->getPost('return') === 'show' ? 'admin/returns/' . $id : 'admin/returns';

        return redirect()->to($back)->with(
            ($res['ok'] ?? false) ? 'success' : 'error',
            ($res['ok'] ?? false) ? 'Return moved to ' . str_replace('_', ' ', $to) . '.' : ($res['reason'] ?? 'Could not update the return.'),
        );
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
