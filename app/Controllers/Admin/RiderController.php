<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\RiderController — cross-vendor rider oversight: list every delivery rider,
 * view a rider (profile + active load + payout plan + KYC + recent payout
 * entries), suspend/activate, assign a payout plan, and verify/reject KYC docs.
 * Guarded by delivery.view (the delivery oversight permission).
 */
final class RiderController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('delivery.view')) {
            return $denied;
        }
        $status   = $this->request->getGet('status');
        $vendorId = $this->request->getGet('vendor_id');

        return view('admin/riders/index', [
            'title' => 'Riders · Admin', 'pageTitle' => 'Delivery Riders', 'active' => 'riders',
            'userName' => session()->get('user_name') ?: 'User',
            'riders'   => service('riderRepository')->adminList(is_numeric($vendorId) ? (int) $vendorId : null, is_string($status) ? $status : null),
            'vendors'  => service('shopRepository')->vendorsForSelect(),
            'vendorId' => is_numeric($vendorId) ? (int) $vendorId : null,
            'status'   => (string) ($status ?? ''),
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->guard('delivery.view')) {
            return $denied;
        }
        $rider = service('riderRepository')->adminFind($id);
        if ($rider === null) {
            return redirect()->to('admin/riders')->with('error', 'Rider not found.');
        }
        $uid = (int) $rider['user_id'];

        $tracking = service('deliveryTrackingRepository');

        return view('admin/riders/show', [
            'title' => 'Rider · ' . ($rider['name'] ?? ''), 'pageTitle' => 'Rider · ' . ($rider['name'] ?? ''), 'active' => 'riders',
            'userName' => session()->get('user_name') ?: 'User',
            'rider'    => $rider,
            'docs'     => service('riderDocumentRepository')->forRider($uid),
            'entries'  => service('riderPayoutRepository')->entriesForRider($uid, 20),
            'plans'    => service('riderPayoutPlanRepository')->options(),
            'metrics'  => $tracking->metricsForRider($uid),
            'disputes' => $tracking->disputesForRider($uid),
            'reviews'  => $tracking->reviewsForRider($uid, 8),
        ]);
    }

    public function resolveDispute(int $id, int $disputeId): RedirectResponse
    {
        if ($denied = $this->guard('delivery.view')) {
            return $denied;
        }
        $status     = (string) $this->request->getPost('status');
        $resolution = trim((string) $this->request->getPost('resolution')) ?: null;
        $ok         = service('deliveryTrackingRepository')->resolveDispute($disputeId, $status, $resolution, (int) session()->get('user_id'));

        return redirect()->to('admin/riders/' . $id)->with($ok ? 'success' : 'error', $ok ? 'Dispute updated.' : 'Could not update the dispute.');
    }

    public function setStatus(int $id): RedirectResponse
    {
        if ($denied = $this->guard('delivery.view')) {
            return $denied;
        }
        $to = (string) $this->request->getPost('status');
        $ok = service('riderRepository')->setStatus($id, $to, (int) session()->get('user_id'));

        return redirect()->to('admin/riders/' . $id)->with($ok ? 'success' : 'error', $ok ? 'Rider ' . $to . '.' : 'Could not update the rider.');
    }

    public function assignPlan(int $id): RedirectResponse
    {
        if ($denied = $this->guard('delivery.view')) {
            return $denied;
        }
        $planId = (int) $this->request->getPost('payout_plan_id');
        service('riderRepository')->assignPlan($id, $planId ?: null, (int) session()->get('user_id'));

        return redirect()->to('admin/riders/' . $id)->with('success', $planId ? 'Payout plan assigned.' : 'Payout plan cleared.');
    }

    public function verifyDoc(int $id, int $docId): RedirectResponse
    {
        return $this->docDecision($id, $docId, 'verified', 'Document verified.');
    }

    public function rejectDoc(int $id, int $docId): RedirectResponse
    {
        return $this->docDecision($id, $docId, 'rejected', 'Document rejected.');
    }

    private function docDecision(int $id, int $docId, string $status, string $okMessage): RedirectResponse
    {
        if ($denied = $this->guard('delivery.view')) {
            return $denied;
        }
        $rider = service('riderRepository')->adminFind($id);
        if ($rider === null) {
            return redirect()->to('admin/riders')->with('error', 'Rider not found.');
        }
        $note = trim((string) $this->request->getPost('note')) ?: null;
        $ok   = service('riderDocumentRepository')->setStatus($docId, (int) $rider['user_id'], $status, $note, (int) session()->get('user_id'));

        return redirect()->to('admin/riders/' . $id)->with($ok ? 'success' : 'error', $ok ? $okMessage : 'Document not found.');
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
