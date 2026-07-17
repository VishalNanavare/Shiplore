<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * CheckoutController — cart, coupons, checkout and order placement. Payment
 * methods are the platform's active gateways (+ COD); placing an order creates
 * the order, per-vendor sub-orders, items and a payment via StoreOrderRepository.
 */
final class CheckoutController extends BaseStoreController
{
    public function cart(): string
    {
        $cart   = service('cartService');
        $items  = $cart->items();
        $coupon = session()->get('store_coupon');

        return view('store/cart', [
            'title'  => 'Your Cart',
            'items'  => $items,
            'coupon' => $coupon,
            'totals' => $cart->totals($items, (float) ($coupon['pct'] ?? 0)),
        ]);
    }

    /** Compact cart body for the slide-over drawer (loaded via fetch). */
    public function miniCart(): string
    {
        $cart   = service('cartService');
        $items  = $cart->items();
        $coupon = session()->get('store_coupon');

        return view('partials/_store_minicart', [
            'items'  => $items,
            'totals' => $cart->totals($items, (float) ($coupon['pct'] ?? 0)),
            'count'  => $cart->count(),
        ]);
    }

    public function addToCart(): \CodeIgniter\HTTP\ResponseInterface
    {
        $variant = (int) $this->request->getPost('variant_id');
        $qty     = max(1, (int) $this->request->getPost('qty'));
        if ($variant <= 0) {
            return redirect()->to('store/cart');
        }

        return $this->doAdd($variant, $qty);
    }

    /** Validate resulting qty (rules + managed stock), then add. */
    private function doAdd(int $variant, int $qty): RedirectResponse
    {
        $already = (int) (service('cartService')->raw()[$variant] ?? 0);
        if ($err = $this->qtyError($variant, $already + $qty)) {
            return redirect()->back()->with('error', $err);
        }
        service('cartService')->add($variant, $qty);

        return redirect()->to('store/cart')->with('success', 'Added to cart.');
    }

    public function updateCart(): RedirectResponse
    {
        $variant = (int) $this->request->getPost('variant_id');
        $qty     = (int) $this->request->getPost('qty');
        if ($variant <= 0) {
            return redirect()->to('store/cart');
        }
        if ($qty <= 0) {
            service('cartService')->remove($variant);

            return redirect()->to('store/cart')->with('success', 'Item removed.');
        }
        // updateCart sets an ABSOLUTE quantity — validate it the same way as add.
        if ($err = $this->qtyError($variant, $qty)) {
            return redirect()->back()->with('error', $err);
        }
        service('cartService')->setQty($variant, $qty);

        return redirect()->to('store/cart');
    }

    /**
     * Validate a final (resulting) quantity for a variant against purchase rules
     * (min/max/step) and managed-inventory stock. Returns an error message, or
     * null when the quantity is acceptable. Single source of truth for cart + checkout.
     */
    private function qtyError(int $variantId, int $finalQty): ?string
    {
        $catalog = service('storeCatalogRepository');

        $rules = $catalog->purchaseRulesForVariant($variantId);
        $check = \App\Libraries\Catalog\PurchaseRules::validate((float) $finalQty, $rules);
        if (! $check['ok']) {
            return $check['message'];
        }

        // Managed inventory (no backorder) caps the quantity at what's in stock.
        $stock = $catalog->variantStock($variantId);
        if ($stock['mode'] === 'managed' && ! $stock['backorder'] && $stock['tracked'] && $finalQty > $stock['available']) {
            $avail = (int) floor($stock['available']);

            return $avail > 0 ? ('Only ' . $avail . ' left in stock.') : 'This item is out of stock.';
        }

        return null;
    }

    public function removeCart(): RedirectResponse
    {
        service('cartService')->remove((int) $this->request->getPost('variant_id'));

        return redirect()->to('store/cart');
    }

    public function applyCoupon(): RedirectResponse
    {
        $cart     = service('cartService');
        $subtotal = (float) $cart->totals($cart->items())['subtotal'];
        $res      = service('storeCouponRepository')->validate((string) $this->request->getPost('code'), $subtotal, $this->customerId());
        if ($res['ok']) {
            session()->set('store_coupon', ['code' => $res['code'], 'pct' => $res['pct']]);

            return redirect()->to('store/cart')->with('success', $res['message']);
        }
        session()->remove('store_coupon');

        return redirect()->to('store/cart')->with('error', $res['message']);
    }

    // ---- Stepped checkout: choose address → add address (map) → payment ----

