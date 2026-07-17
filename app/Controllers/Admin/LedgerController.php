<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;

/** Admin\LedgerController — double-entry ledger view. ledger.view. */
final class LedgerController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('ledger.view')) {
            return $denied;
        }
        $entries = Database::connect()->table('ledger_entries le')
            ->select('le.txn_group_uuid, le.direction, le.amount, le.ref_type, le.ref_id, le.memo, le.created_at, la.code AS account')
            ->join('ledger_accounts la', 'la.id = le.ledger_account_id', 'left')
            ->orderBy('le.id', 'DESC')->limit(200)->get()->getResultArray();

        $totDebit  = array_sum(array_map(static fn ($e) => $e['direction'] === 'debit' ? (float) $e['amount'] : 0, $entries));
        $totCredit = array_sum(array_map(static fn ($e) => $e['direction'] === 'credit' ? (float) $e['amount'] : 0, $entries));

        return view('admin/ledger/index', [
            'title' => 'Ledger · Admin', 'pageTitle' => 'Ledger', 'active' => 'ledger',
            'userName' => session()->get('user_name') ?: 'User',
            'entries' => $entries, 'totDebit' => $totDebit, 'totCredit' => $totCredit,
        ]);
    }

    /** Trial balance: per-account debit/credit totals over the chart of accounts. */
    public function trialBalance()
    {
        if ($denied = $this->guard('ledger.view')) {
            return $denied;
        }
        $rows = Database::connect()->table('ledger_accounts a')
            ->select('a.id, a.code, a.name, a.type, a.parent_id, '
                . "COALESCE(SUM(CASE WHEN e.direction='debit' THEN e.amount ELSE 0 END),0) AS debit, "
                . "COALESCE(SUM(CASE WHEN e.direction='credit' THEN e.amount ELSE 0 END),0) AS credit", false)
            ->join('ledger_entries e', 'e.ledger_account_id = a.id', 'left')
            ->where('a.deleted_at', null)
            ->groupBy('a.id')->orderBy('a.code', 'ASC')->get()->getResultArray();

        $totDebit  = array_sum(array_map(static fn ($r) => (float) $r['debit'], $rows));
        $totCredit = array_sum(array_map(static fn ($r) => (float) $r['credit'], $rows));

        return view('admin/ledger/trial-balance', [
            'title' => 'Trial Balance · Admin', 'pageTitle' => 'Trial Balance', 'active' => 'ledger',
            'userName' => session()->get('user_name') ?: 'User',
            'rows' => $rows, 'totDebit' => $totDebit, 'totCredit' => $totCredit,
        ]);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
