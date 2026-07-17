<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin\RiderFinanceController — rider money: COD cash reconciliation (cash a
 * rider collected vs deposited) and rider earnings payout batches, plus a
 * per-rider statement. Reads need `settlement.view`; mutations need
 * `settlement.run` (the same finance permissions as vendor payouts).
 */
final class RiderFinanceController extends BaseController
{
    // ---- COD reconciliation ----

    public function cod()
    {
        if ($denied = $this->guard('settlement.view')) {
            return $denied;
        }
        $cod      = service('codCollectionRepository');
        $riderId  = $this->request->getGet('rider');
        $riderId  = is_numeric($riderId) ? (int) $riderId : null;

        return view('admin/rider_finance/cod', [
            'title' => 'COD Reconciliation', 'pageTitle' => 'COD Reconciliation', 'active' => 'rider-finance',
            'userName' => session()->get('user_name') ?: 'User',
            'summary'  => $cod->riderSummary(),
            'riderId'  => $riderId,
            'lines'    => $riderId !== null ? $cod->forRider($riderId) : [],
        ]);
    }

    public function codDeposit(int $id): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        $ok = service('codCollectionRepository')->markDeposited($id, (int) session()->get('user_id'));

        return redirect()->back()->with($ok ? 'success' : 'error', $ok ? 'Marked deposited.' : 'Could not update that collection.');
    }

    public function codReconcile(int $id): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        $ok = service('codCollectionRepository')->markReconciled($id, (int) session()->get('user_id'));

        return redirect()->back()->with($ok ? 'success' : 'error', $ok ? 'Marked reconciled.' : 'Could not update that collection.');
    }

    public function codDepositRider(int $riderUserId): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        $n = service('codCollectionRepository')->depositAllForRider($riderUserId, (int) session()->get('user_id'));

        return redirect()->to('admin/rider-finance/cod?rider=' . $riderUserId)
            ->with($n > 0 ? 'success' : 'error', $n > 0 ? $n . ' collection(s) marked deposited.' : 'Nothing outstanding to deposit.');
    }

    // ---- Earnings payout batches ----

    public function payouts()
    {
        if ($denied = $this->guard('settlement.view')) {
            return $denied;
        }
        $repo = service('riderSettlementRepository');

        return view('admin/rider_finance/payouts', [
            'title' => 'Rider Payouts', 'pageTitle' => 'Rider Payouts', 'active' => 'rider-finance',
            'userName' => session()->get('user_name') ?: 'User',
            'batches'  => $repo->listBatches(),
            'eligible' => $repo->eligibleRiders(),
        ]);
    }

    public function runPayouts(): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        $res = service('riderSettlementRepository')->createBatch((int) session()->get('user_id'));
        if (! ($res['ok'] ?? false)) {
            return redirect()->to('admin/rider-finance/payouts')->with('error', $res['reason'] ?? 'Could not create the batch.');
        }

        return redirect()->to('admin/rider-finance/payouts/' . $res['batch_id'])
            ->with('success', 'Payout batch created: ' . $res['count'] . ' rider(s), ₹' . number_format($res['total'], 2) . '.');
    }

    public function payoutShow(int $id)
    {
        if ($denied = $this->guard('settlement.view')) {
            return $denied;
        }
        $repo  = service('riderSettlementRepository');
        $batch = $repo->batch($id);
        if ($batch === null) {
            return redirect()->to('admin/rider-finance/payouts')->with('error', 'Payout batch not found.');
        }

        return view('admin/rider_finance/payout_show', [
            'title' => 'Rider payout batch #' . $id, 'pageTitle' => 'Rider payout batch #' . $id, 'active' => 'rider-finance',
            'userName' => session()->get('user_name') ?: 'User',
            'batch'    => $batch,
            'payouts'  => $repo->payoutsForBatch($id),
        ]);
    }

    public function payoutMarkPaid(int $id): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        $ok = service('riderSettlementRepository')->markBatchPaid($id, (int) session()->get('user_id'));

        return redirect()->to('admin/rider-finance/payouts/' . $id)->with($ok ? 'success' : 'error',
            $ok ? 'Batch marked paid — rider earnings settled.' : 'Could not mark this batch paid.');
    }

    public function payoutMarkFailed(int $id): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        $ok = service('riderSettlementRepository')->markBatchFailed($id, (int) session()->get('user_id'));

        return redirect()->to('admin/rider-finance/payouts/' . $id)->with($ok ? 'success' : 'error',
            $ok ? 'Batch marked failed — entries released for re-batching.' : 'Could not update the batch.');
    }

    public function payoutRiderPaid(int $payoutId): RedirectResponse
    {
        if ($denied = $this->guard('settlement.run')) {
            return $denied;
        }
        $ok = service('riderSettlementRepository')->markRiderPaid($payoutId, (int) session()->get('user_id'));

        return redirect()->back()->with($ok ? 'success' : 'error', $ok ? 'Rider payout marked paid.' : 'Could not update that payout.');
    }

    // ---- Statement ----

    public function statement(int $riderUserId): ResponseInterface|string|RedirectResponse
    {
        if ($denied = $this->guard('settlement.view')) {
            return $denied;
        }
        $rider = service('riderRepository')->profile($riderUserId);
        if ($rider === null) {
            return redirect()->to('admin/riders')->with('error', 'Rider not found.');
        }
        $from = (string) ($this->request->getGet('from') ?: date('Y-m-d', strtotime('-30 days')));
        $to   = (string) ($this->request->getGet('to') ?: date('Y-m-d'));
        $stmt = service('riderSettlementRepository')->statement($riderUserId, $from, $to);

        if ($this->request->getGet('export') === 'csv') {
            return $this->statementCsv($rider, $stmt);
        }

        return view('admin/rider_finance/statement', [
            'title' => 'Statement · ' . ($rider['name'] ?? ''), 'pageTitle' => 'Rider statement · ' . ($rider['name'] ?? ''), 'active' => 'rider-finance',
            'userName' => session()->get('user_name') ?: 'User',
            'rider'    => $rider,
            'riderId'  => $riderUserId,
            'stmt'     => $stmt,
        ]);
    }

    /** @param array<string,mixed> $rider @param array<string,mixed> $stmt */
    private function statementCsv(array $rider, array $stmt): ResponseInterface
    {
        $rows   = [];
        $rows[] = ['Rider', $rider['name'] ?? '', 'Period', $stmt['from'] . ' to ' . $stmt['to']];
        $rows[] = ['Deliveries', $stmt['deliveries'], 'Earnings', $stmt['earnings']];
        $rows[] = ['Paid', $stmt['paid'], 'Unpaid', $stmt['unpaid']];
        $rows[] = ['COD collected', $stmt['cod']['collected'], 'COD outstanding', $stmt['cod']['outstanding']];
        $rows[] = [];
        $rows[] = ['Date', 'Order', 'Delivery', 'Model', 'Order value', 'Km', 'Amount', 'Status'];
        foreach ($stmt['lines'] as $l) {
            $rows[] = [substr((string) $l['created_at'], 0, 16), $l['order_no'] ?? '', $l['delivery_id'], $l['model'], $l['order_value'], $l['distance_km'], $l['amount'], $l['status']];
        }
        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        return $this->response
            ->setHeader('Content-Type', 'text/csv')
            ->setHeader('Content-Disposition', 'attachment; filename="rider-' . ($rider['id'] ?? 'x') . '-statement.csv"')
            ->setBody($csv);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