    /** STEP 1 — choose a saved delivery address (or add a new one). */
    public function checkout(): string|RedirectResponse
    {
        $cart = service('cartService');
        if ($cart->items() === []) {
            return redirect()->to('store/cart')->with('error', 'Your cart is empty.');
        }
        if ($gate = $this->requireCustomerFor('store/checkout', 'Sign in or sign up to place your order.')) {
            return $gate;
        }
        $cid       = (int) $this->customerId();
        $addresses = service('storeCustomerRepository')->addresses($cid);
        // No saved addresses yet → go straight to the map/add-address step (Blinkit).
        if ($addresses === []) {
            return redirect()->to('store/checkout/add-address');
        }

        return view('store/checkout_address', [
            'title'     => 'Select delivery address',
            'addresses' => $addresses,
            'location'  => service('locationService')->get(),
            'count'     => $cart->count(),
            'mapsKey'   => (string) (service('integrationRepository')->config('google_maps')['browser_key'] ?? ''),
        ]);
    }

    /** Address form as an AJAX fragment for the checkout add/edit modal. */
    public function addressForm(): string|RedirectResponse
    {
        if (service('cartService')->items() === []) {
            return redirect()->to('store/cart');
        }
        if ($gate = $this->requireCustomerFor('store/checkout', 'Sign in or sign up to place your order.')) {
            return $gate;
        }
        if (! $this->request->isAJAX()) {
            // Non-JS: fall back to the full add-address page.
            $id = (int) $this->request->getGet('id');
            return redirect()->to('store/checkout/add-address' . ($id > 0 ? '?id=' . $id : ''));
        }
        $cid = (int) $this->customerId();
        $id  = (int) $this->request->getGet('id');
        $e   = $id > 0 ? service('storeCustomerRepository')->address($id, $cid) : null;

        return view('partials/_store_address_form', [
            'action'      => site_url('store/checkout/save-address'),
            'e'           => $e ?? [],
            'profile'     => service('storeCustomerRepository')->profile($cid),
            'mapsKey'     => (string) (service('integrationRepository')->config('google_maps')['browser_key'] ?? ''),
            'submitLabel' => $e ? 'Save &amp; deliver here' : 'Save &amp; continue',
            'formAttrs'   => '',
        ]);
    }

    /** Delete a saved address from the checkout address picker. */
    public function deleteAddress(int $id): RedirectResponse
    {
        if ($gate = $this->requireCustomerFor('store/checkout', 'Sign in or sign up to place your order.')) {
            return $gate;
        }
        service('storeCustomerRepository')->deleteAddress($id, (int) $this->customerId());

        return redirect()->to('store/checkout')->with('success', 'Address removed.');
    }

    /** STEP 2 (GET) — map + complete-address form (add or edit). */
    public function addAddressForm(): string|RedirectResponse
    {
        if (service('cartService')->items() === []) {
            return redirect()->to('store/cart')->with('error', 'Your cart is empty.');
        }
        if ($gate = $this->requireCustomerFor('store/checkout/add-address', 'Sign in to add an address.')) {
            return $gate;
        }
        $cid  = (int) $this->customerId();
        $edit = null;
        $id   = (int) $this->request->getGet('id');
        if ($id > 0) {
            $edit = service('storeCustomerRepository')->address($id, $cid);
        }

        return view('store/checkout_add_address', [
            'title'   => $edit ? 'Edit address' : 'Add a new address',
            'edit'    => $edit,
            'profile' => service('storeCustomerRepository')->profile($cid),
            'mapsKey' => (string) (service('integrationRepository')->config('google_maps')['browser_key'] ?? ''),
        ]);
    }

