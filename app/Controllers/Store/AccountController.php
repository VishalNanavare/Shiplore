<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * AccountController — customer OTP sign-in + account area: profile, orders,
 * saved addresses, notifications, support, return/refund requests and review
 * submission. Account pages require a customer session.
 */
final class AccountController extends BaseStoreController
{
    // ---- Auth (mobile-number OTP, with email as an alternate) ----
    public function loginForm(): string|RedirectResponse
    {
        if ($this->customerId() !== null) {
            return redirect()->to('store/account');
        }

        $via   = $this->request->getGet('via') === 'email' ? 'email' : 'phone';
        $stage = session()->get('login_phone') ? 'verify_phone'
            : (session()->get('login_email') ? 'verify_email' : $via);

        // Real mobile OTP uses the configured Firebase Phone Auth (same infra as
        // staff login). Falls back to the dev code flow only when not configured.
        try {
            $cfg = service('integrationRepository')->config('firebase');
        } catch (\Throwable) {
            $cfg = [];
        }
        $project = trim((string) ($cfg['project_id'] ?? ''));

        return view('store/login', [
            'title' => 'Sign in or sign up',
            'stage' => $stage,
            'fb'    => [
                'apiKey'            => trim((string) ($cfg['web_api_key'] ?? '')),
                'authDomain'        => $project !== '' ? $project . '.firebaseapp.com' : '',
                'projectId'         => $project,
                'messagingSenderId' => trim((string) ($cfg['sender_id'] ?? '')),
                'appId'             => trim((string) ($cfg['app_id'] ?? '')),
            ],
        ]);
    }

