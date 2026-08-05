<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Tax\GstCalculator;
use Config\Database;
use Throwable;

/**
 * StoreOrderRepository — places a customer order (splitting into per-vendor
 * sub-orders with GST + commission) and supports order tracking. Atomic.
 */
final class StoreOrderRepository
{
    /**
     * @param list<array<string,mixed>> $items   cart lines
     * @param array<string,mixed>       $address  name/line1/city/state_code/pincode
     * @param array<string,string>      $totals   from CartService::totals()
     * @return string|null order_no, or null on failure
     */
    public function place(int $customerId, array $items, array $address, array $totals, string $method, ?string $coupon, int $rand, ?string $gstin = null, ?string $deliveryInstructions = null): ?string
    {
        if ($items === []) {
            return null;
        }

        $db  = Database::connect();

        // Backstop validation for EVERY caller (web checkout + mobile API): enforce
        // purchase rules (min/max/step) and an oversell guard on each line. The web
        // controller validates earlier with nicer messages; this guarantees the API
        // and any stale cart can never bypass it.
        $catalog = service('storeCatalogRepository');
        foreach ($items as $it) {
            $vid = (int) $it['variant_id'];
            $qty = (float) $it['qty'];
            if (! \App\Libraries\Catalog\PurchaseRules::validate($qty, $catalog->purchaseRulesForVariant($vid))['ok']) {
                return null; // qty violates min/max/step
            }
            $avail = (float) ($db->table('inventory i')
                ->selectSum('i.available', 'avail')
                ->join('shops s', 's.id = i.shop_id', 'left')
                ->where('i.variant_id', $vid)->where('s.vendor_id', (int) $it['vendor_id'])
                ->where('s.deleted_at', null)->get()->getRowArray()['avail'] ?? 0);
            if ($avail > 0 && $avail < $qty) {
                return null; // insufficient stock -> checkout shows a retry message
            }
        }

        $gst = new GstCalculator();
        $commission = service('commissionService');
        $db->transBegin();

        try {
            $now     = date('Y-m-d H:i:s');
            $orderNo = 'ORD-' . date('Ymd') . '-' . $rand;
            $custState = (string) ($address['state_code'] ?? '27');

            $db->table('orders')->insert([
                'uuid'                 => bin2hex(random_bytes(18)),
                'order_no'             => $orderNo,
                'customer_id'          => $customerId,
                'channel'              => 'online',
                'vertical'             => (string) ($items[0]['vertical'] ?? 'goods'),   // single-vertical cart
                'currency'             => 'INR',
                'subtotal'             => $totals['subtotal'],
                'discount_total'       => $totals['discount'],
                'tax_total'            => $totals['tax'],
                'delivery_total'       => $totals['delivery'],
                'handling_total'       => $totals['handling'] ?? null,
                'tip_total'            => $totals['tip'] ?? null,
                'grand_total'          => $totals['grand'],
                'billing_address_json' => json_encode($address),
                'gstin'                => ($gstin ?? '') !== '' ? mb_substr($gstin, 0, 20) : null,
                'delivery_instructions' => ($deliveryInstructions ?? '') !== '' ? mb_substr($deliveryInstructions, 0, 255) : null,
                // Map-picked delivery point (used to record/verify per-item serviceability).
                'delivery_lat'         => isset($address['lat']) && $address['lat'] !== '' ? (float) $address['lat'] : null,
                'delivery_lng'         => isset($address['lng']) && $address['lng'] !== '' ? (float) $address['lng'] : null,
                // Online orders are NOT paid until the gateway verifies the payment;
                // COD is collected on delivery. Both start pending — markPaid() flips it.
                'payment_status'       => 'pending',
                'placed_at'            => $now,
                'status'               => 'confirmed',
            ]);
            $orderId = (int) $db->insertID();

            // Record coupon redemption + bump usage (audit trail; enforces usage_limit).
            if ($coupon !== null && $coupon !== '') {
                $cp = $db->table('coupons')->select('id')->where('code', strtoupper($coupon))->where('deleted_at', null)->get()->getRowArray();
                if ($cp !== null) {
                    $db->table('coupon_redemptions')->insert([
                        'coupon_id'       => (int) $cp['id'],
                        'customer_id'     => $customerId,
                        'order_id'        => $orderId,
                        'discount_amount' => $this->n((float) ($totals['discount'] ?? 0)),
                        'redeemed_at'     => $now,
                    ]);
                    $db->table('coupons')->where('id', (int) $cp['id'])->set('used_count', 'used_count + 1', false)->update();
                }
            }

            // ONE sub-order (= one bill/invoice) PER PRODUCT. The order remains a
            // single order with a single payment, but every product gets its own
            // sub-order so cancel / return / refund + invoicing are managed per
            // product. (Invoicing is already per sub-order, so each product bills
            // independently for free.)
            $seq = 0;
            $placedSubs = []; // for the T+0 "new order" shop notification (after commit)
            foreach ($items as $it) {
                $seq++;
                $vendorId    = (int) $it['vendor_id'];
                $shopId      = $this->vendorShop($db, $vendorId);
                $sellerState = $this->shopState($db, $shopId) ?? $custState;
                $interState  = $sellerState !== $custState;

                $lineTotal = (float) $it['line_total'];
                $g    = $gst->compute((string) $lineTotal, (float) $it['tax_rate'], true, $interState);
                $comm = (float) $commission->amountForProduct((int) $it['product_id'], (float) $g['taxable']);

                $db->table('sub_orders')->insert([
                    'uuid'              => bin2hex(random_bytes(18)),
                    'order_id'          => $orderId,
                    'vendor_id'         => $vendorId,
                    'shop_id'           => $shopId,
                    'sub_order_no'      => $orderNo . '-' . $seq,
                    'subtotal'          => $this->n($lineTotal),
                    'taxable_value'     => $this->n((float) $g['taxable']),
                    'cgst'              => $this->n((float) $g['cgst']),
                    'sgst'              => $this->n((float) $g['sgst']),
                    'igst'              => $this->n((float) $g['igst']),
                    'delivery_total'    => '0.0000',
                    'grand_total'       => $this->n($lineTotal),
                    'commission_amount' => $this->n($comm),
                    'place_of_supply'   => $custState,
                    'status'            => 'confirmed',
                    'delivery_otp'      => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
                ]);
                $subOrderId = (int) $db->insertID();
                $placedSubs[] = ['shop_id' => $shopId, 'vendor_id' => $vendorId, 'sub_order_no' => $orderNo . '-' . $seq];

                $db->table('order_items')->insert([
                    'uuid'                   => bin2hex(random_bytes(18)),
                    'sub_order_id'           => $subOrderId,
                    'order_id'               => $orderId,
                    'variant_id'             => (int) $it['variant_id'],
                    'product_title_snapshot' => $it['title'],
                    'sku_snapshot'           => $it['sku'] ?? '',
                    'qty'                    => $this->n((float) $it['qty'], 3),
                    'unit_price'             => $this->n((float) $it['price']),
                    'mrp'                    => $this->n((float) $it['mrp']),
                    'taxable_value'          => $g['taxable'],
                    'tax_rate'               => number_format((float) $it['tax_rate'], 2, '.', ''),
                    'cgst'                   => $g['cgst'],
                    'sgst'                   => $g['sgst'],
                    'igst'                   => $g['igst'],
                    'is_gst_inclusive'       => 1,
                    'line_total'             => $this->n($lineTotal),
                ]);

                // decrement stock at the fulfilling shop (no oversell; floored at 0)
                if ($shopId !== null) {
                    $db->table('inventory')->where('shop_id', $shopId)->where('variant_id', (int) $it['variant_id'])
                        ->set('on_hand', 'GREATEST(on_hand - ' . (float) $it['qty'] . ', 0)', false)->update();
                }
            }

            $db->table('order_addresses')->insert([
                'order_id'          => $orderId,
                'name'              => mb_substr((string) ($address['name'] ?? ''), 0, 191),
                'phone'             => ($address['phone'] ?? '') !== '' ? mb_substr((string) $address['phone'], 0, 20) : null,
                'line1'             => mb_substr((string) ($address['line1'] ?? ''), 0, 191),
                'city'              => mb_substr((string) ($address['city'] ?? ''), 0, 80),
                'state_code'        => $custState,
                'pincode'           => mb_substr((string) ($address['pincode'] ?? ''), 0, 10),
                'formatted_address' => ($address['formatted'] ?? '') !== '' ? mb_substr((string) $address['formatted'], 0, 500) : null,
                'latitude'          => isset($address['lat']) && $address['lat'] !== '' ? (float) $address['lat'] : null,
                'longitude'         => isset($address['lng']) && $address['lng'] !== '' ? (float) $address['lng'] : null,
            ]);

            $db->table('payments')->insert([
                'uuid'            => bin2hex(random_bytes(18)),
                'order_id'        => $orderId,
                'gateway'         => $method === 'cod' ? null : $method,
                'method'          => $method,
                'amount'          => $totals['grand'],
                'currency'        => 'INR',
                'idempotency_key' => 'web-' . $orderNo,
                'captured_at'     => null,            // captured only after gateway verify
                'status'          => 'created',
            ]);

            $db->transComplete();
            if (! $db->transStatus()) {
                return null;
            }

            // Order-placed notification to the customer (fail-safe; queued).
            try {
                $u = $db->table('customers')->select('user_id')->where('id', $customerId)->get()->getRowArray();
                if ($u !== null) {
                    service('notificationService')->notify((int) $u['user_id'], 'order_update', ['order_no' => $orderNo, 'status' => 'confirmed']);
                }
            } catch (Throwable) {
            }

            // T+0: notify the fulfilling shop's staff + vendor owner so they accept it.
            try {
                $this->notifyShopNewOrder($db, $placedSubs);
            } catch (Throwable) {
            }

            return $orderNo;
        } catch (Throwable) {
            $db->transRollback();

            return null;
        }
    }

