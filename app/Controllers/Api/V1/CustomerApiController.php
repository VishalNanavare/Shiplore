<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\Catalog\PurchaseRules;
use App\Libraries\Store\CartService;

/**
 * CustomerApiController — Customer app API: location-aware home/shops, catalog
 * browse/search, product detail (with variants), order placement and tracking.
 *
 * Ordering parity with the web checkout (app/Controllers/Store/CheckoutController):
 * placing an order REQUIRES a customer JWT, a map delivery location (lat/lng), a
 * complete address, and every cart line must pass per-item payment/qty/stock and
 * deliverability gates before the order is created. Browse endpoints are public.
 *
 * @see docs/architecture/26-CUSTOMER-APP.md, app/Controllers/Store/CheckoutController.php
 */
final class CustomerApiController extends BaseApiController
{
    public function home()
    {
        $lat  = $this->floatOrNull($this->request->getGet('lat'));
        $lng  = $this->floatOrNull($this->request->getGet('lng'));
        $opts = ['limit' => 18, 'sort' => 'newest'];
        if ($lat !== null && $lng !== null) {
            $opts['shop_ids'] = service('storeShopRepository')->nearbyShopIds($lat, $lng);
            $opts['bucket']   = \App\Libraries\Store\GeoBucket::keyFor($lat, $lng);
        }

        // Top-level categories that actually have products in scope (cnt>0 → hides empty),
        // most-stocked first. Reads the cached, bucket-scoped tree (never aggregates live).
        $catOpts  = isset($opts['bucket']) ? ['bucket' => $opts['bucket']] : [];
        $tree     = service('storeCatalogRepository')->categoryTreeWithCounts($catOpts);
        $roots    = array_values(array_filter($tree, static fn ($c) => $c['parent_id'] === null && (int) $c['cnt'] > 0));
        usort($roots, static fn ($a, $b) => (int) $b['cnt'] <=> (int) $a['cnt']);
        $homeCats = array_slice($roots, 0, 10);

        return $this->ok([
            'categories' => $homeCats,
            'shops'      => service('storeShopRepository')->nearby($lat, $lng, 6),
            'products'   => service('storeCatalogRepository')->products($opts),
            'banners'    => service('bannerRepository')->activeForHome(6),
            'location'   => ['lat' => $lat, 'lng' => $lng, 'scoped' => $lat !== null && $lng !== null],
        ]);
    }

    /** Full active category tree (id, name, slug, parent_id, level, cnt) for the Categories tab. */
    public function categories()
    {
        return $this->ok(service('storeCatalogRepository')->categoryTreeWithCounts());
    }

    /** GET /customer/brands?category={slug} — active brands with product counts (for app filter sheet). */
    public function brands()
    {
        $category = trim((string) $this->request->getGet('category'));
        $opts     = $category !== '' ? ['category' => $category] : [];

        return $this->ok(service('storeCatalogRepository')->computeBrandFacets($opts));
    }

    /** Categories that have products in a given shop — for the shop page's left rail. */
    public function shopCategories()
    {
        $shopId = (int) $this->request->getGet('shop_id');
        if ($shopId <= 0) {
            return $this->ok([]);
        }

        return $this->ok(service('storeCatalogRepository')->categoryFacets(['shop_ids' => [$shopId]], 40));
    }

    public function shops()
    {
        $lat   = $this->floatOrNull($this->request->getGet('lat'));
        $lng   = $this->floatOrNull($this->request->getGet('lng'));
        $items = service('storeShopRepository')->nearby($lat, $lng, 40);

        return $this->collection($items, ['total' => count($items)]);
    }

    public function products()
    {
        $lat   = $this->floatOrNull($this->request->getGet('lat'));
        $lng   = $this->floatOrNull($this->request->getGet('lng'));
        $page  = max(1, (int) $this->request->getGet('page'));
        $per   = min(60, max(1, (int) ($this->request->getGet('per_page') ?: 24)));
        $brand = $this->request->getGet('brand');

        $opts = [
            'q'        => (string) $this->request->getGet('q'),
            'category' => (string) $this->request->getGet('category'),
            'brand'    => is_numeric($brand) ? (int) $brand : '',
            'sort'     => (string) $this->request->getGet('sort'),
            'in_stock' => $this->request->getGet('in_stock') ? 1 : 0,
            'on_offer' => $this->request->getGet('on_offer') ? 1 : 0,
            'limit'    => $per,
            'page'     => $page,
        ];
        // A specific shop (store page) overrides location scoping; vendor filters
        // "more from this store" on product detail.
        $shopId   = (int) $this->request->getGet('shop_id');
        $vendorId = (int) $this->request->getGet('vendor_id');
        if ($shopId > 0) {
            $opts['shop_ids'] = [$shopId];
        } elseif ($lat !== null && $lng !== null) {
            $opts['shop_ids'] = service('storeShopRepository')->nearbyShopIds($lat, $lng);
            $opts['bucket']   = \App\Libraries\Store\GeoBucket::keyFor($lat, $lng);
        }
        if ($vendorId > 0) {
            $opts['vendor_id'] = $vendorId;
        }

        $repo  = service('storeCatalogRepository');
        $items = $repo->products($opts);
        $total = $repo->countProducts($opts);

        return $this->collection($items, [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil($total / $per),
        ]);
    }

    public function product(string $slug)
    {
        $repo    = service('storeCatalogRepository');
        $product = $repo->findBySlug($slug);
        if ($product === null) {
            return $this->failWith('NOT_FOUND', 'Product not found.');
        }

        $pid      = (int) $product['id'];
        $variants = $repo->variants($pid);

        return $this->ok([
            'product'        => $product,
            'variants'       => $variants,
            'variant_count'  => count($variants),
            'images'         => service('mediaRepository')->forProduct($pid),
            'content'        => $repo->content($pid),
            'specifications' => $repo->specifications($pid),
            'reviews'        => $repo->reviews($pid),
            'labels'         => $repo->labels($pid),
            'highlights'     => $repo->highlights($pid),
            'faqs'           => $repo->faqs($pid),
            'related'        => $repo->relatedProducts($pid),
        ]);
    }

