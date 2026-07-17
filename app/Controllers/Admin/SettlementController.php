<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\SettlementController — vendor settlement oversight. View needs
 * `settlement.view`; approving / marking paid needs `settlement.run`. POSTs
 * CSRF-protected at the route.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md §7
 */
final class SettlementController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('settlement.view')) {
            return $denied;
        }

        $status = $this->request->getGet('status');

        return view('admin/settlements/index', [
            'title'       => 'Settlements · Admin',
            'pageTitle'   => 'Settlements',
            'active'      => 'settlements',
            'userName'    => session()->get('user_name') ?: 'User',
            'settlements' => service('settlementRepository')->list(is_string($status) ? $status : null),
            'vendors'     => service('shopRepository')->vendorsForSelect(),
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->guard('settlement.view')) {
            return $denied;
        }

        $repo       = service('settlementRepository');
        $settlement = $repo->findById($id);
        if ($settlement === null) {
            return redirect()->to('admin/settlements')->with('error', 'Settlement not found.');
        }

        return view('admin/settlements/show', [
            'title'       => 'Settlement · Admin',
            'pageTitle'   => 'Settlement · ' . ($settlement['vendor'] ?? ''),
            'active'      => 'settlements',
            'userName'    => session()->get('user_name') ?: 'User',
            'settlement'  => $settlement,
            'lines'       => $repo->lines($id),
            'adjustments' => service('settlementAdjustmentRepository')->forSettlement($id),
        ]);
    }

    /** Add a penalty / bonus / dispute-hold / manual adjustment and recompute net. */
    public function addAdjustment(int $id): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        $settlement = service('settlementRepository')->findById($id);
        if ($settlement === null) {
            return redirect()->to('admin/settlements')->with('error', 'Settlement not found.');
        }
        if (in_array($settlement['status'], ['paid'], true)) {
            return redirect()->to('admin/settlements/' . $id)->with('error', 'A paid settlement can no longer be adjusted.');
        }
        $type   = (string) $this->request->getPost('type');
        $amount = (float) $this->request->getPost('amount');
        $reason = trim((string) $this->request->getPost('reason'));
        if ($amount == 0.0 || $reason === '') {
            return redirect()->to('admin/settlements/' . $id)->with('error', 'Enter a non-zero amount and a reason.');
        }
        service('settlementAdjustmentRepository')->add($id, $type, $amount, $reason, (int) session()->get('user_id'));

        return redirect()->to('admin/settlements/' . $id)->with('success', 'Adjustment applied — net payable recalculated.');
    }

    /** Apply TCS/TDS at admin-set rates (computed on gross) and recompute net. */
    public function applyTaxes(int $id): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        $s = service('settlementRepository')->findById($id);
        if ($s === null) {
            return redirect()->to('admin/settlements')->with('error', 'Settlement not found.');
        }
        if ($s['status'] === 'paid') {
            return redirect()->to('admin/settlements/' . $id)->with('error', 'A paid settlement can no longer be changed.');
        }
        $tcsRate = max(0.0, min(20.0, (float) $this->request->getPost('tcs_rate')));
        $tdsRate = max(0.0, min(20.0, (float) $this->request->getPost('tds_rate')));
        $gross   = (float) $s['gross'];
        $newTcs  = round($gross * $tcsRate / 100, 2);
        $newTds  = round($gross * $tdsRate / 100, 2);
        // Incremental: subtract only the CHANGE in tax from the existing net,
        // preserving the original settlement-run net.
        $delta = ($newTcs + $newTds) - ((float) $s['tcs'] + (float) $s['tds']);
        \Config\Database::connect()->table('settlements')->where('id', $id)
            ->set('tcs', (string) $newTcs)->set('tds', (string) $newTds)
            ->set('net_payable', 'net_payable - ' . round($delta, 2), false)
            ->set('updated_by', session()->get('user_id'))->update();

        return redirect()->to('admin/settlements/' . $id)->with('success', "TCS {$tcsRate}% / TDS {$tdsRate}% applied — net payable reduced by ₹" . number_format($delta, 2) . '.');
    }

    public function reverseAdjustment(int $id, int $adjId): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        service('settlementAdjustmentRepository')->reverse($adjId, $id, (int) session()->get('user_id'));

        return redirect()->to('admin/settlements/' . $id)->with('success', 'Adjustment reversed — net payable recalculated.');
    }

    /** Run a settlement batch for a vendor + period. */
    public function run(): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        $vendorId = (int) $this->request->getPost('vendor_id');
        $start    = trim((string) $this->request->getPost('period_start'));
        $end      = trim((string) $this->request->getPost('period_end'));
        if ($vendorId <= 0 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            return redirect()->to('admin/settlements')->with('error', 'Vendor and a valid period (YYYY-MM-DD) are required.');
        }
        $res = service('settlementService')->run($vendorId, $start, $end, (int) session()->get('user_id'));
        if (! $res['ok']) {
            return redirect()->to('admin/settlements')->with('error', $res['reason'] ?? 'Settlement failed.');
        }

        return redirect()->to('admin/settlements/' . $res['settlement_id'])
            ->with('success', ($res['replay'] ?? false) ? 'Settlement already existed for this period.' : "Settled net ₹{$res['net']} across {$res['lines']} order(s).");
    }

    public function approve(int $id): RedirectResponse
    {
        return $this->transition($id, 'approved', 'Settlement approved.');
    }

    public function markPaid(int $id): RedirectResponse
    {
        return $this->transition($id, 'paid', 'Settlement marked paid.');
    }

    private function transition(int $id, string $status, string $okMessage): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }

        $repo = service('settlementRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/settlements')->with('error', 'Settlement not found.');
        }

        $repo->updateStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/settlements/' . $id)->with('success', $okMessage);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
