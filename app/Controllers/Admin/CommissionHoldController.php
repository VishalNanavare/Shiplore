<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/**
 * Admin\CommissionHoldController — Phase X4: the commission hold queue.
 * Shows what is inside its return window (on_hold), what returns took back
 * (cancelled/reversed), and the platform totals per state (doc 44 §8 C6).
 */
final class CommissionHoldController extends BaseController
{
    public function index()
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'commission.hold.view')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }
        $status = (string) ($this->request->getGet('status') ?: 'on_hold');
        $repo   = service('commissionLedgerRepository');

        return view('admin/commission-holds/index', [
            'title'  => 'Commission Holds',
            'rows'   => $repo->holdQueue(null, $status),
            'totals' => $repo->totalsByStatus(),
            'status' => $status,
        ]);
    }
}
