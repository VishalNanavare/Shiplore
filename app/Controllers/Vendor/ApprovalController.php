<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Vendor\ApprovalController — the vendor approval inbox (X3): every change
 * request raised by the vendor's staff lands here for the L1 decision, plus
 * a "my requests" view for the requesters themselves.
 *
 * Decisions go to the engine, which enforces G1 (no self-approval), records
 * the level decision, audits, notifies, and applies on final approval.
 */
final class ApprovalController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->canDecide()) {
            return redirect()->to('vendor/dashboard')->with('error', 'You do not have permission to review requests.');
        }

        $status = $this->request->getGet('status') ?: null;

        return $this->render('vendor/approvals/index', 'approvals', 'Approvals', [
            'requests' => service('changeRequestRepository')->pendingForVendor((int) $this->vendorId(), $status),
            'status'   => (string) ($status ?? ''),
        ]);
    }

    public function decide(int $id): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->canDecide()) {
            return redirect()->to('vendor/dashboard')->with('error', 'You do not have permission to review requests.');
        }

        $decision = (string) $this->request->getPost('decision');
        $reason   = trim((string) $this->request->getPost('reason')) ?: null;

        // G5 delegation: authority (owner or request.approve.vendor) maps to the
        // vendor level; G1 in the engine still blocks deciding your own request.
        $r = service('changeRequestEngine')->decide($id, [
            'user_id'   => (int) session()->get('user_id'),
            'role'      => 'vendor',
            'vendor_id' => $this->vendorId(),
            'ip'        => $this->request->getIPAddress(),
        ], $decision, $reason);

        if (! ($r['ok'] ?? false)) {
            return redirect()->to('vendor/approvals')->with('error', match ($r['error'] ?? '') {
                'self_approval_forbidden' => 'You cannot decide your own request.',
                'reason_required'         => 'A reason is required to reject or request changes.',
                'wrong_approver_role', 'wrong_vendor' => 'This request is not awaiting your approval.',
                'not_pending'             => 'This request is no longer pending.',
                default                   => 'Could not record the decision.',
            });
        }

        return redirect()->to('vendor/approvals')->with('success', 'Decision recorded — request is now ' . str_replace('_', ' ', (string) $r['status']) . '.');
    }

    /** The requester's own submissions. */
    public function mine()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        return $this->render('vendor/approvals/mine', 'requests', 'My Requests', [
            'requests' => service('changeRequestRepository')->listForRequester((int) session()->get('user_id')),
        ]);
    }

    private function canDecide(): bool
    {
        return $this->isOwner() || $this->can('request.approve.vendor');
    }
}