    /** @return array<string,mixed>|null Order header + sub-orders for tracking. */
    public function track(string $orderNo): ?array
    {
        $order = Database::connect()->table('orders')
            ->where('order_no', $orderNo)->where('deleted_at', null)
            ->get()->getRowArray();
        if ($order === null) {
            return null;
        }
        $db = Database::connect();
        // Per-product (sub-order) state + its live delivery + assigned rider, so the
        // customer app can show a per-item timeline like Blinkit/Zomato.
        $subs = $db->table('sub_orders so')
            ->select('so.id, so.sub_order_no, so.status, so.grand_total, so.delivered_at, so.delivery_otp, v.display_name AS vendor')
            ->join('vendors v', 'v.id = so.vendor_id', 'left')
            ->where('so.order_id', $order['id'])
            ->get()->getResultArray();

        // place() creates one sub-order PER PRODUCT, so the old per-sub-order loop cost
        // 1 + 3×N queries — and this backs the customer app's live tracking screen,
        // POLLED while an order is in flight. Batch the two cheap lookups (items,
        // invoice id) with whereIn(); keep the delivery query per sub-order — it needs
        // ORDER BY d.id DESC LIMIT 1 semantics per row and is genuinely awkward to batch.
        $subIds       = array_column($subs, 'id');
        $itemsBySub   = [];
        $invoiceBySub = [];
        if ($subIds !== []) {
            // Explicit ORDER BY id matches the natural PK order the app already saw
            // from the unordered per-sub-order query.
            foreach ($db->table('order_items')
                ->select('id AS order_item_id, sub_order_id, product_title_snapshot, sku_snapshot, qty, unit_price, status')
                ->whereIn('sub_order_id', $subIds)->orderBy('id', 'ASC')
                ->get()->getResultArray() as $it) {
                $sid = (int) $it['sub_order_id'];
                unset($it['sub_order_id']); // not part of the original per-item payload shape
                $itemsBySub[$sid][] = $it;
            }
            // If a sub-order ever has more than one invoice, the ORIGINAL single-row
            // query took whichever MySQL returned first (no ORDER BY); this map takes
            // the last one iterated — same "arbitrary pick" contract, not a behaviour
            // guarantee either way.
            foreach ($db->table('invoices')->select('id, sub_order_id')
                ->whereIn('sub_order_id', $subIds)->get()->getResultArray() as $inv) {
                $invoiceBySub[(int) $inv['sub_order_id']] = (int) $inv['id'];
            }
        }

        foreach ($subs as &$s) {
            // Only expose OTP at delivery handoff — mask for all other statuses
            if ($s['status'] !== 'out_for_delivery') {
                $s['delivery_otp'] = null;
            }
            $dl = $db->table('deliveries d')
                ->select('d.id, d.status AS delivery_status, d.eta_at, d.delivered_at, d.pod_type, da.rider_user_id, u.name AS rider_name, u.phone AS rider_phone, dbx.current_lat AS rider_lat, dbx.current_lng AS rider_lng')
                ->join('delivery_assignments da', "da.delivery_id = d.id AND da.status = 'accepted'", 'left')
                ->join('users u', 'u.id = da.rider_user_id', 'left')
                ->join('delivery_boys dbx', 'dbx.user_id = da.rider_user_id', 'left')
                ->where('d.sub_order_id', $s['id'])->where('d.deleted_at', null)
                ->orderBy('d.id', 'DESC')->get()->getRowArray();
            // Clamp the displayed delivery state to the sub-order: a stale/ahead delivery
            // row must never make the customer see "Out for delivery" on a confirmed item.
            // Also suppress the ETA until the sub-order is genuinely dispatched, and never
            // surface a past ETA.
            if ($dl !== null) {
                $dl = $this->clampDeliveryToSubOrder($dl, (string) $s['status']);
            }
            $s['items']    = $itemsBySub[(int) $s['id']] ?? [];
            $s['delivery'] = $dl ?: null;
            // Expose the sub-order id (cancel-item endpoint) and order_item ids (return endpoint).
            $s['sub_order_id'] = (int) $s['id'];
            $s['invoice_id']   = $invoiceBySub[(int) $s['id']] ?? null;
            unset($s['id']);
        }
        unset($s);
        $order['sub_orders'] = $subs;
        $order['address']    = $db->table('order_addresses')
            ->select('name, phone, line1, line2, city, state_code, pincode, formatted_address, latitude, longitude')
            ->where('order_id', $order['id'])->where('type', 'shipping')->get()->getRowArray() ?: null;

        return $order;
    }