    /**
     * Place an order. Requires a customer JWT + a map delivery location; mirrors the
     * web checkout's per-item payment/qty/stock + deliverability gates. Supports an
     * optional `idempotency_key` (replays the same order_no for ~5 min) so a retried
     * request never double-charges.
     */
    public function placeOrder()
    {
        $customerId = service('storeCustomerRepository')->customerIdForUser($this->userId());
        if ($customerId === null) {
            return $this->failWith('FORBIDDEN', 'A customer account is required to place an order.');
        }
        $in   = $this->input();
        $idem = trim((string) ($in['idempotency_key'] ?? ''));

        // Resolve the client-held cart (variant_id => qty) into priced lines.
        $cartMap = [];
        foreach ((array) ($in['items'] ?? []) as $line) {
            $vid = (int) ($line['variant_id'] ?? 0);
            $qty = max(1, (int) ($line['qty'] ?? 1));
            if ($vid > 0) {
                $cartMap[$vid] = ($cartMap[$vid] ?? 0) + $qty;
            }
        }
        $lines = service('cartService')->linesFor($cartMap);
        if ($lines === []) {
            return $this->failWith('VALIDATION_ERROR', 'Your cart is empty or the items are no longer available.');
        }

        // A map delivery location is mandatory (parity with web checkout). Fail
        // closed: reject missing OR null-island (0,0) coordinates before any
        // payment path runs (HR2/HR5).
        $lat = $this->floatOrNull($in['lat'] ?? null);
        $lng = $this->floatOrNull($in['lng'] ?? null);
        if ($lat === null || $lng === null || ($lat === 0.0 && $lng === 0.0)) {
            return $this->failWith('VALIDATION_ERROR', 'Pin your delivery address on the map to continue.', ['reason' => 'location_required', 'fields' => ['lat', 'lng']]);
        }

        $profile = service('storeCustomerRepository')->profile($customerId) ?? [];
        $addr    = [
            'name'       => trim((string) ($in['recipient_name'] ?? $in['name'] ?? ($profile['name'] ?? ''))),
            'email'      => trim((string) ($in['email'] ?? ($profile['email'] ?? ''))),
            'phone'      => trim((string) ($in['phone'] ?? ($profile['phone'] ?? ''))),
            'line1'      => trim((string) ($in['line1'] ?? '')),
            'city'       => trim((string) ($in['city'] ?? '')),
            'state_code' => (trim((string) ($in['state_code'] ?? ''))) ?: '27',
            'pincode'    => trim((string) ($in['pincode'] ?? '')),
            'lat'        => $lat,
            'lng'        => $lng,
            'formatted'  => trim((string) ($in['formatted_address'] ?? $in['formatted'] ?? '')) ?: null,
        ];
        $method       = (string) ($in['method'] ?? 'cod') ?: 'cod';
        $tip          = max(0.0, (float) ($in['tip'] ?? 0));
        $gstin        = strtoupper(trim((string) ($in['gstin'] ?? '')));
        $instructions = trim((string) ($in['delivery_instructions'] ?? ''));
        if ($gstin !== '' && ! preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/', $gstin)) {
            return $this->failWith('VALIDATION_ERROR', 'The GSTIN is invalid.', ['field' => 'gstin']);
        }

        foreach (['name', 'line1', 'city', 'pincode'] as $req) {
            if ($addr[$req] === '') {
                return $this->failWith('VALIDATION_ERROR', 'Your delivery address is incomplete.', ['field' => $req]);
            }
        }
        if (! preg_match('/^[0-9]{6}$/', $addr['pincode'])) {
            return $this->failWith('VALIDATION_ERROR', 'Pincode must be 6 digits.', ['field' => 'pincode']);
        }
        if ($addr['email'] !== '' && ! filter_var($addr['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->failWith('VALIDATION_ERROR', 'The email address is invalid.', ['field' => 'email']);
        }
        if ($addr['phone'] !== '' && ! preg_match('/^\+?[0-9]{10,15}$/', $addr['phone'])) {
            return $this->failWith('VALIDATION_ERROR', 'The phone number is invalid.', ['field' => 'phone']);
        }

        // Per-item payment restriction + qty/stock + deliverability (defense-in-depth).
        // This runs BEFORE any payment gateway is initialized (HR5).
        $catalog  = service('storeCatalogRepository');
        $adminMax = service('settingsRepository')->deliveryMaxRadiusKm();
        foreach ($lines as $l) {
            $vid   = (int) ($l['variant_id'] ?? 0);
            $title = (string) ($l['title'] ?? 'An item');
            $rules = $catalog->purchaseRulesForVariant($vid);
            if (! PurchaseRules::paymentAllowed($method, (string) ($rules['payment_restriction'] ?? 'both'))) {
                return $this->failWith('VALIDATION_ERROR', "'" . $title . "' does not allow the '" . $method . "' payment method.", ['variant_id' => $vid, 'reason' => 'payment_restricted']);
            }
            if ($err = $this->qtyError($vid, (int) ($l['qty'] ?? 0))) {
                return $this->failWith('CONFLICT', "'" . $title . "': " . $err, ['variant_id' => $vid, 'reason' => 'qty']);
            }
            $d = $catalog->variantDeliverability($vid, $lat, $lng, $adminMax);
            if (! $d['deliverable']) {
                return $this->failWith(
                    'VALIDATION_ERROR',
                    self::deliveryReasonMessage((string) $d['reason'], $title),
                    ['variant_id' => $vid, 'shop_id' => $d['shop_id'], 'reason' => $d['reason']],
                );
            }
        }

        // Min-order-value gate (mirrors validateCart).
        $subtotal  = (float) service('cartService')->totals($lines)['subtotal'];
        $vendorIds = array_values(array_unique(array_map(static fn ($l) => (int) $l['vendor_id'], $lines)));
        $minVal    = service('storeShopRepository')->minOrderValueForVendors($vendorIds);
        if ($minVal !== null && $subtotal < $minVal) {
            return $this->failWith('VALIDATION_ERROR', 'Minimum order value is ₹' . number_format($minVal, 0) . '. Add more items to proceed.', ['reason' => 'min_order', 'min_order_value' => $minVal]);
        }

        // Re-validate the coupon server-side at order time — drop it if no longer valid.
        $pct    = 0.0;
        $coupon = trim((string) ($in['coupon'] ?? ''));
        if ($coupon !== '') {
            $subtotal = (float) service('cartService')->totals($lines)['subtotal'];
            $recheck  = service('storeCouponRepository')->validate($coupon, $subtotal, $customerId);
            if ($recheck['ok']) {
                $pct = (float) $recheck['pct'];
            } else {
                $coupon = '';
            }
        }
        $totals = service('cartService')->totals($lines, $pct, null, $tip, CartService::HANDLING_FEE);
        $repo   = service('storeOrderRepository');

        // Durable idempotency: claim the key AFTER validation so a retried/concurrent
        // identical request collapses to a single order (UNIQUE-constrained, cache-free).
        if ($idem !== '') {
            $claim = $repo->claimIdempotency($customerId, $idem);
            if ($claim['state'] === 'replay') {
                return $this->respond(\App\Libraries\ApiResponse::success($claim['response'] ?? [], ['replay' => true]), 201);
            }
            if ($claim['state'] === 'in_progress') {
                return $this->failWith('CONFLICT', 'This order is already being placed. Please wait.');
            }
        }

        try {
            $orderNo = $repo->place($customerId, $lines, $addr, $totals, $method, $coupon ?: null, random_int(100000, 999999), $gstin ?: null, $instructions ?: null);
        } catch (\Throwable $e) {
            if ($idem !== '') {
                $repo->releaseIdempotency($customerId, $idem); // never strand the key on a thrown error
            }

            return $this->failWith('SERVER_ERROR', 'Could not place your order. Please try again.');
        }
        if ($orderNo === null) {
            if ($idem !== '') {
                $repo->releaseIdempotency($customerId, $idem); // allow a retry
            }

            return $this->failWith('SERVER_ERROR', 'Could not place your order. Please try again.');
        }

        $response = [
            'order_no'       => $orderNo,
            'totals'         => $totals,
            'payment_method' => $method,
            'coupon'         => $coupon ?: null,
        ];
        if ($idem !== '') {
            $repo->completeIdempotency($customerId, $idem, $orderNo, $response);
        }

        return $this->created($response);
    }

    public function orders()
    {
        $customerId = service('storeCustomerRepository')->customerIdForUser($this->userId());
        if ($customerId === null) {
            return $this->failWith('FORBIDDEN', 'Not a customer account.');
        }
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(50, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $result  = service('storeOrderRepository')->forCustomer($customerId, $page, $perPage);

        return $this->collection($result['items'], [
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($result['total'] / $perPage),
        ]);
    }

    public function track(string $orderNo)
    {
        // Tracking exposes the delivery address (PII) + live rider location, so it is
        // scoped to the order's owner — never a public lookup by order number (IDOR).
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        if (! service('storeOrderRepository')->customerOwns($orderNo, $cid)) {
            return $this->failWith('NOT_FOUND', 'Order not found.');
        }
        $repo  = service('storeOrderRepository');
        $order = $repo->track($orderNo);
        if ($order === null) {
            return $this->failWith('NOT_FOUND', 'Order not found.');
        }
        $order['returns'] = $repo->returnsForOrder($orderNo, $cid);

        return $this->ok($order);
    }

    // ---- Phase B: saved addresses, cart/coupon validation, per-product cancel/return ----

    public function addresses()
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $items = service('storeCustomerRepository')->addresses($cid);

        return $this->collection($items, ['total' => count($items)]);
    }

    public function addAddress()
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $d = $this->addressInput();
        if ($err = $this->addressError($d)) {
            return $err;
        }
        $repo = service('storeCustomerRepository');
        $id   = $repo->addAddress($cid, $d);

        return $id === null
            ? $this->failWith('SERVER_ERROR', 'Could not save the address.')
            : $this->created(['id' => $id, 'address' => $repo->address($id, $cid)]);
    }

    public function updateAddress(int $id)
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $repo = service('storeCustomerRepository');
        if ($repo->address($id, $cid) === null) {
            return $this->failWith('NOT_FOUND', 'Address not found.');
        }
        $d = $this->addressInput();
        if ($err = $this->addressError($d)) {
            return $err;
        }
        $repo->updateAddress($id, $cid, $d);

        return $this->ok(['id' => $id, 'address' => $repo->address($id, $cid)]);
    }

