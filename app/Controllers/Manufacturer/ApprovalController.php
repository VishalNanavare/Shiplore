<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Manufacturer\ApprovalController — the approvals inbox.
 *
 * Change requests raised by a manufacturer's own staff land here for the owner's
 * decision, alongside a "my requests" view for the people who raised them. Without
 * this screen there was nowhere for such a request to go, which is exactly why
 * StaffController shipped owner-only rather than with a request path — building the
 * request half with no approve half would have stranded them.
 *
 * The change-request engine and repository are reused unchanged. They are genuinely
 * tenant-agnostic: change_requests.vendor_id holds whatever the submitter passed, and
 * a manufacturer IS a `vendors` row. Unlike the account/product/unit repositories
 * there is no party_type gate inside them to lose by sharing.
 *
 * ONE THING THAT READS ODDLY AND IS CORRECT: decisions are made with role 'vendor',
 * not 'manufacturer'. 'vendor' is the engine's name for the TENANT-OWNER decision
 * level (ChangeRequestEngine::ROLE_FOR_LEVEL maps 'vendor' => 'vendor'), and the level
 * check it performs is that the decider's vendor_id equals the request's — which is
 * the manufacturer's own id. Passing 'manufacturer' would match no level and every
 * decision would fail with wrong_approver_role.
 *
 * @see \App\Controllers\Vendor\ApprovalController — the vendor counterpart
 */
final class ApprovalController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if (! $this->canDecide()) {
            return redirect()->to('manufacturer/dashboard')->with('error', 'You do not have permission to review requests.');
        }

        $status = $this->request->getGet('status') ?: null;

        return $this->render('manufacturer/approvals/index', 'approvals', 'Approvals', [
            'requests' => service('changeRequestRepository')->pendingForVendor((int) $this->manufacturerId(), $status),
            'status'   => (string) ($status ?? ''),
        ]);
    }

    public function decide(int $id): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if (! $this->canDecide()) {
            return redirect()->to('manufacturer/dashboard')->with('error', 'You do not have permission to review requests.');
        }

        $decision = (string) $this->request->getPost('decision');
        $reason   = trim((string) $this->request->getPost('reason')) ?: null;

        $r = service('changeRequestEngine')->decide($id, [
            'user_id'   => (int) session()->get('user_id'),
            'role'      => 'vendor',   // the engine's tenant-owner level — see the class docblock
            'vendor_id' => $this->manufacturerId(),
            'ip'        => $this->request->getIPAddress(),
        ], $decision, $reason);

        if (! ($r['ok'] ?? false)) {
            return redirect()->to('manufacturer/approvals')->with('error', match ($r['error'] ?? '') {
                'self_approval_forbidden'             => 'You cannot decide your own request.',
                'reason_required'                     => 'A reason is required to reject or request changes.',
                'wrong_approver_role', 'wrong_vendor' => 'This request is not awaiting your approval.',
                'not_pending'                         => 'This request is no longer pending.',
                'not_found'                           => 'Request not found.',
                default                               => 'Could not record the decision.',
            });
        }

        return redirect()->to('manufacturer/approvals')
            ->with('success', 'Decision recorded — request is now ' . str_replace('_', ' ', (string) $r['status']) . '.');
    }

    /** The requester's own submissions. Not gated: everyone may see what they asked for. */
    public function mine()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }

        return $this->render('manufacturer/approvals/mine', 'requests', 'My Requests', [
            'requests' => service('changeRequestRepository')->listForRequester((int) session()->get('user_id')),
        ]);
    }

    /**
     * Who may decide. The owner always can; a manager needs the explicit permission.
     * The engine still blocks deciding your own request (G1) regardless.
     */
    private function canDecide(): bool
    {
        return $this->isOwner() || $this->can('mfg.request.approve');
    }
}