    /**
     * Rank of a customer-facing fulfilment stage. Sub-order and delivery statuses are
     * mapped onto one ladder so the displayed delivery state can be clamped to never
     * run ahead of the sub-order the customer is actually looking at.
     */
    private static function stageRank(string $status): int
    {
        return [
            // sub-order statuses
            'created'          => 0, 'confirmed' => 1, 'accepted' => 2, 'packed' => 3,
            'ready'            => 4, 'out_for_delivery' => 5, 'delivered' => 6, 'completed' => 6,
            // delivery statuses (mapped onto the same ladder)
            'pending'          => 1, 'assigned' => 2, 'picked_up' => 4,
            'failed'           => 4, 'returned' => 6, 'cancelled' => 6,
        ][$status] ?? 0;
    }

    /**
     * Clamp a delivery row so the customer never sees a stage ahead of the sub-order,
     * and only sees an ETA once the item is genuinely out for delivery and the ETA is
     * still in the future. Prevents stale delivery rows from showing "Out for delivery
     * · ETA 8 Jun" on an item the vendor has not dispatched.
     *
     * @param array<string,mixed> $dl
     * @return array<string,mixed>
     */
    private function clampDeliveryToSubOrder(array $dl, string $subStatus): array
    {
        $subRank = self::stageRank($subStatus);
        $delRank = self::stageRank((string) ($dl['delivery_status'] ?? ''));
        if ($delRank > $subRank) {
            // Delivery row is ahead of the sub-order — present the sub-order's stage.
            $dl['delivery_status'] = $subStatus;
            $dl['delivered_at']    = null;
        }
        // Only show an ETA once dispatched, and never a past ETA.
        $dispatched = self::stageRank($subStatus) >= self::stageRank('out_for_delivery');
        $etaTs      = ! empty($dl['eta_at']) ? strtotime((string) $dl['eta_at']) : false;
        if (! $dispatched || $etaTs === false || $etaTs < time()) {
            $dl['eta_at'] = null;
        }

        return $dl;
    }