    public function deleteAddress(int $id)
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $repo = service('storeCustomerRepository');
        if ($repo->address($id, $cid) === null) {
            return $this->failWith('NOT_FOUND', 'Address not found.');
        }
        $repo->deleteAddress($id, $cid);

        return $this->ok(['deleted' => true]);
    }

    public function getProfile()
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $p = service('storeCustomerRepository')->profile($cid);
        if ($p === null) {
            return $this->failWith('NOT_FOUND', 'Customer not found.');
        }
        $p['has_email'] = isset($p['email']) && $p['email'] !== '' && $p['email'] !== null;

        return $this->ok(['profile' => $p]);
    }

    public function updateProfile()
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $body  = $this->input();
        $name  = trim((string) ($body['name'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));

        if ($name === '') {
            return $this->failWith('VALIDATION', 'Name is required.');
        }
        $repo     = service('storeCustomerRepository');
        $existing = $repo->profile($cid);
        $phone    = (string) ($existing['phone'] ?? '');

        $ok = $repo->updateProfile($cid, $name, $email, $phone);
        if (! $ok) {
            return $this->failWith('CONFLICT', 'Email is already in use by another account.');
        }

        return $this->ok(['profile' => $repo->profile($cid)]);
    }

    /** Validate a cart against current price/stock/payment/deliverability (no order created). */
    public function validateCart()
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid; // vendor/rider tokens may not validate customer carts
        }
        $in      = $this->input();
        $lines   = service('cartService')->linesFor($this->cartMap($in));
        $lat     = $this->floatOrNull($in['lat'] ?? null);
        $lng     = $this->floatOrNull($in['lng'] ?? null);
        $method  = (string) ($in['method'] ?? 'cod') ?: 'cod';
        $tip     = max(0.0, (float) ($in['tip'] ?? 0));
        $catalog = service('storeCatalogRepository');

        // Fail closed: a cart with items but no usable delivery coordinates cannot
        // be validated for delivery — block it rather than assuming "deliverable".
        $hasCoords = $lat !== null && $lng !== null && ! ($lat === 0.0 && $lng === 0.0);

        $issues   = [];
        $adminMax = $hasCoords ? service('settingsRepository')->deliveryMaxRadiusKm() : null;
        foreach ($lines as $l) {
            $vid   = (int) ($l['variant_id'] ?? 0);
            $title = (string) ($l['title'] ?? 'Item');
            if (! PurchaseRules::paymentAllowed($method, (string) ($catalog->purchaseRulesForVariant($vid)['payment_restriction'] ?? 'both'))) {
                $issues[] = ['variant_id' => $vid, 'title' => $title, 'reason' => 'payment_restricted'];
            } elseif ($err = $this->qtyError($vid, (int) ($l['qty'] ?? 0))) {
                $issues[] = ['variant_id' => $vid, 'title' => $title, 'reason' => 'qty', 'message' => $err];
            } elseif (! $hasCoords) {
                $issues[] = ['variant_id' => $vid, 'shop_id' => $l['vendor_id'] ?? null, 'title' => $title, 'reason' => 'location_required'];
            } else {
                $d = $catalog->variantDeliverability($vid, $lat, $lng, $adminMax);
                if (! $d['deliverable']) {
                    $issues[] = ['variant_id' => $vid, 'shop_id' => $d['shop_id'], 'title' => $title, 'reason' => $d['reason']];
                }
            }
        }

        // Min-order-value check: compare subtotal against the highest per-shop minimum.
        if ($lines !== [] && $issues === []) {
            $subtotal  = (float) service('cartService')->totals($lines)['subtotal'];
            $vendorIds = array_values(array_unique(array_map(static fn ($l) => (int) $l['vendor_id'], $lines)));
            $minVal    = service('storeShopRepository')->minOrderValueForVendors($vendorIds);
            if ($minVal !== null && $subtotal < $minVal) {
                $need     = number_format($minVal - $subtotal, 0);
                $issues[] = [
                    'reason'          => 'min_order',
                    'title'           => 'Minimum order not met',
                    'message'         => 'Add items worth ₹' . $need . ' more (min ₹' . number_format($minVal, 0) . ').',
                    'min_order_value' => $minVal,
                ];
            }
        }

        $pct    = 0.0;
        $coupon = trim((string) ($in['coupon'] ?? ''));
        if ($coupon !== '' && $lines !== []) {
            $sub = (float) service('cartService')->totals($lines)['subtotal'];
            $c   = service('storeCouponRepository')->validate($coupon, $sub, $cid);
            if ($c['ok']) {
                $pct = (float) $c['pct'];
            }
        }

        return $this->ok([
            'valid'               => $lines !== [] && $issues === [],
            'issues'              => $issues,
            'lines'               => $lines,
            'totals'             => service('cartService')->totals($lines, $pct, null, $tip, CartService::HANDLING_FEE),
            'deliverable_checked' => $hasCoords,
        ]);
    }

    /** Validate a coupon against the current cart subtotal for this customer. */
    public function validateCoupon()
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $in    = $this->input();
        $code  = trim((string) ($in['coupon'] ?? $in['code'] ?? ''));
        $lines = service('cartService')->linesFor($this->cartMap($in));
        if ($lines === []) {
            // A coupon is meaningless without a cart — never report it "valid" on empty.
            return $this->failWith('VALIDATION_ERROR', 'Add items to your cart before applying a coupon.');
        }
        $subtotal = (float) service('cartService')->totals($lines)['subtotal'];
        $res      = service('storeCouponRepository')->validate($code, $subtotal, $cid);

        if (! $res['ok']) {
            return $this->failWith('VALIDATION_ERROR', $res['message'], ['code' => $res['code']]);
        }

        return $this->ok([
            'code'    => $res['code'],
            'pct'     => $res['pct'],
            'message' => $res['message'],
            'totals'  => service('cartService')->totals($lines, (float) $res['pct'], null, 0.0, CartService::HANDLING_FEE),
        ]);
    }

    /** Cancel the entire order (all eligible sub-orders). Uses storeOrderRepository::cancel() which handles refunds. */
    public function cancelOrder(string $orderNo)
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $res = service('storeOrderRepository')->cancel($orderNo, $cid);

        return ($res['ok'] ?? false)
            ? $this->ok(['message' => 'Order cancelled. Any payment will be refunded within 5–7 business days.'])
            : $this->failWith('CONFLICT', $res['reason'] ?? 'Could not cancel the order.');
    }

    /** Rate a delivery (1–5 stars + optional comment). The delivery must belong to the customer. */
    public function rateDelivery(int $deliveryId)
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $db = db_connect();
        $delivery = $db->table('deliveries d')
            ->select('d.id, d.status, d.customer_rating')
            ->join('sub_orders so', 'so.id = d.sub_order_id')
            ->join('orders o', 'o.id = so.order_id')
            ->where('d.id', $deliveryId)
            ->where('o.customer_id', $cid)
            ->where('d.deleted_at', null)
            ->get()->getRowArray();
        if ($delivery === null) {
            return $this->failWith('NOT_FOUND', 'Delivery not found.');
        }
        if ($delivery['status'] !== 'delivered') {
            return $this->failWith('CONFLICT', 'You can only rate a completed delivery.');
        }
        $in      = $this->input();
        $rating  = (int) ($in['rating'] ?? 0);
        $comment = mb_substr(trim((string) ($in['comment'] ?? '')), 0, 500);
        if ($rating < 1 || $rating > 5) {
            return $this->failWith('VALIDATION_ERROR', 'Rating must be between 1 and 5.');
        }
        $db->table('deliveries')->where('id', $deliveryId)->update([
            'customer_rating'  => $rating,
            'customer_comment' => $comment !== '' ? $comment : null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        return $this->ok(['message' => 'Thank you for your feedback!']);
    }

    /** Cancel ONE product (sub-order) of an order pre-fulfilment (proportional refund if paid). */
    public function cancelItem(string $orderNo, int $subOrderId)
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $res = service('storeOrderRepository')->cancelSubOrder($orderNo, $subOrderId, $cid);

        return ($res['ok'] ?? false)
            ? $this->ok(['message' => 'Item cancelled.'])
            : $this->failWith('CONFLICT', $res['reason'] ?? 'Could not cancel the item.');
    }

    /** Request a return for delivered items: items = [{order_item_id, qty}, …] + reason. */
    public function returnOrder(string $orderNo)
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $in    = $this->input();
        $items = [];
        foreach ((array) ($in['items'] ?? []) as $row) {
            $oiId = (int) ($row['order_item_id'] ?? 0);
            $q    = (float) ($row['qty'] ?? 0);
            if ($oiId > 0 && $q > 0) {
                $items[$oiId] = $q;
            }
        }
        $reason = trim((string) ($in['reason'] ?? ''));
        if ($items === []) {
            return $this->failWith('VALIDATION_ERROR', 'Select at least one item to return.');
        }
        if ($reason === '') {
            return $this->failWith('VALIDATION_ERROR', 'A return reason is required.');
        }
        $res = service('storeOrderRepository')->createReturn($orderNo, $cid, $items, $reason);

        return ($res['ok'] ?? false)
            ? $this->ok(['message' => 'Return requested.'])
            : $this->failWith('CONFLICT', $res['reason'] ?? 'Could not submit the return.');
    }

    /** Submit a product review. Customer must have at least one delivered order containing the product. */
    public function submitReview(int $productId)
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        if ($productId <= 0) {
            return $this->failWith('NOT_FOUND', 'Product not found.');
        }
        $db = db_connect();
        // Verify customer has purchased and received this product.
        // order_items stores variant_id; join product_variants to match by product.
        $hasPurchased = $db->table('order_items oi')
            ->join('product_variants pv', 'pv.id = oi.variant_id')
            ->join('sub_orders so', 'so.id = oi.sub_order_id')
            ->join('orders o', 'o.id = so.order_id')
            ->where('o.customer_id', $cid)
            ->where('pv.product_id', $productId)
            ->whereIn('so.status', ['delivered', 'completed'])
            ->countAllResults() > 0;
        if (! $hasPurchased) {
            return $this->failWith('CONFLICT', 'You can only review products you have received.');
        }
        $in     = $this->input();
        $rating = (int) ($in['rating'] ?? 0);
        $title  = mb_substr(trim((string) ($in['title'] ?? '')), 0, 191);
        $body   = trim((string) ($in['body'] ?? ''));
        if ($rating < 1 || $rating > 5) {
            return $this->failWith('VALIDATION_ERROR', 'Rating must be between 1 and 5.');
        }
        $alreadyReviewed = $db->table('reviews')
            ->where('product_id', $productId)->where('customer_id', $cid)->countAllResults() > 0;
        if ($alreadyReviewed) {
            return $this->failWith('CONFLICT', 'You have already reviewed this product.');
        }
        $ok = service('storeCustomerRepository')->submitReview($cid, $productId, $rating, $title, $body);

        return $ok
            ? $this->ok(['message' => 'Review submitted.'])
            : $this->failWith('CONFLICT', 'Could not submit the review.');
    }

    /**
     * Start an online (PayU) payment for a placed-but-unpaid order. Returns the
     * params + forward hash the PayU CheckoutPro SDK needs. Falls back to COD when
     * no gateway is configured (DEPENDENCY_UNAVAILABLE → app keeps COD).
     */
    public function payInit(string $orderNo)
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $repo = service('storeOrderRepository');
        if (! $repo->customerOwns($orderNo, $cid)) {
            return $this->failWith('NOT_FOUND', 'Order not found.');
        }
        $summary = $repo->paymentSummary($orderNo);
        if ($summary === null) {
            return $this->failWith('NOT_FOUND', 'Order not found.');
        }
        if (($summary['payment_status'] ?? '') === 'paid') {
            return $this->failWith('CONFLICT', 'This order is already paid.');
        }
        $payu = service('payuClient');
        if (! $payu->configured()) {
            return $this->failWith('DEPENDENCY_UNAVAILABLE', 'Online payment is currently unavailable.');
        }
        $amount      = number_format((float) $summary['grand_total'], 2, '.', '');
        $txnid       = $orderNo;
        $productinfo = 'Order ' . $orderNo;
        $firstname   = trim((string) ($summary['customer_name'] ?? '')) ?: 'Customer';
        $email       = trim((string) ($summary['customer_email'] ?? ''));
        $phone       = trim((string) ($summary['customer_phone'] ?? ''));
        $repo->setGatewayRef($orderNo, $txnid);

        return $this->ok([
            'gateway'     => 'payu',
            'key'         => $payu->key(),
            'txnid'       => $txnid,
            'amount'      => $amount,
            'productinfo' => $productinfo,
            'firstname'   => $firstname,
            'email'       => $email,
            'phone'       => $phone,
            'hash'        => $payu->paymentHash(compact('txnid', 'amount', 'productinfo', 'firstname', 'email')),
            'environment' => $payu->isTest() ? 1 : 0,
        ]);
    }

    /** Sign a PayU hash string for the SDK's generateHash callback (salt stays server-side). */
    public function payuHash()
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $payu = service('payuClient');
        if (! $payu->configured()) {
            return $this->failWith('DEPENDENCY_UNAVAILABLE', 'Online payment is currently unavailable.');
        }
        $hs = trim((string) ($this->input()['hash_string'] ?? ''));
        if ($hs === '') {
            return $this->failWith('VALIDATION_ERROR', 'hash_string is required.');
        }

        return $this->ok(['hash' => $payu->hash($hs)]);
    }

    /** Verify a PayU success callback (reverse hash) and mark the order paid. */
    public function payVerify(string $orderNo)
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $repo = service('storeOrderRepository');
        if (! $repo->customerOwns($orderNo, $cid)) {
            return $this->failWith('NOT_FOUND', 'Order not found.');
        }
        $resp   = $this->input();
        $payu   = service('payuClient');
        $status = strtolower((string) ($resp['status'] ?? ''));
        if ($status !== 'success' || ! $payu->verifyResponse($resp)) {
            return $this->failWith('VALIDATION_ERROR', 'Payment could not be verified. If money was deducted it will be refunded.');
        }
        $repo->markPaid($orderNo, (string) ($resp['mihpayid'] ?? $resp['txnid'] ?? $orderNo));

        return $this->ok(['payment_status' => 'paid', 'order_no' => $orderNo]);
    }

    /** The customer's account-synced wishlist (Product-shaped items). */
    public function wishlist()
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $items = service('storeWishlistRepository')->forCustomer($cid);

        return $this->collection($items, ['total' => count($items)]);
    }

    /** Add a variant to the wishlist: { variant_id }. */
    public function addWishlist()
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $vid = (int) ($this->input()['variant_id'] ?? 0);
        if ($vid <= 0) {
            return $this->failWith('VALIDATION_ERROR', 'A variant_id is required.', ['field' => 'variant_id']);
        }
        if (! service('storeWishlistRepository')->add($cid, $vid)) {
            return $this->failWith('NOT_FOUND', 'That item is no longer available.');
        }

        return $this->ok(['variant_id' => $vid, 'wishlisted' => true]);
    }

    /** Remove a variant from the wishlist. */
    public function removeWishlist(int $variantId)
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        service('storeWishlistRepository')->remove($cid, $variantId);

        return $this->ok(['variant_id' => $variantId, 'wishlisted' => false]);
    }

    /** Active public coupons for the checkout "see all coupons" sheet. */
    public function coupons()
    {
        return $this->collection(service('storeCouponRepository')->activePublic(20), []);
    }

    /** Per-variant qty + managed-stock check (mirrors CheckoutController::qtyError). */
    private function qtyError(int $variantId, int $finalQty): ?string
    {
        $catalog = service('storeCatalogRepository');
        $rules   = $catalog->purchaseRulesForVariant($variantId);
        $check   = PurchaseRules::validate((float) $finalQty, $rules);
        if (! $check['ok']) {
            return $check['message'];
        }
        $stock = $catalog->variantStock($variantId);
        if ($stock['mode'] === 'managed' && ! $stock['backorder'] && $stock['tracked'] && $finalQty > $stock['available']) {
            $avail = (int) floor($stock['available']);

            return $avail > 0 ? ('Only ' . $avail . ' left in stock.') : 'This item is out of stock.';
        }

        return null;
    }

    // ---- Payment instruments (saved UPI / card refs) -------------------------

    /** List the customer's saved payment instruments + last 20 payment records. */
    public function paymentInstruments()
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $db          = \Config\Database::connect();
        $instruments = $db->table('customer_payment_instruments')
            ->where('customer_id', $cid)->where('deleted_at', null)
            ->orderBy('is_default', 'DESC')->orderBy('created_at', 'DESC')
            ->get()->getResultArray();

        $history = $db->table('payments p')
            ->select('p.method, p.gateway, p.amount, p.currency, p.status, p.captured_at, o.order_no')
            ->join('orders o', 'o.id = p.order_id', 'left')
            ->where('o.customer_id', $cid)
            ->orderBy('p.created_at', 'DESC')
            ->limit(20)
            ->get()->getResultArray();

        return $this->ok([
            'instruments' => array_map(static fn(array $r): array => [
                'id'         => (int) $r['id'],
                'type'       => $r['type'],
                'label'      => $r['label'],
                'instrument' => $r['instrument'],
                'is_default' => (bool) $r['is_default'],
                'created_at' => $r['created_at'],
            ], $instruments),
            'history' => array_map(static fn(array $r): array => [
                'method'      => $r['method'],
                'gateway'     => $r['gateway'],
                'amount'      => (float) $r['amount'],
                'currency'    => $r['currency'],
                'status'      => $r['status'],
                'captured_at' => $r['captured_at'],
                'order_no'    => $r['order_no'],
            ], $history),
        ]);
    }

    /** Save a new payment instrument (UPI VPA or card reference). */
    public function addPaymentInstrument()
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $in         = $this->input();
        $type       = trim((string) ($in['type'] ?? 'upi'));
        $label      = trim((string) ($in['label'] ?? ''));
        $instrument = trim((string) ($in['instrument'] ?? ''));

        if (! in_array($type, ['upi', 'card', 'netbanking', 'wallet'], true)) {
            return $this->failWith('VALIDATION_ERROR', 'Invalid type.', ['type' => 'Must be upi, card, netbanking, or wallet.']);
        }
        if ($instrument === '') {
            return $this->failWith('VALIDATION_ERROR', 'Instrument is required.', ['instrument' => 'required']);
        }
        if ($type === 'upi' && ! preg_match('/^[\w.\-]+@[\w]+$/', $instrument)) {
            return $this->failWith('VALIDATION_ERROR', 'Invalid UPI ID format.', ['instrument' => 'Must be in format: id@bank']);
        }

        $db = \Config\Database::connect();
        $db->table('customer_payment_instruments')->insert([
            'customer_id' => $cid,
            'type'        => $type,
            'label'       => $label ?: strtoupper($type),
            'instrument'  => $instrument,
            'is_default'  => 0,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        return $this->created(['id' => $db->insertID(), 'type' => $type, 'label' => $label ?: strtoupper($type), 'instrument' => $instrument]);
    }

    /** Delete a saved payment instrument (soft-delete). */
    public function deletePaymentInstrument(int $id)
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $db  = \Config\Database::connect();
        $row = $db->table('customer_payment_instruments')->where('id', $id)->where('customer_id', $cid)->where('deleted_at', null)->get()->getRowArray();
        if ($row === null) {
            return $this->failWith('NOT_FOUND', 'Instrument not found.');
        }
        $db->table('customer_payment_instruments')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        return $this->ok(['deleted' => true]);
    }

    /** Set a saved instrument as default (clears others). */
    public function setDefaultInstrument(int $id)
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $db  = \Config\Database::connect();
        $row = $db->table('customer_payment_instruments')->where('id', $id)->where('customer_id', $cid)->where('deleted_at', null)->get()->getRowArray();
        if ($row === null) {
            return $this->failWith('NOT_FOUND', 'Instrument not found.');
        }
        $db->table('customer_payment_instruments')->where('customer_id', $cid)->update(['is_default' => 0]);
        $db->table('customer_payment_instruments')->where('id', $id)->update(['is_default' => 1]);

        return $this->ok(['default_id' => $id]);
    }

    /** @return int customer id, or a failure Response when the JWT isn't a customer. */
    private function requireCustomer()
    {
        $cid = service('storeCustomerRepository')->customerIdForUser($this->userId());

        return $cid ?? $this->failWith('FORBIDDEN', 'A customer account is required.');
    }

    /** Collapse a client cart payload (items[{variant_id,qty}]) into variant_id => qty. */
    private function cartMap(array $in): array
    {
        $map = [];
        foreach ((array) ($in['items'] ?? []) as $line) {
            $vid = (int) ($line['variant_id'] ?? 0);
            $qty = max(1, (int) ($line['qty'] ?? 1));
            if ($vid > 0) {
                $map[$vid] = ($map[$vid] ?? 0) + $qty;
            }
        }

        return $map;
    }

    /** @return array<string,mixed> normalised address payload for the customer repo. */
    private function addressInput(): array
    {
        $in = $this->input();

        return [
            'label'             => (string) ($in['label'] ?? 'Home'),
            'recipient_name'    => trim((string) ($in['recipient_name'] ?? $in['name'] ?? '')),
            'phone'             => trim((string) ($in['phone'] ?? '')),
            'line1'             => trim((string) ($in['line1'] ?? '')),
            'line2'             => trim((string) ($in['line2'] ?? '')),
            'city'              => trim((string) ($in['city'] ?? '')),
            'state_code'        => trim((string) ($in['state_code'] ?? '')),
            'pincode'           => trim((string) ($in['pincode'] ?? '')),
            'formatted_address' => trim((string) ($in['formatted_address'] ?? $in['formatted'] ?? '')),
            'latitude'          => $this->floatOrNull($in['latitude'] ?? $in['lat'] ?? null),
            'longitude'         => $this->floatOrNull($in['longitude'] ?? $in['lng'] ?? null),
            'is_default'        => ! empty($in['is_default']) ? 1 : 0,
        ];
    }

    /** @param array<string,mixed> $d @return mixed null when valid, else a failure Response. */
    private function addressError(array $d)
    {
        if ($d['recipient_name'] === '') {
            return $this->failWith('VALIDATION_ERROR', 'Recipient name is required.', ['field' => 'recipient_name']);
        }
        if ($d['line1'] === '') {
            return $this->failWith('VALIDATION_ERROR', 'Address line 1 is required.', ['field' => 'line1']);
        }
        if ($d['pincode'] !== '' && ! preg_match('/^[0-9]{6}$/', $d['pincode'])) {
            return $this->failWith('VALIDATION_ERROR', 'Pincode must be 6 digits.', ['field' => 'pincode']);
        }
        // Map pin is required — an address with no coordinates can't pass the delivery
        // deliverability gate at checkout, so reject it at save time (parity with web map).
        if ($d['latitude'] === null || $d['longitude'] === null) {
            return $this->failWith('VALIDATION_ERROR', 'Pick the address location on the map (lat/lng required).', ['fields' => ['latitude', 'longitude']]);
        }
        if ($d['phone'] !== '' && ! preg_match('/^\+?[0-9]{10,15}$/', $d['phone'])) {
            return $this->failWith('VALIDATION_ERROR', 'The phone number is invalid.', ['field' => 'phone']);
        }

        return null;
    }

    // ── Push notifications ────────────────────────────────────────────────────

    /** Register or refresh an FCM device token for the current customer. */
    public function registerDeviceToken()
    {
        $body     = $this->input();
        $token    = trim((string) ($body['fcm_token'] ?? ''));
        $platform = in_array((string) ($body['platform'] ?? ''), ['android', 'ios', 'web'], true)
            ? (string) $body['platform']
            : 'android';

        if ($token === '') {
            return $this->failWith('VALIDATION_ERROR', 'fcm_token is required.');
        }

        service('deviceTokenRepository')->upsert(
            $this->userId(),
            $token,
            $platform,
            ($body['app_version'] ?? '') !== '' ? (string) $body['app_version'] : null,
        );

        return $this->ok(['registered' => true]);
    }

    /** Send a sample push to all registered devices of the current customer (demo / debug). */
    public function sendTestNotification()
    {
        $tokens = service('deviceTokenRepository')->activeForUser($this->userId());

        if ($tokens === []) {
            return $this->failWith('NOT_FOUND', 'No registered device tokens. Open the app to auto-register.');
        }

        $result = (new \App\Libraries\Notify\FcmPusher())->send(
            $tokens,
            'Shiplore Notification',
            'Your deliveries, lightning fast. Tap to explore!',
            ['route' => '/', 'badge' => '1'],
        );

        return $this->ok($result);
    }

    /** Return the latest notifications for the logged-in customer (paginated). */
    public function notifications()
    {
        $uid     = $this->userId();
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 50)));
        $db      = \Config\Database::connect();

        $base = $db->table('notifications')
            ->where('user_id', $uid)
            ->where('deleted_at IS NULL', null, false);
        $total = (clone $base)->countAllResults(false);
        $rows  = $base->orderBy('created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return $this->collection($rows, [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'has_more' => ($page * $perPage) < $total,
        ]);
    }

    /** Unread notification count for the logged-in customer (drives the bell badge). */
    public function notificationsUnreadCount()
    {
        $uid    = $this->userId();
        $unread = \Config\Database::connect()->table('notifications')
            ->where('user_id', $uid)
            ->where('read_at IS NULL', null, false)
            ->where('deleted_at IS NULL', null, false)
            ->countAllResults();

        return $this->ok(['unread' => $unread]);
    }

    /** Mark a single notification as read. */
    public function markNotificationRead(int $id)
    {
        $uid = $this->userId();
        \Config\Database::connect()
            ->table('notifications')
            ->where('id', $id)
            ->where('user_id', $uid)
            ->update(['read_at' => date('Y-m-d H:i:s')]);

        return $this->ok(['updated' => true]);
    }

    /** Mark all unread notifications as read for the current customer. */
    public function markAllNotificationsRead()
    {
        $uid = $this->userId();
        \Config\Database::connect()
            ->table('notifications')
            ->where('user_id', $uid)
            ->where('read_at IS NULL', null, false)
            ->where('deleted_at IS NULL', null, false)
            ->update(['read_at' => date('Y-m-d H:i:s')]);

        return $this->ok(['updated' => true]);
    }

    /** Soft-delete a single notification (removes it from the customer's list). */
    public function deleteNotification(int $id)
    {
        $uid = $this->userId();
        \Config\Database::connect()
            ->table('notifications')
            ->where('id', $id)
            ->where('user_id', $uid)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        return $this->ok(['deleted' => true]);
    }

    /** Soft-delete all notifications for the current customer. */
    public function clearAllNotifications()
    {
        $uid = $this->userId();
        \Config\Database::connect()
            ->table('notifications')
            ->where('user_id', $uid)
            ->where('deleted_at IS NULL', null, false)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        return $this->ok(['cleared' => true]);
    }

    /** Customer wallet — balance + last 50 transactions. Auto-creates wallet row on first call. */
    public function wallet()
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $db = \Config\Database::connect();
        $w  = $db->table('wallets')->where('owner_type', 'customer')->where('owner_id', $cid)->where('deleted_at', null)->get()->getRowArray();
        if ($w === null) {
            $db->table('wallets')->insert([
                'uuid'       => (static function (): string { $d = random_bytes(16); $d[6] = chr(ord($d[6]) & 0x0F | 0x40); $d[8] = chr(ord($d[8]) & 0x3F | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4)); })(),
                'owner_type' => 'customer',
                'owner_id'   => $cid,
                'balance'    => 0,
                'currency'   => 'INR',
                'status'     => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $w = $db->table('wallets')->where('owner_type', 'customer')->where('owner_id', $cid)->get()->getRowArray();
        }
        $txns = $db->table('wallet_transactions')->where('wallet_id', $w['id'])->orderBy('created_at', 'DESC')->limit(50)->get()->getResultArray();

        return $this->ok([
            'balance'      => (float) $w['balance'],
            'currency'     => $w['currency'],
            'status'       => $w['status'],
            'transactions' => array_map(static fn(array $t): array => [
                'id'          => (int) $t['id'],
                'type'        => $t['type'],
                'amount'      => (float) $t['amount'],
                'balance_after' => (float) $t['balance_after'],
                'reason'      => $t['reason'],
                'ref_type'    => $t['ref_type'],
                'created_at'  => $t['created_at'],
            ], $txns),
        ]);
    }

    /** Download GST invoice PDF for a sub-order the customer owns (lazy-generated, idempotent). */
    public function invoicePdf(int $subOrderId)
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $db  = \Config\Database::connect();
        $sub = $db->table('sub_orders so')
            ->select('so.id, so.status')
            ->join('orders o', 'o.id = so.order_id')
            ->where('so.id', $subOrderId)
            ->where('o.customer_id', $cid)
            ->where('o.deleted_at', null)
            ->get()->getRowArray();
        if ($sub === null) {
            return $this->failWith('NOT_FOUND', 'Invoice not found.');
        }
        $invoiceId = service('invoiceService')->ensureForSubOrder($subOrderId, null, (string) $sub['status']);
        if ($invoiceId === null) {
            return $this->failWith('UNPROCESSABLE', 'Invoice not yet available for this order.');
        }
        $res = service('documentPdfService')->invoicePdf($invoiceId, null);
        if (! $res['ok']) {
            return $this->failWith('SERVER_ERROR', 'Could not generate invoice.');
        }

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $res['filename'] . '"')
            ->setBody($res['bytes']);
    }

    /** Create a customer support ticket (general enquiry / complaint). */
    public function createSupportTicket()
    {
        $cid = $this->requireCustomer();
        if (! is_int($cid)) {
            return $cid;
        }
        $body    = $this->input();
        $subject = trim((string) ($body['subject'] ?? ''));
        $message = trim((string) ($body['message'] ?? ''));

        if ($subject === '' || $message === '') {
            return $this->failWith('VALIDATION_ERROR', 'Subject and message are required.', ['subject' => $subject === '' ? 'required' : null, 'message' => $message === '' ? 'required' : null]);
        }

        $db = \Config\Database::connect();
        $db->table('support_tickets')->insert([
            'customer_id' => $cid,
            'category'    => 'general',
            'subject'     => $subject,
            'body'        => $message,
            'status'      => 'open',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return $this->ok(['ticket_id' => $db->insertID(), 'status' => 'open']);
    }

    private function floatOrNull(mixed $v): ?float
    {
        return ($v === null || $v === '') ? null : (float) $v;
    }

    /** Human message for a delivery validation reason code (canonical vocabulary). */
    private static function deliveryReasonMessage(string $reason, string $title): string
    {
        return match ($reason) {
            'location_required'    => 'Pin your delivery address on the map to continue.',
            'outside_service_area' => "'" . $title . "' is outside our delivery area.",
            'shop_no_delivery'     => "The store for '" . $title . "' doesn't deliver to your location.",
            'no_serving_shop'      => "No store delivers '" . $title . "' to your location yet.",
            default                => "'" . $title . "' can't be delivered to your location.",
        };
    }
}
