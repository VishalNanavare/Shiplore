<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\PayoutController — batches approved settlements into vendor payouts and
 * tracks them. Viewing needs `settlement.view`; running/marking needs
 * `settlement.run`. No live gateway — payouts are marked paid/failed manually.
 */
final class PayoutController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('settlement.view')) {
            return $denied;
        }
        $repo = service('payoutRepository');

        return view('admin/payouts/index', [
            'title'     => 'Payouts · Admin',
            'pageTitle' => 'Vendor Payouts',
            'active'    => 'settlements',
            'userName'  => session()->get('user_name') ?: 'User',
            'batches'   => $repo->listBatches(),
            'eligible'  => $repo->eligibleSettlements(),
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->guard('settlement.view')) {
            return $denied;
        }
        $repo  = service('payoutRepository');
        $batch = $repo->batch($id);
        if ($batch === null) {
            return redirect()->to('admin/payouts')->with('error', 'Payout batch not found.');
        }

        return view('admin/payouts/show', [
            'title'     => 'Payout batch #' . $id,
            'pageTitle' => 'Payout batch #' . $id,
            'active'    => 'settlements',
            'userName'  => session()->get('user_name') ?: 'User',
            'batch'     => $batch,
            'payouts'   => $repo->payoutsForBatch($id),
        ]);
    }

    public function run(): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        $res = service('payoutRepository')->createBatch((int) session()->get('user_id'));
        if (! ($res['ok'] ?? false)) {
            return redirect()->to('admin/payouts')->with('error', $res['reason'] ?? 'Could not create the batch.');
        }

        return redirect()->to('admin/payouts/' . $res['batch_id'])
            ->with('success', 'Payout batch created: ' . $res['count'] . ' vendor(s), ₹' . number_format($res['total'], 2) . '.');
    }

    public function markPaid(int $id): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        $ok = service('payoutRepository')->markBatchPaid($id, (int) session()->get('user_id'));

        return redirect()->to('admin/payouts/' . $id)->with($ok ? 'success' : 'error',
            $ok ? 'Batch marked paid — its settlements are now settled.' : 'Could not mark this batch paid.');
    }

    public function markFailed(int $id): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        $ok = service('payoutRepository')->markBatchFailed($id, (int) session()->get('user_id'));

        return redirect()->to('admin/payouts/' . $id)->with($ok ? 'success' : 'error', $ok ? 'Batch marked failed.' : 'Could not update the batch.');
    }

    /** Capture a vendor's payout bank account (so its settlements can be paid). */
    public function addBankAccount(): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        $ok = service('vendorBankAccountRepository')->add(
            (int) $this->request->getPost('vendor_id'),
            trim((string) $this->request->getPost('holder_name')),
            (string) $this->request->getPost('account_no'),
            (string) $this->request->getPost('ifsc'),
            (int) session()->get('user_id'),
        );

        return redirect()->to('admin/payouts')->with($ok ? 'success' : 'error',
            $ok ? 'Bank account saved — this vendor can now be paid out.' : 'Please provide holder name, account number and IFSC.');
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
