<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\OrderController — order oversight across all vendors. Lists orders,
 * shows the full breakdown (sub-orders per vendor/shop + line items) and can
 * cancel an order. Session-guarded (`webAuth`); RBAC-checked (`order.view` to
 * read, `order.cancel` to cancel); POSTs CSRF-protected at the route.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md §4
 */
final class OrderController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('order.view')) {
            return $denied;
        }

        $opts = $this->filterOpts();

        return view('admin/orders/index', [
            'title'     => 'Orders · Admin',
            'pageTitle' => 'Orders',
            'active'    => 'orders',
            'userName'  => session()->get('user_name') ?: 'User',
            'orders'    => service('orderRepository')->search($opts),
            'filters'   => $opts,
        ]);
    }

    /** CSV export of the (filtered) order list. */
    public function export()
    {
        if ($denied = $this->guard('order.view')) {
            return $denied;
        }
        $rows = service('orderRepository')->search($this->filterOpts(5000));

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['order_no', 'customer', 'email', 'channel', 'status', 'payment_status', 'grand_total', 'placed_at']);
        foreach ($rows as $r) {
            fputcsv($out, array_map([self::class, 'csvCell'], [$r['order_no'], $r['customer'] ?? '', $r['customer_email'] ?? '', $r['channel'], $r['status'], $r['payment_status'], $r['grand_total'], $r['placed_at'] ?? $r['created_at']]));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $this->response->setHeader('Content-Type', 'text/csv')
            ->setHeader('Content-Disposition', 'attachment; filename="orders-' . date('Ymd') . '.csv"')
            ->setBody($csv);
    }

    /** @return array<string,mixed> filters from the query string */
    private function filterOpts(int $limit = 500): array
    {
        $r = $this->request;

        return array_filter([
            'status'         => (string) $r->getGet('status'),
            'payment_status' => (string) $r->getGet('payment_status'),
            'q'              => trim((string) $r->getGet('q')),
            'from'           => (string) $r->getGet('from'),
            'to'             => (string) $r->getGet('to'),
            'limit'          => $limit,
        ], static fn ($v) => $v !== '' && $v !== null);
    }

    public function show(int $id)
    {
        if ($denied = $this->guard('order.view')) {
            return $denied;
        }

        $repo  = service('orderRepository');
        $order = $repo->findById($id);
        if ($order === null) {
            return redirect()->to('admin/orders')->with('error', 'Order not found.');
        }
        $subOrders = $repo->subOrders($id);

        // Lazy-generate each sub-order's GST invoice (idempotent) so the admin can
        // download a PDF per vendor sub-order.
        $invoiceBySub = [];
        foreach ($subOrders as $so) {
            $invoiceBySub[(int) $so['id']] = service('invoiceService')
                ->ensureForSubOrder((int) $so['id'], (int) session()->get('user_id'), (string) ($so['status'] ?? ''));
        }

        // Load claim + escalation info for each sub-order (ownership dashboard)
        $db          = \Config\Database::connect();
        $adminOrders = new \App\Models\AdminOrderRepository();
        $subClaims   = [];
        $claimLogs   = [];
        foreach ($subOrders as $so) {
            $subId       = (int) $so['id'];
            $subClaims[] = $db->table('sub_orders so')
                ->select('so.id, so.sub_order_no, so.claimed_by_role, so.claimed_by_user_id, so.claim_expires_at, so.escalation_level, so.priority_level, u.name AS handler_name')
                ->join('users u', 'u.id = so.claimed_by_user_id', 'left')
                ->where('so.id', $subId)->get()->getRowArray() ?: [];
            $claimLogs[$subId] = $adminOrders->claimLogs($subId);
        }

        return view('admin/orders/show', [
            'title'        => 'Order ' . ($order['order_no'] ?? '') . ' · Admin',
            'pageTitle'    => 'Order ' . ($order['order_no'] ?? ''),
            'active'       => 'orders',
            'userName'     => session()->get('user_name') ?: 'User',
            'order'        => $order,
            'subOrders'    => $subOrders,
            'invoiceBySub' => $invoiceBySub,
            'items'        => $repo->items($id),
            'returns'      => service('returnRepository')->forOrder($id),
            'refunds'      => service('refundRepository')->forOrder($id),
            'subClaims'    => $subClaims,
            'claimLogs'    => $claimLogs,
        ]);
    }

    public function cancel(int $id): RedirectResponse
    {
        if ($denied = $this->guard('order.cancel')) {
            return $denied;
        }

        $repo = service('orderRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/orders')->with('error', 'Order not found.');
        }

        $reason = trim((string) $this->request->getPost('reason'));
        $ok = $repo->cancel($id, (int) session()->get('user_id'), $reason !== '' ? $reason : null);

        return redirect()->to('admin/orders/' . $id)
            ->with($ok ? 'success' : 'error', $ok ? 'Order cancelled.' : 'Order cannot be cancelled.');
    }

    /** Admin force-release of a sub-order's claim (by sub-order id). */
    public function releaseClaim(int $subOrderId): RedirectResponse
    {
        if ($denied = $this->guard('order.manage')) {
            return $denied;
        }
        $reason = trim((string) $this->request->getPost('reason'));
        service('orderClaimService')->forceRelease(
            $subOrderId,
            (int) session()->get('user_id'),
            $reason !== '' ? $reason : 'Force-released by admin',
            (string) $this->request->getIPAddress(),
            (string) $this->request->getUserAgent()
        );

        return redirect()->back()->with('success', 'Claim released.');
    }

    /** Set priority level on a sub-order (admin). */
    public function setPriority(int $subOrderId): RedirectResponse
    {
        if ($denied = $this->guard('order.manage')) {
            return $denied;
        }
        $level = max(0, min(3, (int) $this->request->getPost('priority_level')));
        \Config\Database::connect()->table('sub_orders')
            ->where('id', $subOrderId)->update(['priority_level' => $level]);

        return redirect()->back()->with('success', 'Priority updated.');
    }

    /**
     * Admin force-claim — take ownership of a sub-order regardless of who (if
     * anyone) currently holds it, and escalate it to the admin level.
     */
    public function forceClaim(int $subOrderId): RedirectResponse
    {
        if ($denied = $this->guard('order.manage')) {
            return $denied;
        }
        $adminUserId = (int) session()->get('user_id');
        $db          = \Config\Database::connect();

        $db->transStart();
        $prev = $db->table('sub_orders')
            ->select('claimed_by_role, claimed_by_user_id')
            ->where('id', $subOrderId)->get()->getRowArray();
        $db->query(
            "UPDATE sub_orders
             SET    claimed_by_role    = 'admin',
                    claimed_by_user_id = ?,
                    claimed_at         = NOW(),
                    claim_expires_at   = DATE_ADD(NOW(), INTERVAL 3 MINUTE),
                    claim_heartbeat_at = NOW(),
                    escalation_level   = 'admin'
             WHERE  id = ?",
            [$adminUserId, $subOrderId]
        );
        $db->transComplete();

        service('orderClaimService')->log(
            $subOrderId,
            'force_claimed',
            $prev['claimed_by_role'] ?? null,
            isset($prev['claimed_by_user_id']) ? (int) $prev['claimed_by_user_id'] : null,
            'admin',
            $adminUserId,
            'admin force claim',
            (string) $this->request->getIPAddress(),
            (string) $this->request->getUserAgent()
        );

        return redirect()->back()->with('success', 'Order claimed by admin.');
    }

    /**
     * Admin de-escalation — hand an escalated sub-order back down to the shop.
     *
     * Escalation is one-way (shop → vendor → admin); once an order reaches a
     * higher level the lower levels are locked out (downward-claim block). This
     * is the ONLY legitimate way back down: the admin explicitly returns the
     * order to the shop, resetting the escalation incident and clearing any
     * claim so the shop can accept / self-deliver again. Without it an escalated
     * order is permanently shop-locked (force-release only clears the claim).
     */
    public function returnToShop(int $subOrderId): RedirectResponse
    {
        if ($denied = $this->guard('order.manage')) {
            return $denied;
        }
        $adminUserId = (int) session()->get('user_id');
        $db          = \Config\Database::connect();

        $prev = $db->table('sub_orders')
            ->select('escalation_level, claimed_by_role, claimed_by_user_id')
            ->where('id', $subOrderId)->get()->getRowArray();
        if ($prev === null) {
            return redirect()->back()->with('error', 'Order not found.');
        }
        if (($prev['escalation_level'] ?? 'shop') === 'shop') {
            return redirect()->back()->with('error', 'Order is already at shop level.');
        }

        // accept_deadline_at is set via SQL NOW() (not PHP date()) so the grace
        // window the escalation cron reads is on MySQL's clock — immune to the
        // PHP(UTC) <-> MySQL(IST) offset that would otherwise skew it by hours.
        $db->table('sub_orders')->where('id', $subOrderId)
            ->set('accept_deadline_at', 'DATE_ADD(NOW(), INTERVAL 10 MINUTE)', false)
            ->update([
                'escalation_level'          => 'shop',
                'escalation_notified_at'    => null,
                'escalation_reminder_count' => 0,
                'claimed_by_role'           => null,
                'claimed_by_user_id'        => null,
                'claimed_at'                => null,
                'claim_expires_at'          => null,
                'claim_heartbeat_at'        => null,
            ]);

        service('orderClaimService')->log(
            $subOrderId,
            'de_escalated',
            (string) $prev['escalation_level'],
            $adminUserId,
            'shop',
            null,
            'Returned to shop by admin',
            (string) $this->request->getIPAddress(),
            (string) $this->request->getUserAgent()
        );

        return redirect()->back()->with('success', 'Order returned to shop.');
    }

    /** Admin override of a sub-order's active delivery status. */
    public function overrideDelivery(int $subOrderId): RedirectResponse
    {
        if ($denied = $this->guard('order.manage')) {
            return $denied;
        }
        $status = (string) $this->request->getPost('status');
        $allowed = ['pending', 'assigned', 'picked_up', 'arrived', 'out_for_delivery', 'delivered', 'failed', 'returned'];
        if (! in_array($status, $allowed, true)) {
            return redirect()->back()->with('error', 'Invalid delivery status.');
        }

        $adminUserId = (int) session()->get('user_id');
        $db          = \Config\Database::connect();

        $delivery = $db->table('deliveries')
            ->select('id')
            ->where('sub_order_id', $subOrderId)
            ->where('deleted_at IS NULL', null, false)
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        if ($delivery === null) {
            return redirect()->back()->with('error', 'No active delivery for this sub-order.');
        }

        $db->transStart();
        $ok = service('deliveryRepository')->updateStatus((int) $delivery['id'], $status, $adminUserId, true); // admin force-override
        $db->transComplete();

        if (! $ok) {
            return redirect()->back()->with('error', 'Could not override delivery status.');
        }

        service('orderClaimService')->log(
            $subOrderId,
            'delivery_override',
            'admin',
            $adminUserId,
            null,
            null,
            'delivery → ' . $status,
            (string) $this->request->getIPAddress(),
            (string) $this->request->getUserAgent()
        );

        return redirect()->back()->with('success', 'Delivery status overridden.');
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }

    /**
     * Neutralise spreadsheet formula injection in an exported cell.
     *
     * Excel/Calc/Sheets evaluate any cell whose text begins with = + - @ TAB or CR as a
     * formula; fputcsv()'s quoting does not stop that, because the CSV parser consumes
     * the quotes before the value is interpreted. The columns exported here carry
     * vendor- and customer-supplied free text, so this is the one place their input
     * crosses out of the browser sandbox onto an operator's machine. A leading
     * apostrophe is the standard text marker and is not displayed by the reader.
     */
    private static function csvCell(mixed $v): string
    {
        $s = (string) $v;

        return $s !== '' && str_contains("=+-@\t\r", $s[0]) ? "'" . $s : $s;
    }
}
