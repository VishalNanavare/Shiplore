<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use App\Libraries\Workflow\StatusMachine;
use Config\Database;

/** Vendor\OrderController — the vendor's own sub-orders + detail. */
final class OrderController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        $status = $this->request->getGet('status');
        $own    = $this->request->getGet('own');
        $own    = in_array($own, ['mine', 'unclaimed', 'escalated', 'urgent'], true) ? $own : null;
        $userId = (int) session()->get('user_id');

        return $this->render('vendor/orders/index', 'orders', 'Orders', [
            'orders'      => service('vendorOrderRepository')->list($this->vendorId(), is_string($status) ? $status : null, $this->effectiveShopId(), 0, 0, null, null, null, $own, $userId),
            'shopOptions' => $this->shopOptions(),
            'shopId'      => $this->requestedShopId(),
            'own'         => $own,
            'filterKeep'  => ['status' => is_string($status) ? $status : null, 'own' => $own],
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        $repo = service('vendorOrderRepository');
        $sub  = $repo->findSubOrder($id, $this->vendorId());
        if ($sub === null) {
            return redirect()->to('vendor/orders')->with('error', 'Order not found.');
        }
        // S0: a branch manager may only open orders for their assigned shop(s).
        if (! $this->isOwner() && ! in_array((int) ($sub['shop_id'] ?? 0), $this->allowedShopIds(), true)) {
            return redirect()->to('vendor/orders')->with('error', 'Order not found.');
        }

        $userId    = (int) session()->get('user_id');
        $claimSvc  = service('orderClaimService');
        $claim     = $claimSvc->owner($id);
        $actorRole = $this->isOwner() ? 'vendor' : 'shop';
        $isOwner   = $claim === null || ((int) ($claim['claimed_by_user_id'] ?? 0) === $userId);

        // Available riders for manual assignment — only this vendor's own fleet
        // (closes the IDOR where any active rider could be picked from the form).
        $availableRiders = Database::connect()->table('delivery_boys db')
            ->select('db.id, db.user_id, u.name, u.phone, db.current_lat, db.current_lng')
            ->join('users u', 'u.id = db.user_id', 'left')
            ->where('db.vendor_id', $this->vendorId())
            ->where('db.availability', 'available')
            ->where('db.status', 'active')
            ->limit(20)
            ->get()->getResultArray();

        $delivery = $repo->delivery($id);

        // Lock the trip only when a POOL rider has accepted it — they own the delivery
        // and the vendor may no longer (re)assign. Self-delivery (mode='self') stays
        // under the shop's control, so they can always (re)assign their own rider.
        $riderAccepted = (bool) Database::connect()->table('deliveries d')
            ->join('delivery_assignments da', "da.delivery_id = d.id AND da.status = 'accepted'", 'inner')
            ->where('d.sub_order_id', $id)->where('d.deleted_at', null)->where('d.mode', 'pool')
            ->countAllResults();

        return $this->render('vendor/orders/show', 'orders', $sub['sub_order_no'], [
            'sub'             => $sub,
            'items'           => $repo->items($id),
            'invoiceId'       => service('invoiceService')->ensureForSubOrder($id, $userId, (string) ($sub['status'] ?? '')),
            'claim'           => $claim,
            'actorRole'       => $actorRole,
            'isClaimOwner'    => $isOwner,
            'delivery'        => $delivery,
            'availableRiders' => $availableRiders,
            'riderAccepted'   => $riderAccepted,
            'nextStatuses'    => StatusMachine::allowedNextSubOrder((string) ($sub['status'] ?? '')),
        ]);
    }

    /** AJAX POST: change sub-order status from the web panel. */
    public function updateStatus(int $id)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $vid    = $this->vendorId();
        $status = (string) ($this->request->getPost('status') ?? '');
        $repo   = service('vendorOrderRepository');
        $sub    = $repo->findSubOrder($id, $vid);

        if ($sub === null || (! $this->isOwner() && ! in_array((int) ($sub['shop_id'] ?? 0), $this->allowedShopIds(), true))) {
            return redirect()->back()->with('error', 'Order not found.');
        }
        if (! StatusMachine::canSubOrder((string) $sub['status'], $status)) {
            return redirect()->back()->with('error', "Cannot move a {$sub['status']} order to {$status}.");
        }

        $userId    = (int) session()->get('user_id');
        $actorRole = $this->isOwner() ? 'vendor' : 'shop';
        $result    = $repo->updateSubOrderStatus($id, $vid, $status, $actorRole, $userId, (string) $this->request->getIPAddress(), (string) $this->request->getUserAgent());

        if (! $result['ok']) {
            $msg = match ($result['reason'] ?? '') {
                'locked'             => 'Order is being handled by ' . ($result['owner']['label'] ?? 'another user') . '.',
                'escalation_blocked' => 'Order has been escalated. Only ' . ucfirst((string) ($result['escalation_level'] ?? '')) . ' can act on it.',
                'rider_controls'     => 'A delivery rider is handling this trip — they will mark it delivered.',
                'otp_unverified'     => 'Verify the customer delivery OTP before marking this order delivered.',
                default              => 'Could not update order status.',
            };

            return redirect()->back()->with('error', $msg);
        }

        if (in_array($status, ['out_for_delivery', 'delivered'], true)) {
            try {
                service('invoiceService')->generateForSubOrder($id, $userId);
            } catch (\Throwable) {
            }
        }
        if ($status === 'delivered') {
            try {
                service('commissionHoldService')->holdForSubOrderId($id);
            } catch (\Throwable) {
            }
        }

        return redirect()->to('vendor/orders/' . $id)->with('success', 'Order status updated to ' . str_replace('_', ' ', $status) . '.');
    }

    /** AJAX POST: heartbeat to refresh claim while order detail page is open. */
    public function heartbeat(int $id)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $refreshed = service('orderClaimService')->heartbeat($id, (int) session()->get('user_id'));

        return $this->response->setJSON(['refreshed' => $refreshed]);
    }

    /** POST: verify delivery OTP from the web panel. */
    public function verifyOtp(int $id)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $repo = service('vendorOrderRepository');
        $sub  = $repo->findSubOrder($id, $this->vendorId());
        if ($sub === null || (! $this->isOwner() && ! in_array((int) ($sub['shop_id'] ?? 0), $this->allowedShopIds(), true))) {
            return redirect()->back()->with('error', 'Order not found.');
        }
        // G2: acquire the claim before any write — a non-owner POSTing directly is
        // blocked server-side, not just by the hidden UI.
        $userId    = (int) session()->get('user_id');
        $actorRole = $this->isOwner() ? 'vendor' : 'shop';
        $cr        = service('orderClaimService')->claim($id, $actorRole, $userId, (string) $this->request->getIPAddress(), (string) $this->request->getUserAgent());
        if (! $cr['ok']) {
            return redirect()->back()->with('error', $this->claimFailMessage($cr));
        }
        if (! empty($sub['otp_verified_at'])) {
            return redirect()->back()->with('error', 'OTP already verified.');
        }
        if ((int) ($sub['otp_attempts'] ?? 0) >= 5) {
            return redirect()->back()->with('error', 'OTP locked — too many wrong attempts.');
        }
        // 60-second cooldown between wrong attempts.
        if (! empty($sub['otp_last_attempt_at']) && (time() - strtotime((string) $sub['otp_last_attempt_at'])) < 60) {
            return redirect()->back()->with('error', 'Too fast — please wait a moment before trying again.');
        }
        // OTP expires 24h after dispatch.
        if (! empty($sub['otp_expires_at']) && strtotime((string) $sub['otp_expires_at']) < time()) {
            return redirect()->back()->with('error', 'OTP has expired. Please regenerate it.');
        }
        $ip       = (string) $this->request->getIPAddress();
        $ua       = (string) $this->request->getUserAgent();
        $claimSvc = service('orderClaimService');
        $otp      = trim((string) $this->request->getPost('otp'));
        if ($otp !== (string) ($sub['delivery_otp'] ?? '')) {
            $repo->incrementOtpAttempts($id);
            $claimSvc->log($id, 'otp_attempt', null, $userId, null, null, 'failed', $ip, $ua);
            $remaining = max(0, 5 - $repo->otpAttempts($id));

            return redirect()->back()->with('error', "Incorrect OTP. {$remaining} attempt(s) remaining.");
        }
        if (! $repo->markOtpVerified($id)) {
            return redirect()->back()->with('error', 'OTP already verified.');
        }
        $claimSvc->log($id, 'otp_attempt', null, $userId, null, null, 'success', $ip, $ua);

        return redirect()->to('vendor/orders/' . $id)->with('success', 'OTP verified successfully.');
    }

    /** POST: assign rider to a delivery from the web panel. */
    public function assignDelivery(int $id)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $repo = service('vendorOrderRepository');
        $sub  = $repo->findSubOrder($id, $this->vendorId());
        if ($sub === null || (! $this->isOwner() && ! in_array((int) ($sub['shop_id'] ?? 0), $this->allowedShopIds(), true))) {
            return redirect()->back()->with('error', 'Order not found.');
        }
        // G2: acquire the claim before assigning — a non-owner POSTing directly is
        // blocked server-side, not just by the hidden UI.
        $userId    = (int) session()->get('user_id');
        $ip        = (string) $this->request->getIPAddress();
        $ua        = (string) $this->request->getUserAgent();
        $actorRole = $this->isOwner() ? 'vendor' : 'shop';
        $claimSvc  = service('orderClaimService');
        $cr        = $claimSvc->claim($id, $actorRole, $userId, $ip, $ua);
        if (! $cr['ok']) {
            return redirect()->back()->with('error', $this->claimFailMessage($cr));
        }

        $mode     = $this->request->getPost('mode') === 'self' ? 'self' : 'pool';
        $riderRaw = (string) $this->request->getPost('rider_user_id');
        // Self-delivery default: the "self" option (or no rider chosen) means the SHOP
        // itself delivers — the current logged-in user is the deliverer, no external
        // rider required. Picking a named fleet/staff rider is still optional.
        $selfDeliver = ($mode === 'self' && in_array($riderRaw, ['self', ''], true));
        $riderUserId = $selfDeliver ? $userId : (int) $riderRaw;

        $delivery = $repo->delivery($id);
        if ($delivery === null) {
            return redirect()->back()->with('error', 'No delivery record found. Accept the order first.');
        }
        $deliveryId = (int) $delivery['id'];

        // A rider is already offered/accepted → this is a reassign (supersede the offer).
        $existingRider = (bool) Database::connect()->table('delivery_assignments da')
            ->where('da.delivery_id', $deliveryId)
            ->whereIn('da.status', ['offered', 'accepted'])
            ->countAllResults();

        $deliveryRepo = service('vendorDeliveryRepository');

        if ($selfDeliver) {
            // The shop (current user) delivers it themselves — assign() supersedes any
            // current offer and stores deliveries.mode='self'.
            if (! $deliveryRepo->assign($deliveryId, $userId, $this->vendorId(), $userId, 'self', 'Self-delivery by shop')) {
                return redirect()->back()->with('error', 'Could not set self-delivery.');
            }
            $assignedRiderId = $userId;
        } elseif ($existingRider) {
            if ($riderUserId <= 0) {
                return redirect()->back()->with('error', 'Please select a rider to reassign to.');
            }
            if (! $deliveryRepo->reassign($deliveryId, $riderUserId, $this->vendorId(), 'manual reassign', $userId)) {
                return redirect()->back()->with('error', 'Could not reassign that rider. They may not belong to your fleet.');
            }
            $assignedRiderId = $riderUserId;
        } elseif ($mode === 'pool') {
            // autoAssign resolves the shop's real coordinates + nearest eligible rider.
            $assignedRiderId = $deliveryRepo->autoAssign($deliveryId, $this->vendorId(), $userId);
            if ($assignedRiderId === null) {
                return redirect()->back()->with('error', 'No eligible riders available. Try manual assignment.');
            }
        } else {
            if ($riderUserId <= 0) {
                return redirect()->back()->with('error', 'Please select a rider, or choose self-delivery.');
            }
            if (! $deliveryRepo->assign($deliveryId, $riderUserId, $this->vendorId(), $userId, $mode)) {
                return redirect()->back()->with('error', 'Could not assign that rider. They may not belong to your fleet.');
            }
            $assignedRiderId = $riderUserId;
        }

        $claimSvc->log($id, $existingRider ? 'rider_reassigned' : 'rider_assigned', $actorRole, $userId, null, null, ($selfDeliver ? 'self-delivery (shop) user ' : 'rider user ') . $assignedRiderId, $ip, $ua);
        $msg = $selfDeliver ? 'Self-delivery set — the shop will deliver this order.' : ($existingRider ? 'Rider reassigned successfully.' : 'Rider assigned successfully.');

        return redirect()->to('vendor/orders/' . $id)->with('success', $msg);
    }

    /** POST: regenerate the customer delivery OTP (owner-only). */
    public function regenerateOtp(int $id)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $repo = service('vendorOrderRepository');
        $sub  = $repo->findSubOrder($id, $this->vendorId());
        if ($sub === null || (! $this->isOwner() && ! in_array((int) ($sub['shop_id'] ?? 0), $this->allowedShopIds(), true))) {
            return redirect()->back()->with('error', 'Order not found.');
        }
        // Regeneration resets the OTP + attempt lock, so it is owner-only — a branch
        // cashier must not be able to clear the throttle on demand.
        if (! $this->isOwner()) {
            return redirect()->back()->with('error', 'Only the vendor owner can regenerate a delivery OTP.');
        }
        $userId    = (int) session()->get('user_id');
        $ip        = (string) $this->request->getIPAddress();
        $ua        = (string) $this->request->getUserAgent();
        $actorRole = 'vendor';
        $claimSvc  = service('orderClaimService');
        $cr        = $claimSvc->claim($id, $actorRole, $userId, $ip, $ua);
        if (! $cr['ok']) {
            return redirect()->back()->with('error', $this->claimFailMessage($cr));
        }
        if (! empty($sub['otp_verified_at'])) {
            return redirect()->back()->with('error', 'OTP already verified — regeneration not needed.');
        }

        $newOtp = (string) random_int(100000, 999999);
        Database::connect()->table('sub_orders')
            ->where('id', $id)->update([
                'delivery_otp'        => $newOtp,
                'otp_attempts'        => 0,
                'otp_last_attempt_at' => null,
                'otp_expires_at'      => date('Y-m-d H:i:s', strtotime('+24 hours')),
            ]);

        // Notify the customer of the new OTP (in-app only — never put the code in a push body).
        try {
            $customerId = Database::connect()->table('orders')
                ->select('customer_id')->where('id', $sub['order_id'])->get()->getRowArray()['customer_id'] ?? null;
            if ($customerId) {
                $custUserId = Database::connect()->table('customers')->select('user_id')->where('id', $customerId)->get()->getRowArray()['user_id'] ?? null;
                if ($custUserId) {
                    service('notificationService')->notify((int) $custUserId, 'order.otp_regenerated', ['sub_order_no' => $sub['sub_order_no']]);
                }
            }
        } catch (\Throwable) {
        }
        $claimSvc->log($id, 'regenerated', $actorRole, $userId, null, null, 'otp regenerated', $ip, $ua);

        return redirect()->to('vendor/orders/' . $id)->with('success', 'New OTP generated and sent to the customer.');
    }

    /**
     * Map a failed claim() result to the same user-facing message updateStatus uses.
     *
     * @param array{ok:bool,reason?:string,owner?:array<string,mixed>,escalation_level?:string} $cr
     */
    private function claimFailMessage(array $cr): string
    {
        return match ($cr['reason'] ?? '') {
            'locked'             => 'Order is being handled by ' . ($cr['owner']['label'] ?? 'another user') . '.',
            'escalation_blocked' => 'Order has been escalated. Only ' . ucfirst((string) ($cr['escalation_level'] ?? '')) . ' can act on it.',
            'rider_controls'     => 'A delivery rider is handling this trip — they will mark it delivered.',
            'otp_unverified'     => 'Verify the customer delivery OTP before marking this order delivered.',
            default              => 'Could not update order status.',
        };
    }
}