    /**
     * Real mobile OTP: the browser completes Firebase Phone Auth and posts the
     * resulting ID token here; we verify it server-side, then sign in (or create)
     * the customer by their verified phone. CSRF-protected, returns JSON.
     */
    public function otpLogin()
    {
        $claims = service('firebaseVerifier')->verify((string) $this->request->getPost('id_token'));
        if ($claims === null) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'message' => 'Could not verify the code. Please try again.', 'csrf' => csrf_hash()]);
        }
        $raw   = trim((string) ($claims['phone_number'] ?? ''));
        $phone = \App\Models\StoreCustomerRepository::normalizePhone($raw) ?? $raw;
        if ($phone === '') {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'message' => 'The verified token has no phone number.', 'csrf' => csrf_hash()]);
        }
        $cust = service('storeCustomerRepository')->findOrCreateByPhone($phone, trim((string) $this->request->getPost('name')));
        if ($cust === null) {
            return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'message' => 'Could not create your account. Please try again.', 'csrf' => csrf_hash()]);
        }
        session()->regenerate();
        session()->set(['customer_id' => $cust['customer_id'], 'customer_user_id' => $cust['user_id'], 'customer_name' => $cust['name']]);

        return $this->response->setJSON(['ok' => true, 'redirect' => site_url($this->loginRedirect()), 'csrf' => csrf_hash()]);
    }

    /** Mobile OTP — primary. Creates the account on first verify (signup = login). */
    public function sendCode(): RedirectResponse
    {
        $phone = \App\Models\StoreCustomerRepository::normalizePhone((string) $this->request->getPost('phone'));
        if ($phone === null) {
            return redirect()->to('store/login')->with('error', 'Enter a valid 10-digit mobile number.');
        }
        $name = trim((string) $this->request->getPost('name'));

        $code = service('otpService')->issue($phone, 'login');
        $brand = service('settingsRepository')->brandName();
        $sms  = service('smsSender')->send($phone, 'Your ' . $brand . ' verification code is ' . $code . '. Valid for 10 minutes.');

        session()->set('login_phone', $phone);
        session()->set('login_name', $name);

        // SmsSender reports dev=true whenever no gateway is configured — which is the
        // normal state in production. Surfacing the code on that flag alone printed a
        // live login OTP on screen to anyone who typed a phone number: a complete
        // bypass of phone ownership. The dev fallback is now gated on the environment,
        // never on the gateway's own report.
        if (! ($sms['ok'] ?? false)) {
            log_message('error', 'Store login: SMS to ' . $phone . ' undeliverable (provider=' . ($sms['provider'] ?? '?') . ') ' . ($sms['message'] ?? ''));

            if (ENVIRONMENT === 'production') {
                return redirect()->to('store/login')
                    ->with('error', 'We could not send your code right now. Please try again shortly.');
            }

            return redirect()->to('store/login')
                ->with('status', 'Code generated (no SMS gateway configured).')
                ->with('dev_codes', 'DEV — SMS OTP: ' . $code);
        }

        return redirect()->to('store/login')->with('status', 'Code sent to ' . $phone . '.');
    }

    /** Email OTP — alternate path (existing accounts only). */
    public function sendCodeEmail(): RedirectResponse
    {
        $email = trim((string) $this->request->getPost('email'));
        $cust  = $email !== '' ? service('storeCustomerRepository')->findByEmail($email) : null;
        if ($cust === null) {
            return redirect()->to('store/login?via=email')->with('error', 'No account for that email. Use your mobile number to sign up.');
        }

        // purpose 'login_email' keeps this out of the shared 'verify' namespace that the
        // public api/v1/auth/otp/request endpoint writes to — otherwise anyone could mint
        // a newer row for this address and silently invalidate the code we just mailed.
        $code = service('otpService')->issue($email, 'login_email');
        session()->set('login_email', $email);

        // Actually send the code over SMTP when the mailer is configured; only fall
        // back to showing it on screen (dev) when email delivery isn't available.
        $mailer = service('mailer');
        $sent   = false;
        if ($mailer->configured()) {
            $brand = service('settingsRepository')->brandName();
            $html = '<p>Your ' . $brand . ' sign-in code is:</p>'
                . '<p style="font-size:28px;font-weight:700;letter-spacing:4px">' . $code . '</p>'
                . "<p>This code expires in 10 minutes. If you didn't request it, you can ignore this email.</p>";
            $sent = $mailer->send($email, 'Your ' . $brand . ' sign-in code', $html);
        }
        if (! $sent) {
            log_message('error', 'Store login: sign-in code to ' . $email . ' could not be sent.');
            if (ENVIRONMENT === 'production') {
                return redirect()->to('store/login?via=email')
                    ->with('error', 'We could not send your sign-in code. Please try again shortly.');
            }
        }
        $r = redirect()->to('store/login')->with('status', 'Code sent to ' . $email . '.');

        return $sent ? $r : $r->with('dev_codes', 'DEV — Email OTP: ' . $code);
    }

    public function verify(): RedirectResponse
    {
        $code = (string) $this->request->getPost('code');

        // Phone path (primary): verify, then find-or-create the customer.
        if (($phone = (string) session()->get('login_phone')) !== '') {
            if (! service('otpService')->verify($phone, $code, 'login')) {
                return redirect()->to('store/login')->with('error', 'Invalid or expired code.');
            }
            $cust = service('storeCustomerRepository')->findOrCreateByPhone($phone, (string) session()->get('login_name'));
            if ($cust === null) {
                return redirect()->to('store/login')->with('error', 'Could not create your account. Please try again.');
            }
            session()->remove(['login_phone', 'login_name']);
            session()->set(['customer_id' => $cust['customer_id'], 'customer_user_id' => $cust['user_id'], 'customer_name' => $cust['name']]);

            return redirect()->to($this->loginRedirect())->with('success', 'Signed in.');
        }

        // Email path (alternate).
        $email = (string) session()->get('login_email');
        if ($email === '' || ! service('otpService')->verify($email, $code, 'login_email')) {
            return redirect()->to('store/login')->with('error', 'Invalid or expired code.');
        }
        $cust = service('storeCustomerRepository')->findByEmail($email);
        if ($cust === null) {
            return redirect()->to('store/login')->with('error', 'Account not found.');
        }
        session()->remove('login_email');
        session()->set(['customer_id' => $cust['customer_id'], 'customer_user_id' => $cust['user_id'], 'customer_name' => $cust['name']]);

        return redirect()->to($this->loginRedirect())->with('success', 'Signed in.');
    }

    /** Where to send the customer after a successful sign-in: the saved return path
     *  (e.g. checkout) if one is pending and safe, else the account home. */
    private function loginRedirect(): string
    {
        $r = (string) session()->get('login_return');
        session()->remove('login_return');

        return (str_starts_with($r, 'store/') && ! str_starts_with($r, 'store//')) ? $r : 'store/account';
    }

    public function logout(): RedirectResponse
    {
        session()->remove(['customer_id', 'customer_user_id', 'customer_name', 'login_email', 'login_phone', 'login_name']);

        return redirect()->to('store')->with('success', 'Signed out.');
    }

    // ---- Account dashboard (single self-contained page; everything in tabs) ----
    public function index(): string|RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        $cid    = (int) $this->customerId();
        $repo   = service('storeCustomerRepository');
        $orders = service('storeOrderRepository')->forCustomer($cid);

        // Invoices per order (ensure for delivered/completed sub-orders, then map).
        $invoices = [];
        try {
            foreach (service('invoiceRepository')->pendingInvoiceSubOrdersForCustomer($cid) as $so) {
                service('invoiceService')->ensureForSubOrder((int) $so['id'], null, (string) ($so['status'] ?? ''));
            }
            foreach (service('invoiceRepository')->listForCustomer($cid) as $inv) {
                $invoices[(string) $inv['order_no']][] = $inv;
            }
        } catch (\Throwable) {
        }

        // Wishlist (session ids → resolved products).
        $wishIds  = array_values(array_unique(array_map('intval', session()->get('store_wishlist') ?: [])));
        $wishlist = $wishIds === [] ? [] : array_values(array_filter(
            service('storeCatalogRepository')->products(['limit' => 60]),
            static fn ($p) => in_array((int) $p['id'], $wishIds, true)
        ));

        $addresses = $repo->addresses($cid);
        $spent     = 0.0;
        foreach ($orders as $o) {
            if ($o['status'] !== 'cancelled') {
                $spent += (float) $o['grand_total'];
            }
        }

        $tab = (string) $this->request->getGet('tab');
        $tab = in_array($tab, ['overview', 'orders', 'addresses', 'wishlist', 'notifications', 'support', 'profile'], true) ? $tab : 'overview';

        return view('store/account/index', [
            'title'         => 'My Account',
            'profile'       => $repo->profile($cid),
            'mapsKey'       => (string) (service('integrationRepository')->config('google_maps')['browser_key'] ?? ''),
            'orders'        => $orders,
            'invoices'      => $invoices,
            'addresses'     => $addresses,
            'notifications' => $repo->notifications((int) session()->get('customer_user_id')),
            'wishlist'      => $wishlist,
            'stats'         => ['orders' => count($orders), 'spent' => $spent, 'addresses' => count($addresses), 'wishlist' => count($wishlist)],
            'activeTab'     => $tab,
        ]);
    }

    /** Update contact details from the dashboard Profile tab. */
    public function updateProfile(): RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        $name  = trim((string) $this->request->getPost('name'));
        $email = trim((string) $this->request->getPost('email'));
        $phone = trim((string) $this->request->getPost('phone'));
        if ($name === '') {
            return redirect()->to('store/account?tab=profile')->with('error', 'Name is required.');
        }
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return redirect()->to('store/account?tab=profile')->with('error', 'Enter a valid email address.');
        }
        if ($phone !== '' && ! preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
            return redirect()->to('store/account?tab=profile')->with('error', 'Enter a valid phone number.');
        }
        $ok = service('storeCustomerRepository')->updateProfile($this->customerId(), $name, $email, $phone);
        if ($ok) {
            session()->set('customer_name', $name);
        }

        return redirect()->to('store/account?tab=profile')->with($ok ? 'success' : 'error',
            $ok ? 'Profile updated.' : 'Could not update — that email may already be in use.');
    }

    public function orders(): string|RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }

        // X4 — invoice download links per order (map: order_no => invoices[])
        $invoices = [];
        try {
            // Ensure invoices exist for the customer's delivered/completed sub-orders.
            foreach (service('invoiceRepository')->pendingInvoiceSubOrdersForCustomer((int) $this->customerId()) as $so) {
                service('invoiceService')->ensureForSubOrder((int) $so['id'], null, (string) ($so['status'] ?? ''));
            }
            foreach (service('invoiceRepository')->listForCustomer((int) $this->customerId()) as $inv) {
                $invoices[(string) $inv['order_no']][] = $inv;
            }
        } catch (\Throwable) {
        }

        return view('store/account/orders', [
            'title'    => 'My Orders',
            'orders'   => service('storeOrderRepository')->forCustomer($this->customerId()),
            'invoices' => $invoices,
        ]);
    }

    /** X2c — download my invoice PDF (ownership-checked, access-logged). */
    public function invoicePdf(int $id)
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        if (! service('invoiceRepository')->customerOwns($id, (int) $this->customerId())) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Invoice not found');
        }
        $res = service('documentPdfService')->invoicePdf($id, (int) session()->get('customer_user_id'));
        if (! ($res['ok'] ?? false)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Invoice not found');
        }
        try {
            \Config\Database::connect()->table('data_access_logs')->insert([
                'actor_user_id' => (int) session()->get('customer_user_id'),
                'access_type'   => 'export', 'entity_type' => 'invoice',
                'scope_type'    => 'self', 'scope_id' => $this->customerId(), 'record_count' => 1,
            ]);
        } catch (\Throwable) {
        }

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $res['filename'] . '"')
            ->setBody($res['bytes']);
    }

    public function cancelOrder(string $orderNo): RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        $res = service('storeOrderRepository')->cancel($orderNo, $this->customerId());

        return redirect()->to('store/account?tab=orders')->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Order cancelled. Any payment will be refunded.' : ($res['reason'] ?? 'Could not cancel.'));
    }

    /** Cancel a single product (one sub-order/bill) within an order. */
    public function cancelOrderItem(string $orderNo, int $subOrderId): RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        $res = service('storeOrderRepository')->cancelSubOrder($orderNo, $subOrderId, (int) $this->customerId());

        return redirect()->to('store/account/orders/' . $orderNo)->with($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Product cancelled. Any payment for it will be refunded.' : ($res['reason'] ?? 'Could not cancel the product.'));
    }

    /**
     * Build the order-detail view data (timeline flags + per-product invoices),
     * or null if the order isn't the customer"s. Shared by the full page + modal.
     * @return array<string,mixed>|null
     */
    private function orderDetailData(string $orderNo): ?array
    {
        $order = service('storeOrderRepository')->detailForCustomer($orderNo, (int) $this->customerId());
        if ($order === null) {
            return null;
        }
        $vertical  = (string) ($order['vertical'] ?? 'goods');
        $subStat   = array_column($order['sub_orders'], 'status');
        $lock      = \App\Libraries\Store\Vertical::cancelLockStatuses($vertical);
        $canCancel = in_array($order['status'], ['created', 'confirmed'], true) && array_intersect($subStat, $lock) === [];

        $deliveredAt = null;
        foreach ($order['sub_orders'] as $so) {
            if (in_array($so['status'], ['delivered', 'completed'], true)) {
                $t = strtotime((string) ($so['updated_at'] ?? ''));
                if ($t && ($deliveredAt === null || $t > $deliveredAt)) {
                    $deliveredAt = $t;
                }
            }
        }
        $windowDays = \App\Libraries\Store\Vertical::returnWindowDays($vertical);
        $canReturn  = \App\Libraries\Store\Vertical::canReturn($vertical)
            && $deliveredAt !== null && (time() - $deliveredAt) <= $windowDays * 86400;

        // Per-product bills: ensure an invoice exists for each invoiceable sub-order, map by sub_order_id.
        $invoices = [];
        try {
            foreach ($order['sub_orders'] as $so) {
                if (in_array($so['status'], \App\Libraries\Invoicing\InvoiceService::INVOICEABLE, true)) {
                    service('invoiceService')->ensureForSubOrder((int) $so['id'], null, (string) $so['status']);
                }
            }
            foreach (service('invoiceRepository')->listForCustomerOrder($orderNo, (int) $this->customerId()) as $inv) {
                $invoices[(int) $inv['sub_order_id']] = $inv;
            }
        } catch (\Throwable) {
        }

        return [
            'order'      => $order,
            'orderNo'    => $orderNo,
            'vertical'   => $vertical,
            'canCancel'  => $canCancel,
            'canReturn'  => $canReturn,
            'windowDays' => $windowDays,
            'invoices'   => $invoices,
        ];
    }

    /** Order detail with a status timeline + per-product cancel/return actions (full page). */
    public function orderDetail(string $orderNo): string|RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        $data = $this->orderDetailData($orderNo);
        if ($data === null) {
            return redirect()->to('store/account?tab=orders')->with('error', 'Order not found.');
        }
        $data['title'] = 'Order ' . $orderNo;
        $data['modal'] = false;

        return view('store/account/order_detail', $data);
    }

    /** Order detail as an HTML fragment for the dashboard modal (no layout). */
    public function orderFragment(string $orderNo): string|RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        $data = $this->orderDetailData($orderNo);
        if ($data === null) {
            return $this->response->setStatusCode(404)->setBody('Order not found.');
        }
        $data['modal'] = true;

        return view('partials/_store_order_detail', $data);
    }

    /** Customer return request — per-item quantities + reason. */
    public function submitOrderReturn(string $orderNo): RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        $reason = trim((string) $this->request->getPost('reason'));
        $items  = [];
        foreach ((array) $this->request->getPost('qty') as $oiId => $q) {
            if ((int) $q > 0) {
                $items[(int) $oiId] = (int) $q;
            }
        }
        if ($items === []) {
            return redirect()->to('store/account/orders/' . $orderNo)->with('error', 'Select at least one item and quantity to return.');
        }
        $res = service('storeOrderRepository')->createReturn($orderNo, (int) $this->customerId(), $items, $reason);

        return redirect()->to('store/account/orders/' . $orderNo)->with($res['ok'] ? 'success' : 'error',
            $res['ok'] ? "Return requested — we\'ll review it shortly." : ($res['reason'] ?? 'Could not submit the return.'));
    }

    public function addresses(): string|RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }

        return view('store/account/addresses', [
            'title'     => 'My Addresses',
            'addresses' => service('storeCustomerRepository')->addresses($this->customerId()),
        ]);
    }

    public function addAddress(): RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        service('storeCustomerRepository')->addAddress($this->customerId(), $this->request->getPost());

        return redirect()->to('store/account?tab=addresses')->with('success', 'Address saved.');
    }

    public function deleteAddress(int $id): RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        service('storeCustomerRepository')->deleteAddress($id, $this->customerId());

        return redirect()->to('store/account?tab=addresses')->with('success', 'Address removed.');
    }

    /** Address form as an AJAX fragment for the account add/edit modal. */
    public function addressForm(): string|RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        if (! $this->request->isAJAX()) {
            return redirect()->to('store/account?tab=addresses');
        }
        $cid = (int) $this->customerId();
        $id  = (int) $this->request->getGet('id');
        $e   = $id > 0 ? service('storeCustomerRepository')->address($id, $cid) : null;

        return view('partials/_store_address_form', [
            'action'      => site_url('store/account/address/save'),
            'e'           => $e ?? [],
            'profile'     => service('storeCustomerRepository')->profile($cid),
            'mapsKey'     => (string) (service('integrationRepository')->config('google_maps')['browser_key'] ?? ''),
            'submitLabel' => $e ? 'Save changes' : 'Save Address',
            'formAttrs'   => 'data-ajax-refresh='#region-addresses'',
        ]);
    }

    /** Save an address from the map-based modal form (account Addresses tab). */
    public function saveMapAddress(): RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        $cid = (int) $this->customerId();
        $req = $this->request;

        $latRaw   = trim((string) $req->getPost('lat'));
        $lngRaw   = trim((string) $req->getPost('lng'));
        $lat      = $latRaw !== '' ? (float) $latRaw : null;
        $lng      = $lngRaw !== '' ? (float) $lngRaw : null;
        $floor    = trim((string) $req->getPost('floor'));
        $landmark = trim((string) $req->getPost('landmark'));
        $line2    = implode(', ', array_filter([$floor !== '' ? 'Floor ' . $floor : '', $landmark]));

        $data = [
            'label'             => in_array($req->getPost('label'), ['Home', 'Work', 'Hotel', 'Other'], true) ? (string) $req->getPost('label') : 'Home',
            'recipient_name'    => trim((string) $req->getPost('name')),
            'phone'             => trim((string) $req->getPost('phone')),
            'line1'             => trim((string) $req->getPost('line1')),
            'line2'             => $line2,
            'city'              => trim((string) $req->getPost('city')),
            'state_code'        => trim((string) $req->getPost('state_code')),
            'pincode'           => trim((string) $req->getPost('pincode')),
            'formatted_address' => trim((string) $req->getPost('formatted')),
            'latitude'          => $lat,
            'longitude'         => $lng,
            'is_default'        => $req->getPost('is_default') ? 1 : 0,
        ];
        if ($lat === null || $lng === null) {
            return redirect()->to('store/account?tab=addresses')->with('error', "Set your delivery location — use 'Go to current location' or the map.");
        }
        if ($data['line1'] === '' || $data['recipient_name'] === '') {
            return redirect()->to('store/account?tab=addresses')->with('error', 'Add the flat / house / building and your name.');
        }
        if ($data['phone'] !== '' && ! preg_match('/^\+?[0-9]{10,15}$/', $data['phone'])) {
            return redirect()->to('store/account?tab=addresses')->with('error', 'Enter a valid phone number.');
        }

        $repo = service('storeCustomerRepository');
        $id   = (int) $req->getPost('address_id');
        if ($id > 0 && $repo->address($id, $cid) !== null) {
            $repo->updateAddress($id, $cid, $data);
        } else {
            $repo->addAddress($cid, $data);
        }

        return redirect()->to('store/account?tab=addresses')->with('success', 'Address saved.');
    }

    public function notifications(): string|RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }

        return view('store/account/notifications', [
            'title'         => 'Notifications',
            'notifications' => service('storeCustomerRepository')->notifications((int) session()->get('customer_user_id')),
        ]);
    }

    public function support(): string|RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }

        return view('store/account/support', [
            'title'  => 'Support & Returns',
            'orders' => service('storeOrderRepository')->forCustomer($this->customerId()),
        ]);
    }

    public function submitReturn(): RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        $orderNo = trim((string) $this->request->getPost('order_no'));
        $reason  = trim((string) $this->request->getPost('reason'));
        if ($orderNo === '' || $reason === '') {
            return redirect()->to('store/account?tab=support')->with('error', 'Select an order and describe the issue.');
        }
        service('storeCustomerRepository')->createReturnRequest($this->customerId(), (int) session()->get('customer_user_id'), $orderNo, $reason);

        return redirect()->to('store/account?tab=support')->with('success', 'Return/refund request submitted.');
    }

    public function submitReview(int $productId): RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        service('storeCustomerRepository')->submitReview(
            $this->customerId(),
            $productId,
            (int) $this->request->getPost('rating'),
            trim((string) $this->request->getPost('title')),
            trim((string) $this->request->getPost('body')),
        );

        return redirect()->back()->with('success', 'Review submitted — pending moderation.');
    }

    /** Phase 3 — rate a delivered trip (1–5 stars + optional comment). */
    public function rateDelivery(int $deliveryId): RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        $res = service('deliveryTrackingRepository')->rate(
            $deliveryId,
            (int) session()->get('customer_user_id'),
            (int) $this->request->getPost('rating'),
            trim((string) $this->request->getPost('comment')) ?: null,
        );

        return redirect()->back()->with($res['ok'] ? 'success' : 'error', $res['msg']);
    }

    /** Phase 3 — report a problem with a delivery (opens a dispute for admin). */
    public function raiseDispute(int $deliveryId): RedirectResponse
    {
        if ($d = $this->requireCustomer()) {
            return $d;
        }
        $res = service('deliveryTrackingRepository')->raiseDispute(
            $deliveryId,
            (int) session()->get('customer_user_id'),
            (string) $this->request->getPost('reason'),
            trim((string) $this->request->getPost('detail')) ?: null,
        );

        return redirect()->back()->with($res['ok'] ? 'success' : 'error', $res['msg']);
    }
}