    /** STEP 2 (POST) — persist the address, set it as the delivery point, go to payment. */
    public function saveAddress(): RedirectResponse
    {
        if (service('cartService')->items() === []) {
            return redirect()->to('store/cart')->with('error', 'Your cart is empty.');
        }
        if ($gate = $this->requireCustomerFor('store/checkout/add-address', 'Sign in to add an address.')) {
            return $gate;
        }
        $cid = (int) $this->customerId();
        $req = $this->request;

        // Coordinates are keyed on PRESENCE of the raw input (not float value) so a
        // genuine 0.0 coordinate is valid and a half-filled pair is rejected.
        $latRaw   = trim((string) $req->getPost('lat'));
        $lngRaw   = trim((string) $req->getPost('lng'));
        $lat      = $latRaw !== '' ? (float) $latRaw : null;
        $lng      = $lngRaw !== '' ? (float) $lngRaw : null;
        $floor    = trim((string) $req->getPost('floor'));
        $landmark = trim((string) $req->getPost('landmark'));
        $line2    = implode(', ', array_filter([$floor !== '' ? 'Floor ' . $floor : '', $landmark]));

        $d = [
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

        // A delivery point is mandatory (drives serviceability). Both coords required;
        // obtainable via the map OR "Go to current location" (works without a Maps key).
        if ($lat === null || $lng === null) {
            return redirect()->back()->withInput()->with('error', "Set your delivery location — use 'Go to current location' or pick it on the map.");
        }
        if ($d['line1'] === '' || $d['recipient_name'] === '') {
            return redirect()->back()->withInput()->with('error', 'Add the flat / house / building and your name.');
        }
        if ($d['phone'] !== '' && ! preg_match('/^\+?[0-9]{10,15}$/', $d['phone'])) {
            return redirect()->back()->withInput()->with('error', 'Enter a valid phone number.');
        }

        $repo = service('storeCustomerRepository');
        $id   = (int) $req->getPost('address_id');
        if ($id > 0 && $repo->address($id, $cid) !== null) {
            $repo->updateAddress($id, $cid, $d);
            $saved = $repo->address($id, $cid);
        } else {
            $newId = $repo->addAddress($cid, $d);
            $saved = $newId ? $repo->address((int) $newId, $cid) : null;
        }
        if ($saved === null) {
            return redirect()->back()->withInput()->with('error', 'Could not save the address. Please try again.');
        }

        $removed = $this->setDeliveryFromAddress($saved);
        $msg     = 'Delivering to ' . ($saved['label'] ?: 'your address') . '.';
        if ($removed !== []) {
            $msg .= ' ' . \App\Libraries\Store\DeliveryMessages::removedSummary($removed);
        }
        // HR4: if pruning emptied the cart, send the customer back to the empty cart.
        if (service('cartService')->items() === []) {
            return redirect()->to('store/cart')->with('error', $msg . ' Your cart is now empty.');
        }

        return redirect()->to('store/checkout/payment')->with('success', $msg);
    }

    /** STEP 1 action — pick a saved address as the delivery point, go to payment. */
    public function useAddress(int $id): RedirectResponse
    {
        if (service('cartService')->items() === []) {
            return redirect()->to('store/cart')->with('error', 'Your cart is empty.');
        }
        if ($gate = $this->requireCustomerFor('store/checkout', 'Sign in or sign up to place your order.')) {
            return $gate;
        }
        $a = service('storeCustomerRepository')->address($id, (int) $this->customerId());
        if ($a === null) {
            return redirect()->to('store/checkout')->with('error', 'Address not found.');
        }
        if ($a['latitude'] === null || $a['longitude'] === null) {
            return redirect()->to('store/checkout/add-address?id=' . $id)->with('error', 'Pin this address on the map to deliver here.');
        }
        $removed = $this->setDeliveryFromAddress($a);
        $msg     = 'Delivering to ' . ($a['label'] ?: 'your address') . '.';
        if ($removed !== []) {
            $msg .= ' ' . \App\Libraries\Store\DeliveryMessages::removedSummary($removed);
        }
        // HR4: if pruning emptied the cart, send the customer back to the empty cart.
        if (service('cartService')->items() === []) {
            return redirect()->to('store/cart')->with('error', $msg . ' Your cart is now empty.');
        }

        return redirect()->to('store/checkout/payment')->with('success', $msg);
    }

    /** STEP 3 — payment method + final review of the chosen address. */
    public function payment(): string|RedirectResponse
    {
        $cart  = service('cartService');
        $items = $cart->items();
        if ($items === []) {
            return redirect()->to('store/cart')->with('error', 'Your cart is empty.');
        }
        if ($gate = $this->requireCustomerFor('store/checkout/payment', 'Sign in or sign up to place your order.')) {
            return $gate;
        }
        $loc = service('locationService')->get();
        if (! session()->get('checkout_addr_ready') || $loc === null || ($loc['lat'] ?? null) === null || ($loc['line1'] ?? '') === '') {
            return redirect()->to('store/checkout')->with('error', 'Choose a delivery address to continue.');
        }

        $coupon = session()->get('store_coupon');
        $token  = bin2hex(random_bytes(8));
        session()->set('checkout_token', $token);

        return view('store/checkout', [
            'title'         => 'Payment',
            'items'         => $items,
            'totals'        => $cart->totals($items, (float) ($coupon['pct'] ?? 0)),
            'coupon'        => $coupon,
            'gateways'      => service('paymentGatewayRepository')->active('online'),
            'gwLabels'      => service('paymentGatewayManager')->availableProviders(),
            'checkoutToken' => $token,
            'location'      => $loc,
            'undeliverable' => $this->undeliverableItems($items, (float) $loc['lat'], (float) $loc['lng']),
        ]);
    }

    /** STEP 3 (POST) — place the order using the chosen delivery address. */
    public function place(): RedirectResponse
    {
        $cart  = service('cartService');
        $items = $cart->items();
        if ($items === []) {
            return redirect()->to('store/cart')->with('error', 'Your cart is empty.');
        }
        if ($gate = $this->requireCustomerFor('store/checkout/payment', 'Sign in or sign up to place your order.')) {
            return $gate;
        }
        $loc = service('locationService')->get();
        if (! session()->get('checkout_addr_ready') || $loc === null || ($loc['lat'] ?? null) === null) {
            return redirect()->to('store/checkout')->with('error', 'Choose a delivery address to continue.');
        }
        $cid     = (int) $this->customerId();
        $profile = service('storeCustomerRepository')->profile($cid) ?? [];
        $lat     = (float) $loc['lat'];
        $lng     = (float) $loc['lng'];

        // Delivery address is the one chosen at steps 1/2 (session) + the account
        // email; no address is re-collected on the payment page.
        $addr = [
            'name'       => ($loc['name'] ?? '') ?: (string) ($profile['name'] ?? ''),
            'email'      => (string) ($profile['email'] ?? ''),
            'phone'      => ($loc['phone'] ?? '') ?: (string) ($profile['phone'] ?? ''),
            'line1'      => (string) ($loc['line1'] ?? ''),
            'city'       => (string) ($loc['city'] ?? ''),
            'state_code' => ((string) ($loc['state_code'] ?? '')) ?: '27',
            'pincode'    => (string) ($loc['pincode'] ?? ''),
            'lat'        => $lat,
            'lng'        => $lng,
            'formatted'  => $loc['formatted'] ?? null,
        ];
        $method = (string) $this->request->getPost('method') ?: 'cod';

        foreach (['name', 'line1', 'city', 'pincode'] as $req) {
            if ($addr[$req] === '') {
                return redirect()->to('store/checkout')->with('error', 'Your delivery address is incomplete — please re-select it.');
            }
        }
        if (! preg_match('/^[0-9]{6}$/', $addr['pincode'])) {
            return redirect()->to('store/checkout/add-address')->with('error', 'Pincode must be 6 digits.');
        }
        if ($addr['email'] !== '' && ! filter_var($addr['email'], FILTER_VALIDATE_EMAIL)) {
            return redirect()->to('store/checkout/payment')->with('error', 'Your account email is invalid.');
        }

        // per-product payment restrictions + qty/stock + deliverability (defense-in-depth).
        $catalog = service('storeCatalogRepository');
        foreach ($items as $line) {
            $vid   = (int) ($line['variant_id'] ?? 0);
            $rules = $catalog->purchaseRulesForVariant($vid);
            if (! \App\Libraries\Catalog\PurchaseRules::paymentAllowed($method, (string) ($rules['payment_restriction'] ?? 'both'))) {
                return redirect()->to('store/checkout/payment')->with('error', "One or more items don't allow the selected payment method. Please choose another.");
            }
            if ($err = $this->qtyError($vid, (int) ($line['qty'] ?? 0))) {
                return redirect()->to('store/cart')->with('error', "'" . ($line['title'] ?? 'An item') . "': " . $err);
            }
            // HR5: deliverability is the final gate before any payment path.
            $d = $catalog->variantDeliverability($vid, $lat, $lng);
            if (! $d['deliverable']) {
                return redirect()->to('store/checkout/payment')->with('error', "'" . ($line['title'] ?? 'An item') . "' — " . \App\Libraries\Store\DeliveryMessages::reasonText((string) $d['reason']) . ' Remove it or change the address.');
            }
        }
        // double-submit guard: consume the one-time token issued on the payment page
        $token = (string) $this->request->getPost('checkout_token');
        if ($token === '' || $token !== (string) session()->get('checkout_token')) {
            return redirect()->to('store/checkout/payment')->with('error', 'Your checkout session expired — please try again.');
        }
        session()->remove('checkout_token');

        // Re-validate the coupon server-side at order time — drop it if no longer valid.
        $coupon = session()->get('store_coupon');
        $pct    = 0.0;
        if (is_array($coupon) && ! empty($coupon['code'])) {
            $subtotal = (float) $cart->totals($items)['subtotal'];
            $recheck  = service('storeCouponRepository')->validate((string) $coupon['code'], $subtotal, $cid);
            if ($recheck['ok']) {
                $pct = (float) $recheck['pct'];
            } else {
                $coupon = null;
                session()->remove('store_coupon');
            }
        }
        $totals  = $cart->totals($items, $pct);
        $orderNo = service('storeOrderRepository')->place($cid, $items, $addr, $totals, $method, $coupon['code'] ?? null, random_int(100000, 999999));

        if ($orderNo === null) {
            return redirect()->to('store/checkout/payment')->with('error', 'Could not place your order. Please try again.');
        }

        $cart->clear();
        session()->remove(['store_coupon', 'checkout_addr_ready']);
        $this->rememberOrder($orderNo);

        // Online (Razorpay): placed as PENDING — hand off to the gateway pay page.
        if ($method === 'razorpay' && service('razorpayClient')->configured()) {
            return redirect()->to('store/pay/' . $orderNo);
        }

        return redirect()->to('store/order/' . $orderNo)->with('success', 'Order placed successfully!');
    }

    /**
     * Set the session delivery point from a saved address; returns the pruned items.
     * @return list<array{variant_id:int, shop_id:?int, title:string, reason:string}>
     */
    private function setDeliveryFromAddress(array $a): array
    {
        $street = trim((string) ($a['line1'] ?? '') . (($a['line2'] ?? '') !== '' ? ', ' . $a['line2'] : ''), ', ');
        $full   = ($a['formatted_address'] ?? '') !== '' ? (string) $a['formatted_address']
            : trim($street . ', ' . (string) ($a['city'] ?? '') . ' ' . (string) ($a['pincode'] ?? ''), ' ,');
        service('locationService')->set(
            (float) $a['latitude'],
            (float) $a['longitude'],
            (string) ($a['label'] ?: 'Delivery'),
            ($a['pincode'] ?? '') !== '' ? (string) $a['pincode'] : null,
            [
                'city'       => (string) ($a['city'] ?? ''),
                'state_code' => (string) ($a['state_code'] ?? ''),
                'line1'      => $street,
                'formatted'  => $full,
                'name'       => (string) ($a['recipient_name'] ?? ''),
                'phone'      => (string) ($a['phone'] ?? ''),
            ]
        );
        session()->set('checkout_addr_ready', true);

        return service('cartService')->removeUndeliverable((float) $a['latitude'], (float) $a['longitude']);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array{variant_id:int, title:string, reason:string, text:string}>
     *         the undeliverable items with canonical reason + ready-to-show text.
     */
    private function undeliverableItems(array $items, float $lat, float $lng): array
    {
        $catalog  = service('storeCatalogRepository');
        $adminMax = service('settingsRepository')->deliveryMaxRadiusKm();
        $out      = [];
        foreach ($items as $it) {
            $d = $catalog->variantDeliverability((int) $it['variant_id'], $lat, $lng, $adminMax);
            if (! $d['deliverable']) {
                $out[] = [
                    'variant_id' => (int) $it['variant_id'],
                    'title'      => (string) ($it['title'] ?? 'Item'),
                    'reason'     => (string) $d['reason'],
                    'text'       => \App\Libraries\Store\DeliveryMessages::reasonText((string) $d['reason']),
                ];
            }
        }

        return $out;
    }

    public function confirmation(string $orderNo): string|RedirectResponse
    {
        if (! $this->ownsOrder($orderNo)) {
            return redirect()->to('store')->with('error', 'Order not found.');
        }

        return view('store/order', [
            'title'   => 'Order ' . $orderNo,
            'order'   => service('storeOrderRepository')->track($orderNo),
            'orderNo' => $orderNo,
        ]);
    }

    public function trackForm(): string
    {
        $orderNo = trim((string) $this->request->getGet('order_no'));
        // Only surface an order the visitor actually owns (no enumerating others').
        $order   = ($orderNo !== '' && $this->ownsOrder($orderNo)) ? service('storeOrderRepository')->track($orderNo) : null;

        return view('store/track', [
            'title'    => 'Track Order',
            'orderNo'  => $orderNo,
            'order'    => $order,
            'notFound' => $orderNo !== '' && $order === null,
        ]);
    }

    /** Phase 3 — live delivery tracking for one order (status, rider, ETA, GPS). */
    public function track(string $orderNo): string|RedirectResponse
    {
        $customerUserId = session()->get('customer_user_id');
        $tracking = service('deliveryTrackingRepository')->forCustomerOrder($orderNo, $customerUserId ? (int) $customerUserId : null);
        if ($tracking === null) {
            return redirect()->to('store/track')->with('error', 'We could not find that order number.');
        }

        return view('store/track_order', [
            'title'    => 'Tracking ' . $orderNo,
            'orderNo'  => $orderNo,
            'tracking' => $tracking,
        ]);
    }
}
