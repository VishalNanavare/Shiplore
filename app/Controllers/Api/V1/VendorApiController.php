<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;

/**
 * VendorApiController — Vendor app API. JWT-authenticated; the acting vendor is
 * resolved from the logged-in user (the vendor they own) so every read/write is
 * tenant-isolated. Dashboard, products, orders and order-status transitions.
 *
 * @see docs/architecture/27-VENDOR-APP.md
 */
final class VendorApiController extends BaseApiController
{
    private const SUB_STATUSES = ['confirmed', 'accepted', 'packed', 'ready', 'out_for_delivery', 'delivered', 'cancelled'];

    public function dashboard()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $repo = service('vendorDashboardRepository');

        return $this->ok([
            'metrics'      => $repo->metrics($vid),
            'recentOrders' => $repo->recentOrders($vid),
        ]);
    }

    /** Analytics summary with date range: revenue, orders, AOV, top products. */
    public function analytics()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }

        $from = $this->request->getGet('from') ?: date('Y-m-d', strtotime('-29 days'));
        $to   = $this->request->getGet('to')   ?: date('Y-m-d');

        $db = db_connect();

        // Revenue + order count over date range
        $row = $db->table('sub_orders so')
            ->join('orders o', 'o.id = so.order_id')
            ->selectSum('so.total', 'revenue')
            ->selectCount('so.id', 'orders')
            ->where('so.vendor_id', $vid)
            ->where('so.status !=', 'cancelled')
            ->where("DATE(o.created_at) >=", $from)
            ->where("DATE(o.created_at) <=", $to)
            ->get()->getRowArray();

        $revenue = (float)($row['revenue'] ?? 0);
        $orders  = (int)($row['orders']  ?? 0);
        $aov     = $orders > 0 ? round($revenue / $orders, 2) : 0;

        // Daily revenue for chart
        $chart = $db->table('sub_orders so')
            ->join('orders o', 'o.id = so.order_id')
            ->select("DATE(o.created_at) as date, SUM(so.total) as revenue, COUNT(so.id) as orders")
            ->where('so.vendor_id', $vid)
            ->where('so.status !=', 'cancelled')
            ->where("DATE(o.created_at) >=", $from)
            ->where("DATE(o.created_at) <=", $to)
            ->groupBy("DATE(o.created_at)")
            ->orderBy("DATE(o.created_at)", 'ASC')
            ->get()->getResultArray();

        // Top 5 products
        $top = $db->table('sub_order_items soi')
            ->join('sub_orders so', 'so.id = soi.sub_order_id')
            ->join('orders o', 'o.id = so.order_id')
            ->join('product_variants pv', 'pv.id = soi.variant_id', 'left')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->select("p.title, SUM(soi.qty) as units, SUM(soi.total) as revenue")
            ->where('so.vendor_id', $vid)
            ->where('so.status !=', 'cancelled')
            ->where("DATE(o.created_at) >=", $from)
            ->where("DATE(o.created_at) <=", $to)
            ->groupBy("p.id")
            ->orderBy("units", 'DESC')
            ->limit(5)
            ->get()->getResultArray();

        return $this->ok([
            'from'    => $from,
            'to'      => $to,
            'revenue' => $revenue,
            'orders'  => $orders,
            'aov'     => $aov,
            'chart'   => $chart,
            'top'     => $top,
        ]);
    }

    public function products()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $items = service('vendorProductRepository')->list($vid, $this->request->getGet('status'));

        return $this->collection($items, ['total' => count($items)]);
    }

    /** Vendor-scoped variants (all statuses + stock) for one of the vendor's products. */
    public function variants(int $productId)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $variants = service('vendorProductRepository')->variants($productId, $vid);
        if ($variants === null) {
            return $this->failWith('NOT_FOUND', 'Product not found.');
        }

        return $this->collection($variants, ['total' => count($variants)]);
    }

    public function orders()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $page     = max(1, (int) $this->request->getGet('page'));
        $per      = min(100, max(1, (int) ($this->request->getGet('per_page') ?: 30)));
        $status   = $this->request->getGet('status');
        $q        = $this->request->getGet('q') ?: null;
        $dateFrom = $this->request->getGet('date_from') ?: null;
        $dateTo   = $this->request->getGet('date_to')   ?: null;
        $shop     = $this->shopScope(); // null = owner (all shops); a shop id = staff branch scope
        $repo     = service('vendorOrderRepository');
        $items    = $repo->list($vid, $status, $shop, $per, ($page - 1) * $per, $q, $dateFrom, $dateTo);
        $total    = $repo->count($vid, $status, $shop, $q, $dateFrom, $dateTo);

        return $this->collection($items, [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil($total / $per),
        ]);
    }

    public function exportOrders()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $dateFrom = $this->request->getGet('date_from') ?: null;
        $dateTo   = $this->request->getGet('date_to')   ?: null;
        $status   = $this->request->getGet('status')    ?: null;

        $db = \Config\Database::connect();
        $b  = $db->table('sub_orders so')
            ->select('so.sub_order_no AS "Order No", o.order_no AS "Parent Order", so.grand_total AS "Total", so.status AS "Status", u.name AS "Customer", u.phone AS "Phone", s.name AS "Shop", so.created_at AS "Date"', false)
            ->join('orders o', 'o.id = so.order_id', 'left')
            ->join('shops s', 's.id = so.shop_id', 'left')
            ->join('customers c', 'c.id = o.customer_id', 'left')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->where('so.vendor_id', $vid)
            ->where('so.deleted_at', null)
            ->orderBy('so.created_at', 'DESC');

        if ($status !== null && $status !== '') {
            $b->where('so.status', $status);
        }
        if ($dateFrom !== null) {
            $b->where('DATE(so.created_at) >=', $dateFrom);
        }
        if ($dateTo !== null) {
            $b->where('DATE(so.created_at) <=', $dateTo);
        }

        $rows = $b->get()->getResultArray();

        $headers = empty($rows) ? ['Order No', 'Parent Order', 'Total', 'Status', 'Customer', 'Phone', 'Shop', 'Date'] : array_keys($rows[0]);
        $xlsx    = \App\Libraries\Xlsx\SimpleXlsx::build($headers, $rows);

        $filename = 'orders_' . date('Ymd_His') . '.xlsx';
        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($xlsx);
    }

    public function order(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $repo = service('vendorOrderRepository');
        $sub  = $repo->findSubOrder($id, $vid);
        if ($sub === null || ! $this->inShopScope((int) $sub['shop_id'])) {
            return $this->failWith('NOT_FOUND', 'Order not found.');
        }
        $sub['items']    = $repo->items($id);
        $sub['delivery'] = $repo->delivery($id);
        $sub['returns']  = $repo->returns($id);
        $sub['address']  = $repo->deliveryAddress((int) $sub['order_id']);
        // The delivery OTP is the CUSTOMER's proof of handoff — never expose it to the
        // vendor. The vendor only enters what the customer reads out (verifyDeliveryOtp).
        unset($sub['delivery_otp']);

        return $this->ok($sub);
    }

    public function orderStatus(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $status = (string) ($this->input()['status'] ?? '');
        if (! in_array($status, self::SUB_STATUSES, true)) {
            return $this->failWith('VALIDATION_ERROR', 'Invalid sub-order status.');
        }
        $repo = service('vendorOrderRepository');
        $sub  = $repo->findSubOrder($id, $vid);
        if ($sub === null || ! $this->inShopScope((int) $sub['shop_id'])) {
            return $this->failWith('NOT_FOUND', 'Order not found.');
        }
        if (! \App\Libraries\Workflow\StatusMachine::canSubOrder((string) $sub['status'], $status)) {
            return $this->failWith('CONFLICT', "Cannot move a {$sub['status']} order to {$status}.");
        }
        $actorRole = (service('vendorAccountRepository')->findByOwnerUserId($this->userId()) !== null) ? 'vendor' : 'shop';
        $result    = $repo->updateSubOrderStatus($id, $vid, $status, $actorRole, $this->userId(), (string) $this->request->getIPAddress(), (string) $this->request->getUserAgent());

        if (! $result['ok']) {
            return match ($result['reason'] ?? '') {
                'locked' => $this->respond([
                    'error'       => 'order_locked',
                    'locked_by'   => $result['owner']['role'] ?? null,
                    'locked_user' => $result['owner']['user_name'] ?? null,
                    'expires_in'  => $result['owner']['expires_in_seconds'] ?? 0,
                    'message'     => 'Order is being handled by ' . ($result['owner']['label'] ?? 'another user') . '.',
                ], 409),
                'escalation_blocked' => $this->respond([
                    'error'           => 'escalation_blocked',
                    'current_level'   => $result['escalation_level'] ?? null,
                    'message'         => 'Order has been escalated. Only ' . ucfirst((string) ($result['escalation_level'] ?? '')) . ' can act on it.',
                ], 403),
                'rider_controls' => $this->respond([
                    'error'   => 'rider_controls',
                    'message' => 'A delivery rider is handling this trip — they will mark it delivered.',
                ], 409),
                'otp_unverified' => $this->respond([
                    'error'   => 'otp_unverified',
                    'message' => 'Verify the customer delivery OTP before marking this order delivered.',
                ], 409),
                default => $this->failWith('UPDATE_FAILED', 'Could not update order status.'),
            };
        }

        if (in_array($status, ['out_for_delivery', 'delivered'], true)) {
            // X2b: GST invoice is issued at dispatch (idempotent on replay).
            try {
                service('invoiceService')->generateForSubOrder($id, $this->userId());
            } catch (\Throwable) {
            }
        }
        if ($status === 'delivered') {
            // X2a: start the commission hold window. Must never block fulfilment.
            try {
                service('commissionHoldService')->holdForSubOrderId($id);
            } catch (\Throwable) {
            }
        }

        return $this->ok(['message' => 'Status updated to ' . $status]);
    }

    /** Keep order claim alive while the vendor's order-detail page is open (ping every 60 s). */
    public function orderHeartbeat(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $refreshed = service('orderClaimService')->heartbeat($id, $this->userId());

        return $this->ok(['refreshed' => $refreshed]);
    }

    /**
     * Shop scope for order reads: null = owner (every shop); a shop id = a staffer's
     * branch (mirrors the web's effectiveShopId). -1 means a staffer with no shop —
     * they see nothing, never the whole vendor's customer orders.
     */
    private function shopScope(): ?int
    {
        $repo = service('vendorAccountRepository');
        if ($repo->findByOwnerUserId($this->userId()) !== null) {
            return null;
        }
        $s = $repo->findStaffVendor($this->userId());
        if ($s !== null) {
            $shops = $repo->shopIdsForStaff((int) $s['vendor_staff_id']);

            return $shops[0] ?? -1;
        }

        return null;
    }

    /** Is a sub-order's shop within the caller's scope? (owner: always; staff: their branch). */
    private function inShopScope(int $shopId): bool
    {
        $scope = $this->shopScope();

        return $scope === null || $scope === $shopId;
    }

    /**
     * Does this variant AND this shop belong to the caller's vendor?
     *
     * inShopScope() alone is NOT an ownership check: shopScope() returns null for any
     * vendor owner, so `$scope === null` makes it true for EVERY shop id, including
     * another tenant's. Endpoints that take shop_id/variant_id from the request body
     * and write must therefore verify tenancy separately — InventoryService takes
     * (variantId, shopId) with no vendor predicate and its ensureRow() will INSERT a
     * fresh inventory row, so an unchecked call writes into another vendor's stock.
     *
     * Mirrors Vendor\ProductInventoryController::ownVariantShop() on the web side and
     * the `join shops ... where s.vendor_id = $vid` idiom already used by
     * setReorderLevel() and inventoryMovements() in this class.
     *
     * Deliberately does NOT require an existing `inventory` row: receive() legitimately
     * creates one for a vendor's own shop+variant.
     */
    private function ownsVariantAndShop(int $variantId, int $shopId, int $vid): bool
    {
        $db = \Config\Database::connect();

        $shopOk = $db->table('shops')
            ->where('id', $shopId)->where('vendor_id', $vid)->where('deleted_at', null)
            ->countAllResults() > 0;
        if (! $shopOk) {
            return false;
        }

        return $db->table('product_variants')
            ->where('id', $variantId)->where('vendor_id', $vid)->where('deleted_at', null)
            ->countAllResults() > 0;
    }

    private function vendorId(): ?int
    {
        $repo = service('vendorAccountRepository');
        // Owner first, then vendor staff / branch managers (parity with the web panel —
        // staff were previously locked out of the vendor API entirely).
        $v = $repo->findByOwnerUserId($this->userId()) ?? $repo->findStaffVendor($this->userId());

        return $v !== null ? (int) $v['id'] : null;
    }

    /**
     * Re-issue a fresh JWT for the current vendor/staff user (sliding session).
     * Mirrors AuthApiController::refresh() but lives on the vendor auth path so
     * the vendor app never has to touch the customer auth route.
     */
    public function refresh()
    {
        $uid = (int) service('scopeContext')->actorId();
        if ($uid <= 0) {
            return $this->failWith('UNAUTHENTICATED', 'Invalid session.');
        }
        $user = service('apiAuthRepository')->findById($uid);
        if ($user === null || $user['status'] !== 'active') {
            return $this->failWith('UNAUTHENTICATED', 'Account not available.');
        }
        $repo = service('vendorAccountRepository');
        if ($repo->findByOwnerUserId($uid) === null && $repo->findStaffVendor($uid) === null) {
            return $this->notVendor();
        }

        // Was env('JWT_SECRET', '') — the ONLY mint site that did not fail closed. With
        // the variable unset it signed with the empty string, so anyone able to guess
        // that could forge a token for any user. TokenService::secret() throws instead,
        // and also rejects the committed INSECURE_DEFAULT placeholder.
        $secret = \App\Libraries\TokenService::secret();

        // Carry the password-binding claim the other mint sites set, so a password
        // change revokes tokens issued here too. Nullable-tolerant: an account with no
        // password (OTP-only) simply gets no claim, exactly as elsewhere.
        $claims = ['sub' => (int) $user['id'], 'typ' => $user['principal_type'], 'name' => $user['name']];
        $pwd    = \App\Libraries\TokenService::pwdClaim($user['password_hash'] ?? null);
        if ($pwd !== null) {
            $claims['pwd'] = $pwd;
        }

        $token = service('tokenService')->issue($claims, 2_592_000, $secret, time());

        return $this->ok([
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => 2_592_000,
            'user'       => [
                'id'             => (int) $user['id'],
                'name'           => $user['name'],
                'principal_type' => $user['principal_type'],
                'email'          => $user['email'],
                'phone'          => $user['phone'],
            ],
        ]);
    }

    /** Register or refresh an FCM device token for the current vendor/staff user. */
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

    /** DELETE — de-register all FCM tokens for this user (called on logout). */
    public function deregisterDeviceToken()
    {
        service('deviceTokenRepository')->removeForUser($this->userId());

        return $this->ok(['deregistered' => true]);
    }

    /**
     * List the vendor's shops with open/closed status and basic info.
     * Owners see all shops; staff see only their assigned shop(s).
     */
    public function shops()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $scope = $this->shopScope(); // null = owner; shop_id = staff branch; -1 = no shop
        $repo  = service('vendorShopRepository');
        $shops = $scope === null
            ? $repo->list($vid)
            : ($scope > 0 ? [$repo->findById($scope, $vid)] : []);

        return $this->collection(array_values(array_filter($shops)), ['total' => count($shops)]);
    }

    /** Open a shop for new orders. Owner or manager only. */
    public function openShop(int $shopId)
    {
        return $this->setShopStatus($shopId, 'open');
    }

    /** Close a shop to new orders. Owner or manager only. */
    public function closeShop(int $shopId)
    {
        return $this->setShopStatus($shopId, 'closed');
    }

    private function setShopStatus(int $shopId, string $status)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        if (! $this->inShopScope($shopId)) {
            return $this->failWith('FORBIDDEN', 'Not allowed to manage this shop.');
        }
        service('vendorShopRepository')->updateStatus($shopId, $vid, $status, $this->userId());

        return $this->ok(['shop_id' => $shopId, 'status' => $status]);
    }

    /**
     * Inventory list for the vendor's products, optionally scoped to a shop.
     * Staff only see their assigned shop; owners see all.
     * Pass ?low_stock=1 to filter to variants below reorder point.
     */
    public function inventory()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $scope  = $this->shopScope();
        $shopId = (int) $this->request->getGet('shop_id') ?: null;
        // Staff: can only see their own shop.
        if ($scope !== null && $scope > 0) {
            $shopId = $scope;
        } elseif ($scope === -1) {
            return $this->collection([], ['total' => 0]);
        }
        $lowStock = (bool) $this->request->getGet('low_stock');
        $q        = $this->request->getGet('q') ?: null;
        $page     = max(1, (int) $this->request->getGet('page'));
        $per      = min(100, max(1, (int) ($this->request->getGet('per_page') ?: 40)));
        $repo     = service('vendorInventoryRepository');
        $offset   = ($page - 1) * $per;
        $items    = $repo->list($vid, $shopId, $q, $lowStock, $per, $offset);
        $total    = $repo->count($vid, $shopId, $q, $lowStock);

        return $this->collection($items, [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil($total / $per),
        ]);
    }

    /** Receive stock for a variant in a shop (positive delta). Owner or manager. */
    public function inventoryReceive()
    {
        return $this->inventoryLedger('receive');
    }

    /** Adjust stock for a variant in a shop (positive or negative delta). Owner or manager. */
    public function inventoryAdjust()
    {
        return $this->inventoryLedger('adjust');
    }

    private function inventoryLedger(string $type)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $in        = $this->input();
        $variantId = (int) ($in['variant_id'] ?? 0);
        $shopId    = (int) ($in['shop_id'] ?? 0);
        $qty       = (float) ($in['qty'] ?? $in['delta'] ?? 0);
        $reason    = trim((string) ($in['reason'] ?? ''));
        $notes     = trim((string) ($in['notes'] ?? ''));

        if ($variantId <= 0 || $shopId <= 0 || $qty == 0) {
            return $this->failWith('VALIDATION_ERROR', 'variant_id, shop_id and qty/delta are required.');
        }
        if (! $this->inShopScope($shopId)) {
            return $this->failWith('FORBIDDEN', 'Not allowed to adjust stock for this shop.');
        }
        // inShopScope() is a BRANCH check, not a tenancy one — it passes for every shop
        // id when the caller is a vendor owner. Without this, an owner could post
        // another vendor's shop_id/variant_id and write into their stock.
        if (! $this->ownsVariantAndShop($variantId, $shopId, $vid)) {
            return $this->failWith('FORBIDDEN', 'Not allowed to adjust stock for this shop.');
        }

        $svc = service('inventoryService');
        if ($type === 'receive') {
            $svc->receive($variantId, $shopId, $qty, 0.0, ['notes' => $notes ?: $reason], $this->userId());
        } else {
            $svc->adjust($variantId, $shopId, $qty, $reason ?: 'adjustment', $notes, $this->userId());
        }

        return $this->ok(['updated' => true]);
    }

    /** Publish one of the vendor's products (sets status = published). Owner only. */
    public function publishProduct(int $productId)
    {
        return $this->setProductStatus($productId, 'published');
    }

    /** Unpublish one of the vendor's products (sets status = unpublished). Owner only. */
    public function unpublishProduct(int $productId)
    {
        return $this->setProductStatus($productId, 'unpublished');
    }

    private function setProductStatus(int $productId, string $status)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $ok = service('vendorProductRepository')->setStatus($productId, $vid, $status);
        if (! $ok) {
            return $this->failWith('NOT_FOUND', 'Product not found.');
        }

        return $this->ok(['product_id' => $productId, 'status' => $status]);
    }

    /** Update price (and optionally MRP) for one variant. Owner or manager. */
    public function updateVariantPrice(int $productId, int $variantId)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $in    = $this->input();
        $price = isset($in['price']) ? (float) $in['price'] : null;
        $mrp   = isset($in['mrp'])   ? (float) $in['mrp']   : null;

        if ($price === null || $price <= 0) {
            return $this->failWith('VALIDATION_ERROR', 'price must be a positive number.');
        }

        $ok = service('vendorProductRepository')->updateVariantPrice($variantId, $productId, $vid, $price, $mrp);
        if (! $ok) {
            return $this->failWith('NOT_FOUND', 'Variant not found.');
        }

        return $this->ok(['variant_id' => $variantId, 'price' => $price, 'mrp' => $mrp]);
    }

    /**
     * List deliveries for the vendor, scoped to a shop and/or status.
     * Owners see all; staff see only their assigned shop.
     */
    public function deliveries()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $scope  = $this->shopScope();
        $shopId = (int) $this->request->getGet('shop_id') ?: null;
        if ($scope !== null && $scope > 0) {
            $shopId = $scope;
        } elseif ($scope === -1) {
            return $this->collection([], ['total' => 0]);
        }
        $status = $this->request->getGet('status');
        $page   = max(1, (int) $this->request->getGet('page'));
        $per    = min(100, max(1, (int) ($this->request->getGet('per_page') ?: 30)));
        $repo   = service('vendorDeliveryRepository');
        $filter = $status !== '' ? $status : null;
        $offset = ($page - 1) * $per;
        $items  = $repo->deliveries($vid, $filter, $shopId, $per, $offset);
        $total  = $repo->deliveriesCount($vid, $filter, $shopId);

        return $this->collection($items, [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil($total / $per),
        ]);
    }

    /** Single delivery detail: items, customer, rider, status history. */
    public function delivery(int $id)
    {
        $vid  = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $repo     = service('vendorDeliveryRepository');
        $delivery = $repo->find($id, $vid);
        if ($delivery === null || ! $this->inShopScope((int) $delivery['shop_id'])) {
            return $this->failWith('NOT_FOUND', 'Delivery not found.');
        }

        return $this->ok($delivery);
    }

    /** Manually assign a rider to a delivery. Owner or manager. */
    public function assignRider(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $riderId = (int) ($this->input()['rider_user_id'] ?? 0);
        if ($riderId <= 0) {
            return $this->failWith('VALIDATION_ERROR', 'rider_user_id is required.');
        }
        $repo     = service('vendorDeliveryRepository');
        $delivery = $repo->find($id, $vid);
        if ($delivery === null || ! $this->inShopScope((int) $delivery['shop_id'])) {
            return $this->failWith('NOT_FOUND', 'Delivery not found.');
        }
        $repo->assign($id, $riderId, $vid, $this->userId());

        return $this->ok(['delivery_id' => $id, 'rider_user_id' => $riderId]);
    }

    /** Auto-assign the nearest available rider to a delivery. Owner or manager. */
    public function autoAssignRider(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $repo     = service('vendorDeliveryRepository');
        $delivery = $repo->find($id, $vid);
        if ($delivery === null || ! $this->inShopScope((int) $delivery['shop_id'])) {
            return $this->failWith('NOT_FOUND', 'Delivery not found.');
        }
        $rider = $repo->autoAssign($id, $vid, $this->userId());
        if ($rider === null) {
            return $this->failWith('NOT_FOUND', 'No available rider found.');
        }

        return $this->ok(['delivery_id' => $id, 'rider_user_id' => $rider]);
    }

    /** Mark a delivery as failed with a reason. */
    public function failDelivery(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $reason = trim((string) ($this->input()['reason'] ?? ''));
        if ($reason === '') {
            return $this->failWith('VALIDATION_ERROR', 'reason is required.');
        }
        $repo     = service('vendorDeliveryRepository');
        $delivery = $repo->find($id, $vid);
        if ($delivery === null || ! $this->inShopScope((int) $delivery['shop_id'])) {
            return $this->failWith('NOT_FOUND', 'Delivery not found.');
        }
        $repo->markFailed($id, $vid, $reason, $this->userId());

        return $this->ok(['delivery_id' => $id, 'failed' => true]);
    }

    /** Set the reorder threshold for a specific variant+shop row. Owner or manager. */
    public function setReorderLevel()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $in        = $this->input();
        $variantId = (int) ($in['variant_id'] ?? 0);
        $shopId    = (int) ($in['shop_id'] ?? 0);
        $level     = (int) ($in['level'] ?? -1);
        if ($variantId <= 0 || $shopId <= 0 || $level < 0) {
            return $this->failWith('VALIDATION_ERROR', 'variant_id, shop_id and level (≥0) are required.');
        }
        if (! $this->inShopScope($shopId)) {
            return $this->failWith('FORBIDDEN', 'Not allowed to update this shop.');
        }
        $db    = \Config\Database::connect();
        $count = $db->table('inventory i')
            ->join('shops s', 's.id = i.shop_id')
            ->where('i.variant_id', $variantId)->where('i.shop_id', $shopId)->where('s.vendor_id', $vid)
            ->countAllResults();
        if ($count === 0) {
            return $this->failWith('NOT_FOUND', 'Inventory record not found.');
        }
        $db->table('inventory')
            ->where('variant_id', $variantId)->where('shop_id', $shopId)
            ->update(['reorder_level' => $level]);

        return $this->ok(['variant_id' => $variantId, 'shop_id' => $shopId, 'reorder_level' => $level]);
    }

    /** Inventory movement history for a variant+shop. Owner or manager. */
    public function inventoryMovements()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $variantId = (int) $this->request->getGet('variant_id');
        $shopId    = (int) $this->request->getGet('shop_id');
        if ($variantId <= 0 || $shopId <= 0) {
            return $this->failWith('VALIDATION_ERROR', 'variant_id and shop_id are required.');
        }
        if (! $this->inShopScope($shopId)) {
            return $this->failWith('FORBIDDEN', 'Not allowed to view this shop.');
        }
        $db    = \Config\Database::connect();
        $count = $db->table('inventory i')
            ->join('shops s', 's.id = i.shop_id')
            ->where('i.variant_id', $variantId)->where('i.shop_id', $shopId)->where('s.vendor_id', $vid)
            ->countAllResults();
        if ($count === 0) {
            return $this->failWith('NOT_FOUND', 'Inventory record not found.');
        }
        $rows = $db->table('inventory_ledger')
            ->select('id, movement_type, qty_delta, balance_after, reason_code, ref_type, created_at')
            ->where('variant_id', $variantId)->where('shop_id', $shopId)
            ->orderBy('created_at', 'DESC')->limit(100)
            ->get()->getResultArray();

        return $this->collection($rows, ['total' => count($rows)]);
    }

    /** List the vendor's active delivery riders with their current load. Owner or manager. */
    public function riders()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $rows = service('vendorDeliveryRepository')->riders($vid);

        return $this->collection($rows ?? [], ['total' => count($rows ?? [])]);
    }

    /** Create a new product (with a default variant). Owner only. */
    public function createProduct()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $in         = $this->input();
        $title      = trim((string) ($in['title'] ?? ''));
        $categoryId = (int) ($in['category_id'] ?? 0);
        $basePrice  = (float) ($in['base_price'] ?? 0);
        if ($title === '' || $categoryId <= 0 || $basePrice <= 0) {
            return $this->failWith('VALIDATION_ERROR', 'title, category_id and base_price are required.');
        }
        $catExists = \Config\Database::connect()->table('categories')
            ->where('id', $categoryId)->where('status', 'active')->countAllResults();
        if ($catExists === 0) {
            return $this->failWith('VALIDATION_ERROR', 'Category not found or inactive.');
        }
        $status = in_array((string) ($in['status'] ?? ''), ['draft', 'published'], true)
            ? (string) $in['status']
            : 'draft';
        $id = service('vendorProductRepository')->create($vid, [
            'title'       => $title,
            'category_id' => $categoryId,
            'base_price'  => $basePrice,
            'mrp'         => isset($in['mrp']) ? (float) $in['mrp'] : null,
            'description' => isset($in['description']) ? trim((string) $in['description']) : null,
            'status'      => $status,
        ]);
        if ($id === null) {
            return $this->failWith('SERVER_ERROR', 'Failed to create product.');
        }

        return $this->ok(['product_id' => $id]);
    }

    /** PUT /vendor/products/:id — update product title, description, category. Owner only. */
    public function updateProduct(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $db      = \Config\Database::connect();
        $product = $db->table('products')->where('id', $id)->where('vendor_id', $vid)->where('deleted_at', null)->get()->getRowArray();
        if ($product === null) {
            return $this->failWith('NOT_FOUND', 'Product not found.');
        }
        $in     = $this->input();
        $patch  = [];
        if (isset($in['title']) && trim((string) $in['title']) !== '') {
            $patch['title'] = trim((string) $in['title']);
        }
        if (array_key_exists('description', $in)) {
            $patch['description'] = isset($in['description']) ? trim((string) $in['description']) : null;
        }
        if (isset($in['category_id']) && (int) $in['category_id'] > 0) {
            $catExists = $db->table('categories')->where('id', (int) $in['category_id'])->where('status', 'active')->countAllResults();
            if ($catExists === 0) {
                return $this->failWith('VALIDATION_ERROR', 'Category not found or inactive.');
            }
            $patch['category_id'] = (int) $in['category_id'];
        }
        if (empty($patch)) {
            return $this->failWith('VALIDATION_ERROR', 'No updatable fields provided.');
        }
        $patch['updated_at'] = date('Y-m-d H:i:s');
        $db->table('products')->where('id', $id)->update($patch);

        return $this->ok(['updated' => true]);
    }

    /** DELETE /vendor/products/:id — soft-delete product. Owner only. */
    public function deleteProduct(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $db  = \Config\Database::connect();
        $ok  = $db->table('products')->where('id', $id)->where('vendor_id', $vid)->where('deleted_at', null)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        return $ok ? $this->ok(['deleted' => true]) : $this->failWith('NOT_FOUND', 'Product not found.');
    }

    /** DELETE /vendor/products/:id/variants/:variantId — soft-delete variant. Owner only. */
    public function deleteVariant(int $productId, int $variantId)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $db  = \Config\Database::connect();
        $own = $db->table('products')->where('id', $productId)->where('vendor_id', $vid)->where('deleted_at', null)->countAllResults();
        if ($own === 0) {
            return $this->failWith('NOT_FOUND', 'Product not found.');
        }
        $ok = $db->table('product_variants')->where('id', $variantId)->where('product_id', $productId)->where('deleted_at', null)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        return $ok ? $this->ok(['deleted' => true]) : $this->failWith('NOT_FOUND', 'Variant not found.');
    }

    /** POST /vendor/products/:id/media — upload product image via multipart. Owner only. */
    public function uploadProductImage(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $db      = \Config\Database::connect();
        $product = $db->table('products')->where('id', $id)->where('vendor_id', $vid)->where('deleted_at', null)->get()->getRowArray();
        if ($product === null) {
            return $this->failWith('NOT_FOUND', 'Product not found.');
        }
        $file = $this->request->getFile('image');
        if ($file === null || ! $file->isValid()) {
            return $this->failWith('VALIDATION_ERROR', 'No valid image file provided.');
        }
        $uid = $this->userId() ?? 0;
        $res = service('mediaService')->store($file, 'vendor', $vid, $uid, 'public');
        if (empty($res['ok'])) {
            return $this->failWith('SERVER_ERROR', $res['reason'] ?? 'Image upload failed.');
        }
        $repo = service('mediaRepository');
        $repo->attachToProduct($id, (int) $res['id'], ! $repo->hasPrimary($id), $uid);

        return $this->ok(['uuid' => $res['uuid'], 'media_id' => (int) $res['id']]);
    }

    /** Add a new variant to an existing product. Owner only. */
    public function addVariant(int $productId)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $in        = $this->input();
        $sku       = trim((string) ($in['sku'] ?? ''));
        $basePrice = (float) ($in['base_price'] ?? 0);
        if ($sku === '' || $basePrice <= 0) {
            return $this->failWith('VALIDATION_ERROR', 'sku and base_price are required.');
        }
        $id = service('vendorProductRepository')->addVariant($productId, $vid, [
            'sku'        => $sku,
            'base_price' => $basePrice,
            'mrp'        => isset($in['mrp']) ? (float) $in['mrp'] : null,
        ]);
        if ($id === null) {
            return $this->failWith('NOT_FOUND', 'Product not found or variant could not be created.');
        }

        return $this->ok(['variant_id' => $id]);
    }

    /** Leaf-category lookup for the product create form. */
    public function lookupCategories()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $rows = \Config\Database::connect()->table('categories')
            ->select('id, name')
            ->where('parent_id IS NOT NULL', null, false)
            ->where('status', 'active')
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();
        $items = array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);

        return $this->collection($items, ['total' => count($items)]);
    }

    /** GET /vendor/refunds — vendor's refund list (all shops or scoped). */
    public function refunds()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $page   = max(1, (int) $this->request->getGet('page'));
        $per    = min(100, max(1, (int) ($this->request->getGet('per_page') ?: 30)));
        $shopId = $this->shopScope();
        $offset = ($page - 1) * $per;
        $repo   = service('vendorRefundRepository');
        $rows   = $repo->list($vid, $shopId, $per, $offset);
        $total  = $repo->count($vid, $shopId);

        return $this->collection($rows, ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => (int) ceil($total / $per)]);
    }

    /** POST /vendor/refunds/:id/approve */
    public function approveRefund(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $repo = service('vendorRefundRepository');
        if (! $repo->approve($id, $vid)) {
            return $this->failWith('NOT_FOUND', 'Refund not found or not in pending status.');
        }

        return $this->respondNoContent();
    }

    /** POST /vendor/refunds/:id/reject */
    public function rejectRefund(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $body   = $this->request->getJSON(true) ?? [];
        $reason = $body['reason'] ?? null;
        $repo   = service('vendorRefundRepository');
        if (! $repo->reject($id, $vid, $reason)) {
            return $this->failWith('NOT_FOUND', 'Refund not found or not in pending status.');
        }

        return $this->respondNoContent();
    }

    /** GET /vendor/deliveries/:id/history — status event timeline for one delivery. */
    public function deliveryHistory(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $repo = service('vendorDeliveryRepository');
        if (! $repo->owns($id, $vid)) {
            return $this->failWith('NOT_FOUND', 'Delivery not found.');
        }
        $rows = $repo->history($id, $vid);

        return $this->collection($rows, ['total' => count($rows)]);
    }

    /** POST /vendor/purchase — receive stock from a supplier. */
    public function createPurchase()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $in     = $this->input();
        $shopId = (int) ($in['shop_id'] ?? 0);
        $lines  = $in['lines'] ?? [];
        if ($shopId <= 0 || ! is_array($lines) || count($lines) === 0) {
            return $this->failWith('VALIDATION_ERROR', 'shop_id and lines are required.');
        }
        if (! $this->inShopScope($shopId)) {
            return $this->failWith('FORBIDDEN', 'Not allowed to adjust stock for this shop.');
        }
        $db   = \Config\Database::connect();
        $shop = $db->table('shops')
            ->where('id', $shopId)->where('vendor_id', $vid)->where('deleted_at', null)
            ->countAllResults();
        if ($shop === 0) {
            return $this->failWith('NOT_FOUND', 'Shop not found.');
        }
        $svc = service('inventoryService');
        foreach ($lines as $line) {
            $variantId = (int) ($line['variant_id'] ?? 0);
            $qty       = (float) ($line['qty'] ?? 0);
            $cost      = (float) ($line['cost'] ?? 0);
            if ($variantId <= 0 || $qty <= 0) {
                return $this->failWith('VALIDATION_ERROR', 'Each line requires variant_id and qty > 0.');
            }
            $owns = $db->table('product_variants')
                ->where('id', $variantId)->where('vendor_id', $vid)->where('deleted_at', null)
                ->countAllResults();
            if ($owns === 0) {
                return $this->failWith('NOT_FOUND', "Variant {$variantId} not found.");
            }
            $svc->receive($variantId, $shopId, $qty, $cost > 0 ? $cost : 0.0, [], $this->userId());
        }

        return $this->ok(['received' => count($lines)]);
    }

    // ── P2: GST, Settlements, Shop Hours, Transfers, Delivery Reports ──

    /** GET /vendor/gst — GST summary + recent lines. */
    public function gstSummary()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        // Vendor-wide GST figures are owner-only, exactly as on the web panel where a
        // shop-scoped staffer never sees a sibling branch's tax data.
        if (! $this->isOwner()) {
            return $this->failWith('FORBIDDEN', 'Only the vendor owner can view GST summary.');
        }
        $repo    = service('vendorGstRepository');
        $summary = $repo->summary($vid);
        $lines   = $repo->lines($vid, 50);

        return $this->ok(['summary' => $summary, 'lines' => $lines]);
    }

    /** GET /vendor/settlements — payout settlement list. */
    public function settlements()
    {
        $vid  = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        // Vendor-wide money is owner-only, exactly as on the web panel where a
        // shop-scoped staffer never sees a sibling branch's figures.
        if (! $this->isOwner()) {
            return $this->failWith('FORBIDDEN', 'Only the vendor owner can view settlements.');
        }
        $page   = max(1, (int) $this->request->getGet('page'));
        $per    = min(100, max(1, (int) ($this->request->getGet('per_page') ?: 30)));
        $offset = ($page - 1) * $per;
        $repo   = service('vendorSettlementRepository');
        $rows   = $repo->list($vid, $per, $offset);
        $total  = $repo->count($vid);

        return $this->collection($rows, ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => (int) ceil($total / $per)]);
    }

    /** GET /vendor/settlements/:id — settlement detail + lines. */
    public function settlement(int $id)
    {
        $vid  = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        if (! $this->isOwner()) {
            return $this->failWith('FORBIDDEN', 'Only the vendor owner can view settlements.');
        }
        $repo = service('vendorSettlementRepository');
        $row  = $repo->findById($id, $vid);
        if ($row === null) {
            return $this->failWith('NOT_FOUND', 'Settlement not found.');
        }
        $lines = $repo->lines($id);

        return $this->ok(['settlement' => $row, 'lines' => $lines]);
    }

    /** GET /vendor/shops/:id/hours — operating hours for one of the vendor's shops. */
    public function shopHours(int $shopId)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $db   = \Config\Database::connect();
        $owns = $db->table('shops')
            ->where('id', $shopId)->where('vendor_id', $vid)->where('deleted_at', null)
            ->countAllResults();
        if ($owns === 0) {
            return $this->failWith('NOT_FOUND', 'Shop not found.');
        }
        $hours = service('vendorShopRepository')->hours($shopId);

        return $this->collection($hours, ['total' => count($hours)]);
    }

    /** GET /vendor/transfers — inter-shop stock transfer list. */
    public function transfers()
    {
        $vid  = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        if (! $this->isOwner()) {
            return $this->failWith('FORBIDDEN', 'Only the vendor owner can view transfers.');
        }
        $page   = max(1, (int) $this->request->getGet('page'));
        $per    = min(100, max(1, (int) ($this->request->getGet('per_page') ?: 30)));
        $offset = ($page - 1) * $per;
        $repo   = service('vendorTransferRepository');
        $rows   = $repo->list($vid, $per, $offset);
        $total  = $repo->count($vid);

        return $this->collection($rows, ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => (int) ceil($total / $per)]);
    }

    /** POST /vendor/transfers — request an inter-shop stock transfer. */
    public function createTransfer()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        if (! $this->isOwner()) {
            return $this->failWith('FORBIDDEN', 'Only the vendor owner can request transfers.');
        }
        $in        = $this->input();
        $fromShop  = (int) ($in['from_shop_id'] ?? 0);
        $toShop    = (int) ($in['to_shop_id'] ?? 0);
        $variantId = (int) ($in['variant_id'] ?? 0);
        $qty       = (float) ($in['qty'] ?? 0);
        if ($fromShop <= 0 || $toShop <= 0 || $variantId <= 0 || $qty <= 0) {
            return $this->failWith('VALIDATION_ERROR', 'from_shop_id, to_shop_id, variant_id and qty > 0 are required.');
        }
        if ($fromShop === $toShop) {
            return $this->failWith('VALIDATION_ERROR', 'from_shop_id and to_shop_id must be different.');
        }
        $db = \Config\Database::connect();
        foreach ([$fromShop, $toShop] as $sid) {
            $ok = $db->table('shops')
                ->where('id', $sid)->where('vendor_id', $vid)->where('deleted_at', null)
                ->countAllResults();
            if ($ok === 0) {
                return $this->failWith('NOT_FOUND', "Shop {$sid} not found.");
            }
        }
        $owns = $db->table('product_variants')
            ->where('id', $variantId)->where('vendor_id', $vid)->where('deleted_at', null)
            ->countAllResults();
        if ($owns === 0) {
            return $this->failWith('NOT_FOUND', "Variant {$variantId} not found.");
        }
        $ok = service('vendorTransferRepository')->createRequest($vid, $fromShop, $toShop, $variantId, $qty, $this->userId());

        return $ok
            ? $this->ok(['requested' => true])
            : $this->failWith('SERVER_ERROR', 'Failed to create transfer request.');
    }

    /** GET /vendor/deliveries/reports — rollup stats for the vendor. */
    public function deliveryReports()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }

        return $this->ok(service('vendorDeliveryRepository')->reports($vid));
    }

    // ── P3: Notifications, Staff ──

    /** GET /vendor/notifications — in-app notification inbox. */
    public function notifications()
    {
        $uid    = $this->userId();
        $page   = max(1, (int) $this->request->getGet('page'));
        $per    = min(100, max(1, (int) ($this->request->getGet('per_page') ?: 50)));
        $offset = ($page - 1) * $per;
        $repo   = service('vendorNotificationRepository');
        $rows   = $repo->list($uid, $per, $offset);
        $total  = $repo->count($uid);
        $unread = (int) \Config\Database::connect()->table('notifications')
            ->where('user_id', $uid)->where('read_at IS NULL', null, false)->where('deleted_at', null)
            ->countAllResults();

        return $this->collection($rows, ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => (int) ceil($total / $per), 'unread' => $unread]);
    }

    /** GET /vendor/notifications/pending-rings — unread ring-type notifications for app restore. */
    public function pendingRings()
    {
        $rows = service('vendorNotificationRepository')->pendingRings($this->userId());

        return $this->ok($rows);
    }

    /** GET /vendor/dashboard/chart — 7-day order count per day for mini bar chart. */
    public function dashboardChart()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }

        return $this->ok(service('vendorDashboardRepository')->chart($vid));
    }

    /** POST /vendor/orders/:id/verify-otp — verify delivery OTP; reveals address on success. */
    public function verifyDeliveryOtp(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $repo = service('vendorOrderRepository');
        $sub  = $repo->findSubOrder($id, $vid);
        if ($sub === null || ! $this->inShopScope((int) $sub['shop_id'])) {
            return $this->failWith('NOT_FOUND', 'Order not found.');
        }
        if (! empty($sub['otp_verified_at'])) {
            return $this->failWith('CONFLICT', 'Order already verified at ' . $sub['otp_verified_at'] . '.');
        }
        if ((int) ($sub['otp_attempts'] ?? 0) >= 5) {
            return $this->failWith('LOCKED', 'OTP locked — too many wrong attempts. Contact support.');
        }
        // Rate limit: enforce 60-second gap between wrong attempts
        if (! empty($sub['otp_last_attempt_at'])) {
            $secondsSinceLast = time() - strtotime((string) $sub['otp_last_attempt_at']);
            if ($secondsSinceLast < 60) {
                return $this->respond([
                    'error'       => 'RATE_LIMITED',
                    'message'     => 'Too fast. Please wait before trying again.',
                    'retry_after' => 60 - $secondsSinceLast,
                ], 429);
            }
        }
        // OTP expiry check (24 h after out_for_delivery transition)
        if (! empty($sub['otp_expires_at']) && strtotime((string) $sub['otp_expires_at']) < time()) {
            return $this->failWith('EXPIRED', 'OTP has expired. Please request a new one.');
        }
        $otp = (string) ($this->input()['otp'] ?? '');
        if ($otp !== (string) ($sub['delivery_otp'] ?? '')) {
            $repo->incrementOtpAttempts($id);
            // Log failed attempt
            service('orderClaimService')->log($id, 'otp_attempt', null, $this->userId(), null, null, 'failed', (string) $this->request->getIPAddress(), (string) $this->request->getUserAgent());
            $remaining = max(0, 5 - $repo->otpAttempts($id)); // re-read for an accurate count under concurrency

            return $this->failWith('VALIDATION_ERROR', "Incorrect OTP. {$remaining} attempt" . ($remaining === 1 ? '' : 's') . ' remaining.');
        }
        // markOtpVerified is conditional (only if still unverified) so two racing
        // verifications can't both "succeed".
        if (! $repo->markOtpVerified($id)) {
            return $this->failWith('CONFLICT', 'Order already verified.');
        }
        // Log successful verification
        service('orderClaimService')->log($id, 'otp_attempt', null, $this->userId(), null, null, 'success', (string) $this->request->getIPAddress(), (string) $this->request->getUserAgent());

        return $this->ok(['verified' => true, 'message' => 'OTP verified']);
    }

    /** POST /vendor/orders/:id/regenerate-otp — generate a fresh OTP (admin or shop owner only). */
    public function regenerateOtp(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        // Regeneration resets the OTP + the attempt lock, so it is owner-only — a
        // branch cashier must not be able to clear the throttle on demand.
        if (service('vendorAccountRepository')->findByOwnerUserId($this->userId()) === null) {
            return $this->respond(['error' => 'forbidden', 'message' => 'Only the vendor owner can regenerate a delivery OTP.'], 403);
        }
        $repo = service('vendorOrderRepository');
        $sub  = $repo->findSubOrder($id, $vid);
        if ($sub === null || ! $this->inShopScope((int) $sub['shop_id'])) {
            return $this->failWith('NOT_FOUND', 'Order not found.');
        }
        if (! empty($sub['otp_verified_at'])) {
            return $this->failWith('CONFLICT', 'OTP already verified — regeneration not needed.');
        }
        $newOtp = (string) random_int(100000, 999999);
        \Config\Database::connect()->table('sub_orders')
            ->where('id', $id)->update([
                'delivery_otp'       => $newOtp,
                'otp_attempts'       => 0,
                'otp_last_attempt_at'=> null,
                'otp_expires_at'     => date('Y-m-d H:i:s', strtotime('+24 hours')),
            ]);
        // Notify customer of the new OTP
        try {
            $customerId = \Config\Database::connect()->table('orders o')
                ->select('o.customer_id')->where('o.id', $sub['order_id'])->get()->getRowArray()['customer_id'] ?? null;
            if ($customerId) {
                $custUserId = \Config\Database::connect()->table('customers')->select('user_id')->where('id', $customerId)->get()->getRowArray()['user_id'] ?? null;
                if ($custUserId) {
                    // OTP stays in-app only — the customer opens the app to view the new code.
                    service('notificationService')->notify((int) $custUserId, 'order.otp_regenerated', ['sub_order_no' => $sub['sub_order_no']]);
                }
            }
        } catch (\Throwable) {
        }
        service('orderClaimService')->log($id, 'otp_attempt', null, $this->userId(), null, null, 'regenerated', (string) $this->request->getIPAddress(), (string) $this->request->getUserAgent());

        return $this->ok(['message' => 'New OTP generated and sent to customer.']);
    }

    /** POST /vendor/orders/:id/assign-delivery — per-item delivery assignment. */
    public function assignOrderDelivery(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $repo = service('vendorOrderRepository');
        $sub  = $repo->findSubOrder($id, $vid);
        if ($sub === null) {
            return $this->failWith('NOT_FOUND', 'Order not found.');
        }
        if (in_array($sub['status'], ['out_for_delivery', 'delivered', 'cancelled'], true)) {
            return $this->failWith('CONFLICT', 'Cannot reassign delivery for a dispatched or completed order.');
        }
        $assignments = $this->input()['assignments'] ?? [];
        if (! is_array($assignments) || $assignments === []) {
            return $this->failWith('VALIDATION_ERROR', 'assignments is required.');
        }
        foreach ($assignments as $a) {
            if (($a['mode'] ?? 'pool') === 'self' && empty($a['rider_user_id'])) {
                return $this->failWith('VALIDATION_ERROR', 'rider_user_id is required for self-delivery mode.');
            }
        }
        $repo->upsertItemAssignments($id, (int) $sub['order_id'], $assignments, $this->userId());

        return $this->ok(['assigned' => true]);
    }

    /** PATCH /vendor/notifications/:id/read — mark one notification read. */
    public function markNotificationRead(int $id)
    {
        service('vendorNotificationRepository')->markRead($id, $this->userId());

        return $this->ok(['read' => true]);
    }

    /** POST /vendor/notifications/read-all — mark all notifications read. */
    public function markAllNotificationsRead()
    {
        service('vendorNotificationRepository')->markAllRead($this->userId());

        return $this->ok(['read' => true]);
    }

    /** DELETE /vendor/notifications/:id — soft-delete one notification. */
    public function deleteNotification(int $id)
    {
        service('vendorNotificationRepository')->deleteOne($id, $this->userId());

        return $this->ok(['deleted' => true]);
    }

    /** DELETE /vendor/notifications — soft-delete all notifications. */
    public function deleteAllNotifications()
    {
        service('vendorNotificationRepository')->deleteAll($this->userId());

        return $this->ok(['deleted' => true]);
    }

    /** GET /vendor/staff — list of staff members with their shop assignments. Owner only. */
    public function staffList()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        if (! $this->isOwner()) {
            return $this->failWith('FORBIDDEN', 'Only the vendor owner can view the staff roster.');
        }
        $rows = service('vendorStaffRepository')->staffWithShops($vid);

        return $this->collection($rows, ['total' => count($rows)]);
    }

    /** Bulk action on multiple products: publish/unpublish. Owner only. */
    public function bulkProducts()
    {
        $vid = $this->vendorId();
        if ($vid === null) {
            return $this->notVendor();
        }
        $action = $this->request->getJSON(true)['action'] ?? '';
        $ids    = $this->request->getJSON(true)['ids'] ?? [];
        if (!in_array($action, ['publish', 'unpublish'], true) || empty($ids)) {
            return $this->failValidationErrors('action and ids[] are required');
        }
        $status = $action === 'publish' ? 'published' : 'unpublished';
        $db = db_connect();
        $db->table('products')
            ->whereIn('id', array_map('intval', $ids))
            ->where('vendor_id', $vid)
            ->update(['status' => $status]);
        return $this->ok(['updated' => $db->affectedRows()]);
    }

    /** True when the acting user is the vendor owner (not staff). */
    private function isOwner(): bool
    {
        return service('vendorAccountRepository')->findByOwnerUserId($this->userId()) !== null;
    }

    public function commissionLedger()
    {
        $vid = $this->vendorId();
        if ($vid === null) return $this->notVendor();
        if (! $this->isOwner()) {
            return $this->failWith('FORBIDDEN', 'Only the vendor owner can view the commission ledger.');
        }

        $db = \Config\Database::connect();
        $rows = $db->table('commission_ledger cl')
            ->select('cl.id, cl.sub_order_id, so.sub_order_no, cl.amount, cl.rate, cl.type, cl.description, cl.created_at, cl.status', false)
            ->join('sub_orders so', 'so.id = cl.sub_order_id', 'left')
            ->where('cl.vendor_id', $vid)
            ->orderBy('cl.created_at', 'DESC')
            ->limit(100)
            ->get()->getResultArray();

        $summary = $db->table('commission_ledger')->select('SUM(amount) AS total_commission, AVG(rate) AS avg_rate')->where('vendor_id', $vid)->where('status', 'confirmed')->get()->getRowArray();

        return $this->item([
            'summary' => [
                'total_commission' => (float)($summary['total_commission'] ?? 0),
                'avg_rate'         => (float)($summary['avg_rate'] ?? 0),
            ],
            'entries' => $rows,
        ]);
    }

    public function transferAction(int $id, string $action)
    {
        $vid = $this->vendorId();
        if ($vid === null) return $this->notVendor();
        if (! $this->isOwner()) {
            return $this->failWith('FORBIDDEN', 'Only the vendor owner can act on transfers.');
        }

        $db  = \Config\Database::connect();
        $row = $db->table('stock_transfers')->where('id', $id)->where('vendor_id', $vid)->get()->getRowArray();
        if (!$row) return $this->fail('Transfer not found or access denied.', 404);

        $allowed = [
            'pending'    => ['approve', 'reject', 'cancel'],
            'approved'   => ['pack', 'cancel'],
            'packed'     => ['dispatch', 'cancel'],
            'in_transit' => ['receive'],
            'received'   => ['close'],
        ];
        $current    = $row['status'] ?? 'pending';
        $nextStatus = [
            'approve'  => 'approved',
            'reject'   => 'rejected',
            'pack'     => 'packed',
            'dispatch' => 'in_transit',
            'receive'  => 'received',
            'close'    => 'closed',
            'cancel'   => 'cancelled',
        ][$action] ?? null;

        if (!$nextStatus || !in_array($action, $allowed[$current] ?? [], true)) {
            return $this->fail("Action '{$action}' not allowed on a '{$current}' transfer.", 422);
        }

        $note = $this->request->getJSON(true)['note'] ?? null;
        $update = ['status' => $nextStatus, 'updated_at' => date('Y-m-d H:i:s')];
        if ($note !== null) {
            $update['notes'] = $note;
        }
        $db->table('stock_transfers')->where('id', $id)->update($update);

        return $this->item(['id' => $id, 'status' => $nextStatus]);
    }

    /** vendor_staff.staff_type ENUM (database/sql/10_staff.sql:28). */
    private const STAFF_TYPES = ['branch_manager', 'cashier', 'packer', 'helper', 'delivery_boy', 'manager', 'other'];

    public function createStaff()
    {
        $vid = $this->vendorId();
        if ($vid === null || !$this->isOwner()) return $this->fail('Owner access required.', 403);

        $body = $this->request->getJSON(true) ?? [];
        $name  = trim($body['name'] ?? '');
        $phone = trim($body['phone'] ?? '');
        $type  = in_array($body['type'] ?? '', self::STAFF_TYPES, true) ? $body['type'] : 'cashier';
        $shops = array_values(array_intersect(
            array_map('intval', (array) ($body['shop_ids'] ?? [])),
            service('vendorAccountRepository')->shopIdsForVendor($vid),
        ));

        if (!$name || !$phone) return $this->fail('Name and phone are required.', 422);

        $db = \Config\Database::connect();

        // Never adopt an existing account by phone: that used to bind an admin, a
        // rider or another vendor's staffer to this tenant without their involvement
        // (findStaffVendor() resolves any active vendor_staff row), and updateStaff()
        // would then treat them as ours to rewrite — an arbitrary cross-tenant write
        // to the global users table.
        if ($db->table('users')->where('phone', $phone)->where('deleted_at', null)->get()->getRowArray()) {
            return $this->fail('That phone number already belongs to an account.', 409);
        }

        $db->transBegin();
        try {
            $db->table('users')->insert(['name' => $name, 'phone' => $phone, 'role' => 'staff', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $userId = (int) $db->insertID();

            $db->table('vendor_staff')->insert([
                'vendor_id'   => $vid,
                'user_id'     => $userId,
                'staff_type'  => $type,
                'designation' => $body['designation'] ?? '',
                'status'      => 'active',
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
            $staffId = (int) $db->insertID();

            // staff_shop_assignments is keyed on (vendor_staff_id, shop_id), not
            // (staff_user_id, vendor_id) — those columns never existed on this table,
            // so any call here with a non-empty shop_ids threw *after* the users and
            // vendor_staff writes above had already committed (no transaction), 500ing
            // while leaving the binding in place. Fixed columns + a real transaction.
            foreach ($shops as $sid) {
                $db->table('staff_shop_assignments')->insert([
                    'vendor_staff_id' => $staffId, 'shop_id' => $sid, 'assigned_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $db->transComplete();
            if (! $db->transStatus()) {
                return $this->fail('Could not create the staff member.', 500);
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'vendor staff create failed: ' . $e->getMessage());

            return $this->fail('Could not create the staff member.', 500);
        }

        return $this->item(['user_id' => $userId, 'message' => 'Staff member added.']);
    }

    public function updateStaff(int $userId)
    {
        $vid = $this->vendorId();
        if ($vid === null || !$this->isOwner()) return $this->fail('Owner access required.', 403);

        $db  = \Config\Database::connect();
        $row = $db->table('vendor_staff')->where('user_id', $userId)->where('vendor_id', $vid)->get()->getRowArray();
        if (!$row) return $this->fail('Staff not found.', 404);

        $body = $this->request->getJSON(true) ?? [];
        if (!empty($body['type']) && ! in_array($body['type'], self::STAFF_TYPES, true)) {
            return $this->fail('Invalid staff type.', 422);
        }

        $db->transBegin();
        try {
            if (!empty($body['name'])) {
                $db->table('users')->where('id', $userId)->update(['name' => $body['name'], 'updated_at' => date('Y-m-d H:i:s')]);
            }
            if (!empty($body['type'])) {
                $db->table('vendor_staff')->where('user_id', $userId)->where('vendor_id', $vid)->update(['staff_type' => $body['type'], 'updated_at' => date('Y-m-d H:i:s')]);
            }
            if (isset($body['shop_ids'])) {
                $shops = array_values(array_intersect(
                    array_map('intval', (array) $body['shop_ids']),
                    service('vendorAccountRepository')->shopIdsForVendor($vid),
                ));
                $db->table('staff_shop_assignments')->where('vendor_staff_id', (int) $row['id'])->delete();
                foreach ($shops as $sid) {
                    $db->table('staff_shop_assignments')->insert(['vendor_staff_id' => (int) $row['id'], 'shop_id' => $sid, 'assigned_at' => date('Y-m-d H:i:s')]);
                }
            }
            $db->transComplete();
            if (! $db->transStatus()) {
                return $this->fail('Could not update the staff member.', 500);
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'vendor staff update failed: ' . $e->getMessage());

            return $this->fail('Could not update the staff member.', 500);
        }

        return $this->item(['message' => 'Updated.']);
    }

    public function deleteStaff(int $userId)
    {
        $vid = $this->vendorId();
        if ($vid === null || !$this->isOwner()) return $this->fail('Owner access required.', 403);

        $db  = \Config\Database::connect();
        $row = $db->table('vendor_staff')->where('user_id', $userId)->where('vendor_id', $vid)->get()->getRowArray();
        if (!$row) return $this->fail('Staff not found.', 404);

        $db->table('vendor_staff')->where('id', (int) $row['id'])->update(['status' => 'inactive', 'updated_at' => date('Y-m-d H:i:s')]);
        $db->table('staff_shop_assignments')->where('vendor_staff_id', (int) $row['id'])->delete();

        return $this->item(['message' => 'Staff member deactivated.']);
    }

    public function saveShopHours(int $shopId)
    {
        $vid = $this->vendorId();
        if ($vid === null) return $this->notVendor();

        $db  = \Config\Database::connect();
        $shop = $db->table('shops')->where('id', $shopId)->where('vendor_id', $vid)->get()->getRowArray();
        if (!$shop) return $this->fail('Shop not found.', 404);

        $body = $this->request->getJSON(true) ?? [];
        $hours = $body['hours'] ?? [];
        if (empty($hours)) return $this->fail('Hours data required.', 422);

        foreach ($hours as $h) {
            $day = (int)($h['day_of_week'] ?? 0);
            if ($day < 1 || $day > 7) continue;
            $isClosed = !empty($h['is_closed']) ? 1 : 0;
            $existing = $db->table('shop_hours')->where('shop_id', $shopId)->where('day_of_week', $day)->get()->getRowArray();
            $record = [
                'shop_id'      => $shopId,
                'day_of_week'  => $day,
                'is_closed'    => $isClosed,
                'open_time'    => $isClosed ? null : ($h['open_time'] ?? '09:00:00'),
                'close_time'   => $isClosed ? null : ($h['close_time'] ?? '21:00:00'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ];
            if ($existing) {
                $db->table('shop_hours')->where('id', $existing['id'])->update($record);
            } else {
                $record['created_at'] = date('Y-m-d H:i:s');
                $db->table('shop_hours')->insert($record);
            }
        }

        return $this->item(['message' => 'Hours saved.']);
    }

    public function businessProfile()
    {
        $vid = $this->vendorId();
        if ($vid === null) return $this->notVendor();

        $db = \Config\Database::connect();
        $vendor = $db->table('vendors v')
            ->select('v.id, v.business_name, v.business_type, v.email, v.phone, v.address, v.gst_number, v.logo_uuid, v.status, v.created_at', false)
            ->where('v.id', $vid)
            ->get()->getRowArray();

        return $this->item($vendor ?: []);
    }

    public function updateBusinessProfile()
    {
        $vid = $this->vendorId();
        if ($vid === null || !$this->isOwner()) return $this->fail('Owner access required.', 403);

        $body = $this->request->getJSON(true) ?? [];
        $updates = array_filter([
            'business_name' => $body['business_name'] ?? null,
            'email'         => $body['email'] ?? null,
            'address'       => $body['address'] ?? null,
            'gst_number'    => $body['gst_number'] ?? null,
        ], fn($v) => $v !== null);

        if (!empty($updates)) {
            $updates['updated_at'] = date('Y-m-d H:i:s');
            \Config\Database::connect()->table('vendors')->where('id', $vid)->update($updates);
        }

        return $this->item(['message' => 'Profile updated.']);
    }

    public function productPricing(int $productId)
    {
        $vid = $this->vendorId();
        if ($vid === null) return $this->notVendor();

        $db  = \Config\Database::connect();
        $own = $db->table('products')->where('id', $productId)->where('vendor_id', $vid)->get()->getRowArray();
        if (!$own) return $this->fail('Product not found.', 404);

        $special = $db->table('product_special_prices')->where('product_id', $productId)->where('deleted_at', null)->get()->getResultArray();
        $tier    = $db->table('product_tier_prices')->where('product_id', $productId)->where('deleted_at', null)->get()->getResultArray();

        return $this->item(['special' => $special, 'tier' => $tier]);
    }

    public function addSpecialPrice(int $productId)
    {
        $vid = $this->vendorId();
        if ($vid === null || !$this->isOwner()) return $this->fail('Owner access required.', 403);

        $db  = \Config\Database::connect();
        $own = $db->table('products')->where('id', $productId)->where('vendor_id', $vid)->get()->getRowArray();
        if (!$own) return $this->fail('Product not found.', 404);

        $body = $this->request->getJSON(true) ?? [];
        $db->table('product_special_prices')->insert([
            'product_id'  => $productId,
            'variant_id'  => $body['variant_id'] ?? null,
            'price'       => (float)($body['price'] ?? 0),
            'label'       => $body['label'] ?? '',
            'starts_at'   => $body['starts_at'] ?? null,
            'ends_at'     => $body['ends_at'] ?? null,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        return $this->item(['message' => 'Special price added.', 'id' => $db->insertID()]);
    }

    public function addTierPrice(int $productId)
    {
        $vid = $this->vendorId();
        if ($vid === null || !$this->isOwner()) return $this->fail('Owner access required.', 403);

        $db  = \Config\Database::connect();
        $own = $db->table('products')->where('id', $productId)->where('vendor_id', $vid)->get()->getRowArray();
        if (!$own) return $this->fail('Product not found.', 404);

        $body   = $this->request->getJSON(true) ?? [];
        $price  = (float) ($body['price']   ?? 0);
        $minQty = (int)   ($body['min_qty'] ?? 0);
        if ($price <= 0 || $minQty < 1) {
            return $this->fail('price and min_qty are required.', 422);
        }
        $db->table('product_tier_prices')->insert([
            'product_id' => $productId,
            'vendor_id'  => $vid,
            'min_qty'    => $minQty,
            'price'      => $price,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->item(['message' => 'Tier price added.', 'id' => $db->insertID()]);
    }

    public function deleteProductPricing(int $productId, string $type, int $ruleId)
    {
        $vid = $this->vendorId();
        if ($vid === null || !$this->isOwner()) return $this->fail('Owner access required.', 403);

        $db = \Config\Database::connect();
        // Every sibling method here (productPricing, addSpecialPrice, addTierPrice)
        // checks the product belongs to this vendor before touching it; this one
        // didn't, so any vendor could delete another vendor's rule by guessing ids.
        $own = $db->table('products')->where('id', $productId)->where('vendor_id', $vid)->get()->getRowArray();
        if (!$own) return $this->fail('Product not found.', 404);

        $table = $type === 'tier' ? 'product_tier_prices' : 'product_special_prices';
        $db->table($table)->where('id', $ruleId)->where('product_id', $productId)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        return $this->item(['message' => 'Deleted.']);
    }

    public function approvals()
    {
        $vid    = $this->vendorId();
        $userId = $this->userId();
        if ($vid === null || !$userId) return $this->notVendor();

        $db   = \Config\Database::connect();
        $isOwner = $this->isOwner();

        $b = $db->table('change_requests cr')
            ->select('cr.id, cr.type, cr.payload, cr.status, cr.reason, cr.created_at, u.name AS requester_name, cr.requester_id', false)
            ->join('users u', 'u.id = cr.requester_id', 'left')
            ->where('cr.vendor_id', $vid)
            ->orderBy('cr.created_at', 'DESC')
            ->limit(50);

        if (!$isOwner) {
            $b->where('cr.requester_id', $userId);
        }

        return $this->collection($b->get()->getResultArray());
    }

    public function approveChangeRequest(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null || !$this->isOwner()) return $this->fail('Owner access required.', 403);

        $db  = \Config\Database::connect();
        $row = $db->table('change_requests')->where('id', $id)->where('vendor_id', $vid)->where('status', 'pending')->get()->getRowArray();
        if (!$row) return $this->fail('Request not found or already actioned.', 404);

        $db->table('change_requests')->where('id', $id)->update(['status' => 'approved', 'actioned_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

        return $this->item(['message' => 'Approved.']);
    }

    public function rejectChangeRequest(int $id)
    {
        $vid = $this->vendorId();
        if ($vid === null || !$this->isOwner()) return $this->fail('Owner access required.', 403);

        $db   = \Config\Database::connect();
        $row  = $db->table('change_requests')->where('id', $id)->where('vendor_id', $vid)->where('status', 'pending')->get()->getRowArray();
        if (!$row) return $this->fail('Request not found or already actioned.', 404);

        $reason = $this->request->getJSON(true)['reason'] ?? '';
        $db->table('change_requests')->where('id', $id)->update(['status' => 'rejected', 'reason' => $reason, 'actioned_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

        return $this->item(['message' => 'Rejected.']);
    }

    public function documents()
    {
        $vid = $this->vendorId();
        if ($vid === null) return $this->notVendor();

        $db   = \Config\Database::connect();
        $docs = $db->table('vendor_documents')->where('vendor_id', $vid)->where('deleted_at', null)->orderBy('created_at', 'DESC')->get()->getResultArray();

        return $this->collection($docs);
    }

    public function documentPresign()
    {
        $vid = $this->vendorId();
        if ($vid === null) return $this->notVendor();

        $body     = $this->request->getJSON(true) ?? [];
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($body['filename'] ?? 'document.pdf'));
        $type     = $body['doc_type'] ?? 'kyc';
        $uuid     = bin2hex(random_bytes(16));
        $ext      = pathinfo($filename, PATHINFO_EXTENSION) ?: 'pdf';
        $path     = "vendor/{$vid}/docs/{$uuid}.{$ext}";

        // Store pending record
        \Config\Database::connect()->table('vendor_documents')->insert([
            'vendor_id'  => $vid,
            'doc_type'   => $type,
            'filename'   => $filename,
            'file_path'  => $path,
            'uuid'       => $uuid,
            'status'     => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // In production, return a real pre-signed S3 URL. For now return a local upload URL.
        return $this->item([
            'upload_url' => rtrim(base_url(), '/') . '/api/v1/vendor/documents/upload/' . $uuid,
            'uuid'       => $uuid,
            'path'       => $path,
        ]);
    }

    public function documentConfirm(string $uuid)
    {
        $vid = $this->vendorId();
        if ($vid === null) return $this->notVendor();

        $db  = \Config\Database::connect();
        $doc = $db->table('vendor_documents')->where('uuid', $uuid)->where('vendor_id', $vid)->get()->getRowArray();
        if (!$doc) return $this->fail('Document not found.', 404);

        $db->table('vendor_documents')->where('uuid', $uuid)->update(['status' => 'uploaded', 'updated_at' => date('Y-m-d H:i:s')]);

        return $this->item(['message' => 'Document confirmed.', 'uuid' => $uuid]);
    }

    public function printBarcodes()
    {
        $vid = $this->vendorId();
        if ($vid === null) return $this->notVendor();

        $body    = $this->request->getJSON(true) ?? [];
        $items   = $body['items'] ?? []; // [{variant_id, qty}]

        if (empty($items)) return $this->fail('No items specified.', 422);

        $db   = \Config\Database::connect();
        $rows = [];
        foreach ($items as $item) {
            $varId   = (int)($item['variant_id'] ?? 0);
            $qty     = max(1, (int)($item['qty'] ?? 1));
            $variant = $db->table('product_variants pv')
                ->select('pv.id, pv.sku, pv.barcode, pv.price, p.title AS product_title, pv.variant_key', false)
                ->join('products p', 'p.id = pv.product_id')
                ->where('pv.id', $varId)
                ->where('p.vendor_id', $vid)
                ->get()->getRowArray();
            if ($variant) {
                for ($i = 0; $i < $qty; $i++) {
                    $rows[] = $variant;
                }
            }
        }

        if (empty($rows)) return $this->fail('No valid variants found.', 422);

        // Use existing BarcodeLabelService if available, otherwise return data for client-side generation
        if (class_exists(\App\Libraries\Barcode\BarcodeLabelService::class)) {
            $pdf = \App\Libraries\Barcode\BarcodeLabelService::generate($rows);
            return $this->response
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'attachment; filename="barcodes.pdf"')
                ->setBody($pdf);
        }

        // Fallback: return the data for the app to display
        return $this->collection($rows);
    }

    private function notVendor()
    {
        return $this->failWith('FORBIDDEN', 'This account is not a vendor.');
    }
}