    /**
     * Order + payment summary for the gateway pay page (amount, status, gateway
     * ref + the buyer's contact for the checkout prefill).
     * @return array<string,mixed>|null
     */
    public function paymentSummary(string $orderNo): ?array
    {
        $row = Database::connect()->table('orders o')
            ->select('o.id, o.order_no, o.grand_total, o.payment_status, o.customer_id, p.id AS payment_id, p.method, p.gateway, p.gateway_ref, p.status AS pay_status, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone')
            ->join('payments p', 'p.order_id = o.id', 'left')
            ->join('customers c', 'c.id = o.customer_id', 'left')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->where('o.order_no', $orderNo)->where('o.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** Store the gateway's order/intent reference against the order's payment row. */
    public function setGatewayRef(string $orderNo, string $gatewayRef): bool
    {
        $db    = Database::connect();
        $order = $db->table('orders')->select('id')->where('order_no', $orderNo)->get()->getRowArray();
        if ($order === null) {
            return false;
        }

        return (bool) $db->table('payments')->where('order_id', $order['id'])->update(['gateway_ref' => mb_substr($gatewayRef, 0, 100)]);
    }

    /** Mark an order paid after the gateway verified the payment (idempotent). */
    public function markPaid(string $orderNo, string $paymentRef): bool
    {
        $db    = Database::connect();
        $order = $db->table('orders')->select('id, payment_status')->where('order_no', $orderNo)->where('deleted_at', null)->get()->getRowArray();
        if ($order === null) {
            return false;
        }
        // Idempotency: a retried/duplicate gateway callback must not re-capture.
        if (($order['payment_status'] ?? '') === 'paid') {
            return true;
        }
        $now = date('Y-m-d H:i:s');
        $db->table('payments')->where('order_id', $order['id'])->update([
            'status' => 'captured', 'captured_at' => $now, 'gateway_ref' => mb_substr($paymentRef, 0, 100),
        ]);
        $db->table('orders')->where('id', $order['id'])->update(['payment_status' => 'paid']);

        return true;
    }

    /** @return list<array<string,mixed>> */
    /** @return array{items: list<array<string,mixed>>, total: int} */
    public function forCustomer(int $customerId, int $page = 1, int $perPage = 20): array
    {
        $db     = Database::connect();
        $base   = $db->table('orders')->where('customer_id', $customerId)->where('deleted_at', null);
        $total  = (clone $base)->countAllResults(false);
        $offset = ($page - 1) * $perPage;
        $items  = $base->select('id, order_no, grand_total, payment_status, status, created_at')
            ->orderBy('created_at', 'DESC')->limit($perPage, $offset)
            ->get()->getResultArray();

        return ['items' => $items, 'total' => (int) $total];
    }

    /** Does this order belong to this customer? (ownership gate for order pages) */
    public function customerOwns(string $orderNo, int $customerId): bool
    {
        if ($orderNo === '' || $customerId <= 0) {
            return false;
        }

        return Database::connect()->table('orders')->select('id')
            ->where('order_no', $orderNo)->where('customer_id', $customerId)->where('deleted_at', null)
            ->get()->getRowArray() !== null;
    }

    /**
     * Try to CLAIM an idempotency key for this customer (insert-first). Returns:
     *  - ['state'=>'claimed']              → caller owns it, proceed to place the order
     *  - ['state'=>'replay','response'=>…] → a completed prior request; replay its response
     *  - ['state'=>'in_progress']          → a concurrent request holds the claim (no order yet)
     * The UNIQUE(customer_id, idem_key) makes this race-safe even under cache failure.
     * @return array<string,mixed>
     */
    public function claimIdempotency(int $customerId, string $key): array
    {
        $db = Database::connect();
        try {
            $db->table('api_idempotency_keys')->insert(['customer_id' => $customerId, 'idem_key' => mb_substr($key, 0, 80)]);

            return ['state' => 'claimed'];
        } catch (Throwable) {
            // Duplicate key — a prior/concurrent request already claimed it.
            $row = $db->table('api_idempotency_keys')->select('order_no, response')
                ->where('customer_id', $customerId)->where('idem_key', mb_substr($key, 0, 80))->get()->getRowArray();
            if ($row !== null && ($row['order_no'] ?? null) !== null) {
                return ['state' => 'replay', 'response' => json_decode((string) ($row['response'] ?? 'null'), true)];
            }

            return ['state' => 'in_progress'];
        }
    }

    /** Persist the placed order + response against a claimed idempotency key (for replay). */
    public function completeIdempotency(int $customerId, string $key, string $orderNo, array $response): void
    {
        Database::connect()->table('api_idempotency_keys')
            ->where('customer_id', $customerId)->where('idem_key', mb_substr($key, 0, 80))
            ->update(['order_no' => $orderNo, 'response' => json_encode($response)]);
    }

    /** Release a claimed key when placement fails validation, so the client can retry. */
    public function releaseIdempotency(int $customerId, string $key): void
    {
        Database::connect()->table('api_idempotency_keys')
            ->where('customer_id', $customerId)->where('idem_key', mb_substr($key, 0, 80))->where('order_no', null)
            ->delete();
    }

    /** Full order header + sub-orders + items for the customer order-detail page. @return array<string,mixed>|null */
    public function detailForCustomer(string $orderNo, int $customerId): ?array
    {
        $db    = Database::connect();
        $order = $db->table('orders')->where('order_no', $orderNo)->where('customer_id', $customerId)->where('deleted_at', null)->get()->getRowArray();
        if ($order === null) {
            return null;
        }
        $order['sub_orders'] = $db->table('sub_orders so')
            ->select('so.id, so.sub_order_no, so.status, so.grand_total, so.updated_at, v.display_name AS vendor')
            ->join('vendors v', 'v.id = so.vendor_id', 'left')
            ->where('so.order_id', $order['id'])->get()->getResultArray();
        $order['items'] = $db->table('order_items oi')
            ->select('oi.id, oi.sub_order_id, oi.product_title_snapshot AS title, oi.sku_snapshot AS sku, oi.qty, oi.unit_price, (oi.unit_price * oi.qty) AS line_total, oi.status, so.status AS sub_status')
            ->join('sub_orders so', 'so.id = oi.sub_order_id', 'left')
            ->where('oi.order_id', $order['id'])->get()->getResultArray();

        return $order;
    }

    /**
     * Customer-initiated cancellation — vertical-aware timing; initiates a refund
     * (admin processes it) for paid orders. @return array{ok:bool,reason?:string}
     */
    public function cancel(string $orderNo, int $customerId): array
    {
        $db    = Database::connect();
        $order = $db->table('orders')->where('order_no', $orderNo)->where('customer_id', $customerId)->where('deleted_at', null)->get()->getRowArray();
        if ($order === null) {
            return ['ok' => false, 'reason' => 'Order not found.'];
        }
        if (! in_array($order['status'], ['created', 'confirmed'], true)) {
            return ['ok' => false, 'reason' => 'This order can no longer be cancelled.'];
        }
        $vertical = (string) ($order['vertical'] ?? 'goods');
        $lock     = \App\Libraries\Store\Vertical::cancelLockStatuses($vertical);
        $subs     = $db->table('sub_orders')->select('status')->where('order_id', $order['id'])->get()->getResultArray();
        foreach ($subs as $s) {
            if (in_array($s['status'], $lock, true)) {
                return ['ok' => false, 'reason' => $vertical === 'food'
                    ? 'The restaurant has started preparing this order — it can no longer be cancelled.'
                    : 'This order is already on its way and can no longer be cancelled.'];
            }
        }
        $db->table('sub_orders')->where('order_id', $order['id'])
            ->whereNotIn('status', ['out_for_delivery', 'delivered', 'completed', 'cancelled', 'returned'])
            ->update(['status' => 'cancelled']);
        $db->table('orders')->where('id', $order['id'])->update(['status' => 'cancelled']);

        if (($order['payment_status'] ?? '') === 'paid') {
            $this->initiateRefund($db, (int) $order['id'], (float) $order['grand_total'], 'cancellation', null, $orderNo);
        }

        return ['ok' => true];
    }

    /**
     * Customer-initiated return of delivered items. Creates a returns row + return_items
     * per sub-order and initiates a refund (admin processes). $items = [order_item_id => qty].
     * @param array<int,float> $items @return array{ok:bool,reason?:string}
     */
    public function createReturn(string $orderNo, int $customerId, array $items, string $reason): array
    {
        $db    = Database::connect();
        $order = $db->table('orders')->where('order_no', $orderNo)->where('customer_id', $customerId)->where('deleted_at', null)->get()->getRowArray();
        if ($order === null) {
            return ['ok' => false, 'reason' => 'Order not found.'];
        }
        $rows = $db->table('order_items')->select('id, sub_order_id, qty, unit_price, cgst, sgst, igst')
            ->where('order_id', $order['id'])->get()->getResultArray();
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $bySub = [];
        foreach ($items as $oiId => $q) {
            $oiId = (int) $oiId;
            $q    = (float) $q;
            if ($q > 0 && isset($byId[$oiId])) {
                $bySub[(int) $byId[$oiId]['sub_order_id']][$oiId] = min($q, (float) $byId[$oiId]['qty']);
            }
        }
        if ($bySub === []) {
            return ['ok' => false, 'reason' => 'Select at least one item to return.'];
        }

        // Server-side return gates (the web only gated these in the view; the API had
        // no gate at all): the vertical must allow returns, and each sub-order must be
        // DELIVERED and still inside its return window.
        $vertical = (string) ($order['vertical'] ?? 'goods');
        if (! \App\Libraries\Store\Vertical::canReturn($vertical)) {
            return ['ok' => false, 'reason' => 'Returns are not available for this order.'];
        }
        $windowDays = \App\Libraries\Store\Vertical::returnWindowDays($vertical);
        $subRows    = $db->table('sub_orders')->select('id, status, delivered_at, updated_at')
            ->whereIn('id', array_map('intval', array_keys($bySub)))->where('order_id', $order['id'])
            ->get()->getResultArray();
        $subById = [];
        foreach ($subRows as $sr) {
            $subById[(int) $sr['id']] = $sr;
        }
        foreach (array_keys($bySub) as $subId) {
            $sr = $subById[(int) $subId] ?? null;
            if ($sr === null) {
                return ['ok' => false, 'reason' => 'Item not found in this order.'];
            }
            if (! in_array($sr['status'], ['delivered', 'completed'], true)) {
                return ['ok' => false, 'reason' => 'Only delivered items can be returned.'];
            }
            // Window is measured from delivery; older delivered orders may have no
            // delivered_at, so fall back to the sub-order's last update before failing.
            $deliveredAt = $sr['delivered_at'] ? strtotime((string) $sr['delivered_at'])
                : ($sr['updated_at'] ? strtotime((string) $sr['updated_at']) : null);
            if ($deliveredAt === null || (time() - $deliveredAt) > $windowDays * 86400) {
                return ['ok' => false, 'reason' => 'The return window for this item has closed.'];
            }
        }

        $db->transBegin();
        try {
            foreach ($bySub as $subId => $lines) {
                $db->table('returns')->insert([
                    'uuid'          => bin2hex(random_bytes(18)),
                    'sub_order_id'  => $subId,
                    'order_id'      => (int) $order['id'],
                    'customer_id'   => $customerId,
                    'channel'       => 'online',
                    'refund_to'     => 'source',
                    'status'        => 'requested',
                    'customer_note' => mb_substr($reason, 0, 500),
                ]);
                $retId       = (int) $db->insertID();
                $refundTotal = 0.0;
                foreach ($lines as $oiId => $q) {
                    $r      = $byId[$oiId];
                    $amt    = round((float) $r['unit_price'] * $q, 2);
                    $taxRev = ((float) $r['cgst'] + (float) $r['sgst'] + (float) $r['igst']) * ($q / max(0.001, (float) $r['qty']));
                    $db->table('return_items')->insert([
                        'return_id'     => $retId,
                        'order_item_id' => $oiId,
                        'qty'           => number_format($q, 3, '.', ''),
                        'refund_amount' => number_format($amt, 4, '.', ''),
                        'tax_reversed'  => number_format($taxRev, 4, '.', ''),
                        'condition'     => 'resaleable',
                        'status'        => 'pending',
                    ]);
                    $refundTotal += $amt;
                }
                if (($order['payment_status'] ?? '') === 'paid') {
                    $this->initiateRefund($db, (int) $order['id'], $refundTotal, 'return', $retId, $orderNo);
                }
            }
            $db->transComplete();

            return $db->transStatus() ? ['ok' => true] : ['ok' => false, 'reason' => 'Could not submit the return. Please try again.'];
        } catch (Throwable) {
            $db->transRollback();

            return ['ok' => false, 'reason' => 'Could not submit the return. Please try again.'];
        }
    }

    /** Create a refund row (status 'initiated') for admin to process via RefundService. */
    private function initiateRefund($db, int $orderId, float $amount, string $kind, ?int $returnId, string $orderNo, ?int $subOrderId = null): void
    {
        if ($amount <= 0) {
            return;
        }
        $pay = $db->table('payments')->select('id, method')->where('order_id', $orderId)->get()->getRowArray();
        if ($pay === null) {
            return;
        }
        // Idempotency key is unique per (kind, order, return, sub-order) so per-product
        // cancellations never collide with each other or with a whole-order refund.
        $key = $kind . '-' . $orderNo . ($returnId ? '-r' . $returnId : '') . ($subOrderId ? '-s' . $subOrderId : '');
        $db->table('refunds')->insert([
            'uuid'            => bin2hex(random_bytes(18)),
            'payment_id'      => (int) $pay['id'],
            'return_id'       => $returnId,
            'amount'          => number_format($amount, 4, '.', ''),
            'destination'     => ($pay['method'] ?? '') === 'cod' ? 'cash' : 'source',
            'idempotency_key' => mb_substr($key, 0, 64),
            'status'          => 'initiated',
            'kind'            => $kind,
        ]);
    }

    /**
     * Per-product cancellation — cancels ONE sub-order (one product) of an order,
     * initiating a proportional refund for that product when the order is paid.
     * The rest of the order is untouched. @return array{ok:bool,reason?:string}
     */
    public function cancelSubOrder(string $orderNo, int $subOrderId, int $customerId): array
    {
        $db    = Database::connect();
        $order = $db->table('orders')->where('order_no', $orderNo)->where('customer_id', $customerId)->where('deleted_at', null)->get()->getRowArray();
        if ($order === null) {
            return ['ok' => false, 'reason' => 'Order not found.'];
        }
        $so = $db->table('sub_orders')->select('id, status, grand_total')
            ->where('id', $subOrderId)->where('order_id', (int) $order['id'])->get()->getRowArray();
        if ($so === null) {
            return ['ok' => false, 'reason' => 'Item not found in this order.'];
        }
        if (in_array($so['status'], ['cancelled', 'returned'], true)) {
            return ['ok' => false, 'reason' => 'This item has already been cancelled.'];
        }
        if (in_array($so['status'], ['out_for_delivery', 'delivered', 'completed'], true)) {
            return ['ok' => false, 'reason' => 'This item is already on its way and can no longer be cancelled.'];
        }

        $db->transBegin();
        try {
            $db->table('sub_orders')->where('id', $subOrderId)->update(['status' => 'cancelled']);
            $db->table('order_items')->where('sub_order_id', $subOrderId)->update(['status' => 'cancelled']);
            if (($order['payment_status'] ?? '') === 'paid') {
                $this->initiateRefund($db, (int) $order['id'], (float) $so['grand_total'], 'cancellation', null, $orderNo, $subOrderId);
            }
            $this->resyncOrderStatus($db, (int) $order['id']);
            $db->transComplete();

            return $db->transStatus() ? ['ok' => true] : ['ok' => false, 'reason' => 'Could not cancel the item. Please try again.'];
        } catch (Throwable) {
            $db->transRollback();

            return ['ok' => false, 'reason' => 'Could not cancel the item. Please try again.'];
        }
    }

    /**
     * Recompute the parent order's status after a sub-order changes: all sub-orders
     * cancelled → order cancelled; some cancelled → partially_fulfilled (only while
     * the order is still pre-fulfilment, so a richer status is never overwritten).
     */
    private function resyncOrderStatus($db, int $orderId): void
    {
        $subs = $db->table('sub_orders')->select('status')->where('order_id', $orderId)->get()->getResultArray();
        if ($subs === []) {
            return;
        }
        $total     = count($subs);
        $cancelled = 0;
        foreach ($subs as $s) {
            if ($s['status'] === 'cancelled') {
                $cancelled++;
            }
        }
        if ($cancelled === $total) {
            $db->table('orders')->where('id', $orderId)->update(['status' => 'cancelled']);
        } elseif ($cancelled > 0) {
            $db->table('orders')->where('id', $orderId)
                ->whereIn('status', ['created', 'confirmed', 'partially_fulfilled'])
                ->update(['status' => 'partially_fulfilled']);
        }
    }

    private function vendorShop($db, int $vendorId): ?int
    {
        $row = $db->table('shops')->select('id')->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->orderBy('id', 'ASC')->get()->getRowArray();

        return $row ? (int) $row['id'] : null;
    }

    /**
     * T+0 new-order notification to the fulfilling shop's active staff + the vendor
     * owner, so the order is picked up promptly (before escalation kicks in).
     *
     * @param list<array{shop_id:?int,vendor_id:int,sub_order_no:string}> $placedSubs
     */
    private function notifyShopNewOrder($db, array $placedSubs): void
    {
        $svc = service('notificationService');
        foreach ($placedSubs as $s) {
            $userIds = [];
            // Active staff assigned to this shop.
            if (! empty($s['shop_id'])) {
                $rows = $db->table('vendor_staff vs')
                    ->select('vs.user_id')
                    ->join('staff_shop_assignments ssa', "ssa.vendor_staff_id = vs.id AND ssa.status = 'active' AND ssa.deleted_at IS NULL", 'inner')
                    ->where('ssa.shop_id', (int) $s['shop_id'])->where('vs.status', 'active')
                    ->get()->getResultArray();
                foreach ($rows as $r) {
                    $userIds[] = (int) $r['user_id'];
                }
            }
            // Vendor owner.
            $owner = $db->table('vendors')->select('owner_user_id')->where('id', (int) $s['vendor_id'])->get()->getRowArray();
            if ($owner !== null && ! empty($owner['owner_user_id'])) {
                $userIds[] = (int) $owner['owner_user_id'];
            }
            foreach (array_unique($userIds) as $uid) {
                $svc->notify($uid, 'order.new', ['sub_order_no' => $s['sub_order_no']]);
            }
        }
    }

    /** Return requests submitted by the customer for a given order, including per-item breakdown. */
    public function returnsForOrder(string $orderNo, int $customerId): array
    {
        $db    = Database::connect();
        $order = $db->table('orders')->select('id')->where('order_no', $orderNo)->where('customer_id', $customerId)->where('deleted_at', null)->get()->getRowArray();
        if ($order === null) {
            return [];
        }
        $returns = $db->table('returns r')
            ->select('r.id, r.uuid, r.status, r.customer_note, r.refund_to, r.channel, r.created_at, r.updated_at')
            ->where('r.order_id', (int) $order['id'])
            ->where('r.customer_id', $customerId)
            ->orderBy('r.id', 'DESC')
            ->get()->getResultArray();

        foreach ($returns as &$ret) {
            $ret['items'] = $db->table('return_items ri')
                ->select('ri.qty, ri.refund_amount, oi.title, oi.sku')
                ->join('order_items oi', 'oi.id = ri.order_item_id', 'left')
                ->where('ri.return_id', (int) $ret['id'])
                ->get()->getResultArray();
            unset($ret['id']); // expose uuid only
        }
        unset($ret);

        return $returns;
    }

    private function shopState($db, ?int $shopId): ?string
    {
        if ($shopId === null) {
            return null;
        }
        $row = $db->table('shops')->select('state_code')->where('id', $shopId)->get()->getRowArray();

        return $row['state_code'] ?? null;
    }

    private function n(float $v, int $dp = 4): string
    {
        return number_format(round($v, $dp), $dp, '.', '');
    }
}
