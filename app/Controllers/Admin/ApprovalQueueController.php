<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\ApprovalQueueController — the unified platform approval queue (X3):
 * every change request that reached the admin level (L2), SLA-sorted, plus
 * an all-states browser for governance oversight.
 */
final class ApprovalQueueController extends BaseController
{
    public function index()
    {
        if ($r = $this->guard('request.approve.admin')) {
            return $r;
        }
        $status = $this->request->getGet('status') ?: null;

        return view('admin/approvals/index', [
            'title'    => 'Approval Queue',
            'requests' => service('changeRequestRepository')->pendingForAdmin($status),
            'status'   => (string) ($status ?? ''),
        ]);
    }

    public function decide(int $id): RedirectResponse
    {
        if ($r = $this->guard('request.approve.admin')) {
            return $r;
        }
        $decision = (string) $this->request->getPost('decision');
        $reason   = trim((string) $this->request->getPost('reason')) ?: null;

        $res = service('changeRequestEngine')->decide($id, [
            'user_id' => (int) session()->get('user_id'),
            'role'    => 'admin',
            'ip'      => $this->request->getIPAddress(),
        ], $decision, $reason);

        if (! ($res['ok'] ?? false)) {
            return redirect()->to('admin/approvals')->with('error', match ($res['error'] ?? '') {
                'self_approval_forbidden' => 'You cannot decide your own request.',
                'reason_required'         => 'A reason is required to reject or request changes.',
                'not_pending'             => 'This request is no longer pending.',
                'wrong_approver_role'     => 'This request is awaiting the vendor level, not admin.',
                default                   => 'Could not record the decision.',
            });
        }

        return redirect()->to('admin/approvals')->with('success', 'Decision recorded — request is now ' . str_replace('_', ' ', (string) $res['status']) . '.');
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
