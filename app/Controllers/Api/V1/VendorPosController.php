<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\Firebase\FirebaseIdTokenVerifier;
use App\Libraries\Notify\SmsSender;
use App\Libraries\Otp\OtpService;

/**
 * VendorPosController — POS-wizard vendor auth + shop-list endpoints.
 *
 * POST /api/v1/auth/vendor/firebase-verify  — exchange a Firebase Phone-Auth
 *   ID token for a vendor mobile JWT. Public; rate-limited.
 *
 * GET  /api/v1/vendor/pos/shops  — list the authenticated vendor's active shops.
 *   Requires JWT (jwtAuth filter).
 *
 * Edge cases covered:
 *   - Firebase not configured (no project_id in integration_accounts) → 503
 *   - Invalid / expired Firebase token → 401
 *   - Token has no phone_number claim → 401
 *   - Phone not found in users table → 404
 *   - User exists but is not a vendor → 403
 *   - Vendor account not active → 403
 *   - Vendor record (vendors table) not found → 403
 *   - Vendor with zero active shops → 200, empty array
 */
final class VendorPosController extends BaseApiController
{
    /** 30-day token TTL — mirrors AuthApiController. */
    private const TOKEN_TTL = 2_592_000;

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/vendor/firebase-verify
    // -------------------------------------------------------------------------

    public function firebaseVerify()
    {
        $body    = $this->input();
        $idToken = trim((string) ($body['id_token'] ?? ''));

        if ($idToken === '') {
            return $this->failWith('VALIDATION_ERROR', 'id_token is required.');
        }

        // Resolve Firebase project config from integration_accounts.
        $cfg       = service('integrationRepository')->config('firebase');
        $projectId = trim((string) ($cfg['project_id'] ?? ''));

        if ($projectId === '') {
            return $this->failWith(
                'DEPENDENCY_UNAVAILABLE',
                'Firebase is not configured for this installation. Contact your administrator.',
            );
        }

        // Verify RS256 signature + standard claims (aud, iss, exp, iat, sub).
        $claims = (new FirebaseIdTokenVerifier($projectId))->verify($idToken);

        if ($claims === null) {
            return $this->failWith(
                'UNAUTHENTICATED',
                'Invalid or expired Firebase token. The OTP session may have timed out — please try again.',
            );
        }

        // Firebase Phone Auth puts the verified number in phone_number.
        $phone = trim((string) ($claims['phone_number'] ?? ''));

        if ($phone === '') {
            return $this->failWith(
                'UNAUTHENTICATED',
                'Token does not contain a phone_number claim. Ensure Phone sign-in is enabled in your Firebase project.',
            );
        }

        // Resolve the account by phone number. Firebase returns E.164
        // (+91XXXXXXXXXX); the stored value may omit the country code or '+',
        // so use the formatting-tolerant lookup (which also refuses ambiguous
        // matches) rather than a strict string equality.
        $user = service('userRepository')->findByPhone($phone);

        if ($user === null) {
            return $this->failWith(
                'NOT_FOUND',
                'No account is registered with ' . $phone . '. Please contact your administrator.',
            );
        }

        if (($user['principal_type'] ?? '') !== 'vendor') {
            return $this->failWith(
                'FORBIDDEN',
                'This phone number is not associated with a vendor account.',
            );
        }

        if (($user['status'] ?? '') !== 'active') {
            return $this->failWith(
                'FORBIDDEN',
                'This vendor account is not active. Please contact support.',
            );
        }

        // Confirm the vendor record exists (owner lookup only; staff can't install).
        $vendor = service('vendorAccountRepository')->findByOwnerUserId((int) $user['id']);

        if ($vendor === null) {
            return $this->failWith(
                'FORBIDDEN',
                'No vendor profile found for this account.',
            );
        }

        // Issue a mobile JWT identical in structure to AuthApiController::otpVerify.
        $token = service('tokenService')->issue(
            ['sub' => (int) $user['id'], 'typ' => 'vendor', 'name' => (string) $user['name']],
            self::TOKEN_TTL,
            \App\Libraries\TokenService::secret(),
            time(),
        );

        return $this->ok([
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_TTL,
            'user'       => [
                'id'             => (int) $user['id'],
                'name'           => (string) $user['name'],
                'principal_type' => 'vendor',
                'email'          => (string) $user['email'],
                'phone'          => (string) $user['phone'],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/vendor/otp/send
    // -------------------------------------------------------------------------

    public function otpSend()
    {
        $body  = $this->input();
        $phone = trim((string) ($body['phone'] ?? ''));

        if ($phone === '') {
            return $this->failWith('VALIDATION_ERROR', 'phone is required.');
        }

        $user = service('userRepository')->findByPhone($phone);
        if ($user === null) {
            return $this->failWith(
                'NOT_FOUND',
                'No account is registered with ' . $phone . '. Please contact your administrator.',
            );
        }
        if (($user['principal_type'] ?? '') !== 'vendor') {
            return $this->failWith('FORBIDDEN', 'This phone number is not associated with a vendor account.');
        }
        if (($user['status'] ?? '') !== 'active') {
            return $this->failWith('FORBIDDEN', 'This vendor account is not active.');
        }

        $code   = (new OtpService())->issue($phone, 'login');
        $result = (new SmsSender())->send($phone, 'Your Shiplore POS login code is ' . $code . '. Valid for 10 minutes.');

        // `sent` must reflect reality: it was hardcoded true even when nothing left the
        // building. And dev_code was gated on the gateway's own dev flag, which is true
        // whenever no gateway is configured — production's normal state — so this
        // endpoint handed a live POS login code to any unauthenticated caller.
        if (! ($result['ok'] ?? false)) {
            log_message('error', 'POS OTP: SMS to ' . $phone . ' undeliverable (provider=' . ($result['provider'] ?? '?') . ') ' . ($result['message'] ?? ''));

            if (ENVIRONMENT === 'production') {
                return $this->failWith('DEPENDENCY_UNAVAILABLE', 'Could not send the verification code. Please try again shortly.');
            }

            return $this->ok(['sent' => false], ['dev_code' => $code]);
        }

        return $this->ok(['sent' => true]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/vendor/otp/verify
    // -------------------------------------------------------------------------

    public function otpVerify()
    {
        $body  = $this->input();
        $phone = trim((string) ($body['phone'] ?? ''));
        $code  = trim((string) ($body['code']  ?? ''));

        if ($phone === '' || $code === '') {
            return $this->failWith('VALIDATION_ERROR', 'phone and code are required.');
        }

        if (!(new OtpService())->verify($phone, $code, 'login')) {
            return $this->failWith('UNAUTHENTICATED', 'Invalid or expired OTP. Please try again.');
        }

        $user = service('userRepository')->findByPhone($phone);
        if ($user === null || ($user['principal_type'] ?? '') !== 'vendor') {
            return $this->failWith('FORBIDDEN', 'Account not found or not a vendor.');
        }

        $vendor = service('vendorAccountRepository')->findByOwnerUserId((int) $user['id']);
        if ($vendor === null) {
            return $this->failWith('FORBIDDEN', 'No vendor profile found for this account.');
        }

        $token = service('tokenService')->issue(
            ['sub' => (int) $user['id'], 'typ' => 'vendor', 'name' => (string) $user['name']],
            self::TOKEN_TTL,
            \App\Libraries\TokenService::secret(),
            time(),
        );

        return $this->ok([
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_TTL,
            'user'       => [
                'id'             => (int) $user['id'],
                'name'           => (string) $user['name'],
                'principal_type' => 'vendor',
                'email'          => (string) $user['email'],
                'phone'          => (string) $user['phone'],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/vendor/pos/shops
    // -------------------------------------------------------------------------

    public function shops()
    {
        // Owner-only: staff don't install POS servers.
        $vendor = service('vendorAccountRepository')->findByOwnerUserId($this->userId());

        if ($vendor === null) {
            return $this->failWith('FORBIDDEN', 'This account is not a vendor owner.');
        }

        $rows = \Config\Database::connect()
            ->table('shops')
            ->select('id, name, code, gstin, state_code, address_json, pincode, status')
            ->where('vendor_id', (int) $vendor['id'])
            ->where('deleted_at', null)
            ->orderBy('name', 'ASC')
            ->get()
            ->getResultArray();

        $shops = [];
        foreach ($rows as $row) {
            // Only offer active shops for POS installation.
            if (($row['status'] ?? '') !== 'active') {
                continue;
            }

            $addr  = json_decode((string) ($row['address_json'] ?? '{}'), true);
            $parts = [];
            if (is_array($addr)) {
                foreach (['line1', 'area', 'city'] as $key) {
                    $v = trim((string) ($addr[$key] ?? ''));
                    if ($v !== '') {
                        $parts[] = $v;
                    }
                }
            }

            $shops[] = [
                'id'         => (int) $row['id'],
                'name'       => (string) $row['name'],
                'code'       => (string) $row['code'],
                'gstin'      => $row['gstin'] !== null ? (string) $row['gstin'] : null,
                'state_code' => (string) $row['state_code'],
                'address'    => $parts !== [] ? implode(', ', $parts) : null,
                'pincode'    => (string) $row['pincode'],
            ];
        }

        return $this->collection($shops, ['total' => count($shops)]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/vendor/pos/masters/(:shopId)
    // -------------------------------------------------------------------------

    /**
     * Returns all master data required to bootstrap a POS terminal in a single
     * call: shop config, category tree, tax rates, product attributes, and the
     * active staff roster. Requires vendor owner JWT (staff cannot install POS).
     */
    public function masters(int $shopId)
    {
        $vendor = service('vendorAccountRepository')->findByOwnerUserId($this->userId());
        if ($vendor === null) {
            return $this->failWith('FORBIDDEN', 'This account is not a vendor owner.');
        }

        $db   = \Config\Database::connect();
        $shop = $db->table('shops')
            ->select('id, vendor_id, name, code, gstin, state_code, address_json, pincode, status')
            ->where('id', $shopId)
            ->where('deleted_at', null)
            ->get()->getRowArray();

        if ($shop === null || (int) $shop['vendor_id'] !== (int) $vendor['id']) {
            return $this->failWith('NOT_FOUND', 'Shop not found for this vendor.');
        }
        if ($shop['status'] !== 'active') {
            return $this->failWith('FORBIDDEN', 'Shop is not active.');
        }

        // --- Shop detail (same address composition as shops()) ---
        $addr  = json_decode((string) ($shop['address_json'] ?? '{}'), true);
        $parts = [];
        if (is_array($addr)) {
            foreach (['line1', 'area', 'city'] as $key) {
                $v = trim((string) ($addr[$key] ?? ''));
                if ($v !== '') {
                    $parts[] = $v;
                }
            }
        }
        $shopDetail = [
            'id'         => (int) $shop['id'],
            'name'       => (string) $shop['name'],
            'code'       => (string) ($shop['code'] ?? ''),
            'gstin'      => isset($shop['gstin']) ? (string) $shop['gstin'] : null,
            'state_code' => (string) ($shop['state_code'] ?? ''),
            'address'    => $parts !== [] ? implode(', ', $parts) : null,
            'pincode'    => (string) ($shop['pincode'] ?? ''),
        ];

        // --- Category tree ---
        $catRows = $db->table('categories')
            ->select('id, parent_id, name, path')
            ->where('status', 'active')
            ->where("COALESCE(path,'') NOT LIKE 'hotels-hospitality%'", null, false)
            ->orderBy('path', 'ASC')
            ->get()->getResultArray();

        $categoryList = array_map(static fn ($c) => [
            'id'        => (int) $c['id'],
            'name'      => (string) $c['name'],
            'parent_id' => isset($c['parent_id']) ? (int) $c['parent_id'] : null,
            'path'      => isset($c['path']) ? (string) $c['path'] : null,
        ], $catRows);

        // --- Tax configuration ---
        // gst_config: prefer vendor-scoped row, fall back to first global row.
        $gstRow = $db->table('gst_config')
            ->where('scope_type', 'vendor')
            ->where('scope_id', (int) $vendor['id'])
            ->limit(1)->get()->getRowArray()
            ?? $db->table('gst_config')->orderBy('id', 'ASC')->limit(1)->get()->getRowArray();

        $taxClasses = $db->table('tax_classes')
            ->select('id, code, name, is_exempt')
            ->where('status', 'active')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        // Latest rate per class valid today
        $taxRates = $db->table('tax_rates')
            ->select('tax_class_id, cgst, sgst, igst, cess, valid_from')
            ->where('status', 'active')
            ->groupStart()
                ->where('valid_to IS NULL', null, false)
                ->orWhere('valid_to >=', date('Y-m-d'))
            ->groupEnd()
            ->orderBy('tax_class_id', 'ASC')
            ->orderBy('valid_from', 'DESC')
            ->get()->getResultArray();

        // De-duplicate: keep only the most-recent rate per tax_class_id
        $latestRates = [];
        foreach ($taxRates as $r) {
            $cid = (int) $r['tax_class_id'];
            if (!isset($latestRates[$cid])) {
                $latestRates[$cid] = $r;
            }
        }

        $hsnCodes = $db->table('hsn_sac_codes')
            ->select('id, code, description, default_tax_class_id')
            ->where('status', 'active')
            ->orderBy('code', 'ASC')
            ->get()->getResultArray();

        // --- Product attributes + values ---
        $attrRows = $db->table('attributes')
            ->select('id, code, name, type')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $avRows = $db->table('attribute_values')
            ->select('id, attribute_id, value')
            ->orderBy('attribute_id', 'ASC')
            ->orderBy('sort_order', 'ASC')
            ->get()->getResultArray();

        $valuesByAttr = [];
        foreach ($avRows as $av) {
            $valuesByAttr[(int) $av['attribute_id']][] = [
                'id'    => (int) $av['id'],
                'value' => (string) $av['value'],
            ];
        }
        $attributeList = array_map(static fn ($a) => [
            'id'     => (int) $a['id'],
            'code'   => (string) $a['code'],
            'name'   => (string) $a['name'],
            'type'   => (string) ($a['type'] ?? 'select'),
            'values' => $valuesByAttr[(int) $a['id']] ?? [],
        ], $attrRows);

        // --- Active staff for this vendor ---
        $staffRows = $db->table('vendor_staff vs')
            ->select('u.id, u.name, u.email, u.phone, vs.staff_type, vs.employee_code')
            ->join('users u', 'u.id = vs.user_id', 'left')
            ->where('vs.vendor_id', (int) $vendor['id'])
            ->where('vs.status', 'active')
            ->where('u.status', 'active')
            ->orderBy('u.name', 'ASC')
            ->get()->getResultArray();

        $staffList = array_map(static fn ($s) => [
            'id'            => (int) $s['id'],
            'name'          => (string) $s['name'],
            'email'         => isset($s['email']) ? (string) $s['email'] : null,
            'phone'         => isset($s['phone']) ? (string) $s['phone'] : null,
            'staff_type'    => (string) ($s['staff_type'] ?? 'cashier'),
            'employee_code' => isset($s['employee_code']) ? (string) $s['employee_code'] : null,
        ], $staffRows);

        return $this->ok([
            'server_time' => date('Y-m-d H:i:s'),
            'shop'        => $shopDetail,
            'categories'  => $categoryList,
            'tax_config'  => [
                'gst_config'  => $gstRow !== null ? [
                    'rounding_mode'  => (string) ($gstRow['rounding_mode'] ?? 'nearest'),
                    'composition'    => (bool) ($gstRow['composition'] ?? false),
                    'fy_start_month' => (int) ($gstRow['fy_start_month'] ?? 4),
                ] : null,
                'tax_classes' => array_map(static fn ($c) => [
                    'id'        => (int) $c['id'],
                    'code'      => (string) $c['code'],
                    'name'      => (string) $c['name'],
                    'is_exempt' => (bool) ($c['is_exempt'] ?? false),
                ], $taxClasses),
                'tax_rates' => array_values(array_map(static fn ($r) => [
                    'tax_class_id' => (int) $r['tax_class_id'],
                    'cgst'         => (string) $r['cgst'],
                    'sgst'         => (string) $r['sgst'],
                    'igst'         => (string) $r['igst'],
                    'cess'         => (string) $r['cess'],
                    'valid_from'   => (string) $r['valid_from'],
                ], $latestRates)),
                'hsn_codes' => array_map(static fn ($h) => [
                    'id'                   => (int) $h['id'],
                    'code'                 => (string) $h['code'],
                    'description'          => isset($h['description']) ? (string) $h['description'] : null,
                    'default_tax_class_id' => isset($h['default_tax_class_id']) ? (int) $h['default_tax_class_id'] : null,
                ], $hsnCodes),
            ],
            'attributes' => $attributeList,
            'staff'      => $staffList,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/vendor/pos/sale
    // -------------------------------------------------------------------------

    /**
     * Create a POS sale: validate stock, deduct inventory, compute GST, record the sale.
     * Returns a full receipt payload including shop GSTIN, GST breakdown, and customer info.
     */
    public function createSale()
    {
        $vendor = service('vendorAccountRepository')->findByOwnerUserId($this->userId());
        if ($vendor === null) {
            return $this->failWith('FORBIDDEN', 'This account is not a vendor owner.');
        }

        $in            = $this->input();
        $shopId        = (int) ($in['shop_id'] ?? 0);
        $lines         = $in['lines'] ?? [];
        $paymentMethod = trim((string) ($in['payment_method'] ?? 'cash'));
        $customerName  = trim((string) ($in['customer_name'] ?? ''));
        $customerPhone = trim((string) ($in['customer_phone'] ?? ''));
        $discountInput = (float) ($in['discount'] ?? 0);
        $discountType  = trim((string) ($in['discount_type'] ?? 'fixed'));

        if ($shopId <= 0 || ! is_array($lines) || count($lines) === 0) {
            return $this->failWith('VALIDATION_ERROR', 'shop_id and lines are required.');
        }
        if (! in_array($paymentMethod, ['cash', 'card', 'upi', 'credit'], true)) {
            return $this->failWith('VALIDATION_ERROR', 'payment_method must be cash, card, upi or credit.');
        }
        if (! in_array($discountType, ['pct', 'fixed'], true)) {
            return $this->failWith('VALIDATION_ERROR', 'discount_type must be pct or fixed.');
        }

        $db   = \Config\Database::connect();
        $shop = $db->table('shops')
            ->select('id, name, code, gstin, state_code, address_json')
            ->where('id', $shopId)->where('vendor_id', (int) $vendor['id'])
            ->where('deleted_at', null)->where('status', 'active')
            ->get()->getRowArray();
        if ($shop === null) {
            return $this->failWith('NOT_FOUND', 'Shop not found.');
        }

        $gst      = new \App\Libraries\Tax\GstCalculator();
        $subtotal = 0.0;
        $saleItems = [];
        $svc      = service('inventoryService');

        // Batch-load variant info + tax rates
        $variantIds = array_filter(array_map(static fn ($l) => (int) ($l['variant_id'] ?? 0), $lines));
        if (empty($variantIds)) {
            return $this->failWith('VALIDATION_ERROR', 'No valid variant_ids in lines.');
        }

        $taxRows = $db->table('product_variants pv')
            ->select(
                'pv.id AS variant_id, pv.sku, p.title,' .
                ' COALESCE(hs.code, \'\') AS hsn_code,' .
                ' COALESCE((SELECT tr2.igst FROM tax_rates tr2 WHERE tr2.tax_class_id = p.tax_class_id' .
                "  AND tr2.status = 'active' ORDER BY tr2.valid_from DESC LIMIT 1), 0) AS tax_rate_pct"
            )
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->join('hsn_sac_codes hs', 'hs.id = p.hsn_sac_id', 'left')
            ->whereIn('pv.id', array_values($variantIds))
            ->where('pv.vendor_id', (int) $vendor['id'])
            ->get()->getResultArray();

        $taxMap = [];
        foreach ($taxRows as $row) {
            $taxMap[(int) $row['variant_id']] = $row;
        }

        foreach ($lines as $line) {
            $variantId = (int) ($line['variant_id'] ?? 0);
            $qty       = (float) ($line['qty'] ?? 0);
            $unitPrice = (float) ($line['unit_price'] ?? 0);

            if ($variantId <= 0 || $qty <= 0 || $unitPrice < 0) {
                return $this->failWith('VALIDATION_ERROR', 'Each line requires variant_id, qty > 0 and unit_price ≥ 0.');
            }
            if (! isset($taxMap[$variantId])) {
                return $this->failWith('NOT_FOUND', "Variant {$variantId} not found.");
            }

            try {
                $svc->adjust($variantId, $shopId, -$qty, 'pos_sale', '', $this->userId());
            } catch (\Throwable) {
                return $this->failWith('CONFLICT', "Insufficient stock for variant {$variantId}.");
            }

            $lineTotal   = round($unitPrice * $qty, 4);
            $taxRatePct  = (float) ($taxMap[$variantId]['tax_rate_pct'] ?? 0);
            $g           = $gst->compute((string) $lineTotal, $taxRatePct, true, false);

            $subtotal   += $lineTotal;
            $saleItems[] = [
                'variant_id'    => $variantId,
                'sku'           => (string) ($taxMap[$variantId]['sku'] ?? ''),
                'product_title' => (string) ($taxMap[$variantId]['title'] ?? ''),
                'hsn_code'      => (string) ($taxMap[$variantId]['hsn_code'] ?? ''),
                'qty'           => $qty,
                'unit_price'    => $unitPrice,
                'line_total'    => $lineTotal,
                'tax_rate'      => $taxRatePct,
                'taxable_value' => (float) $g['taxable'],
                'cgst'          => (float) $g['cgst'],
                'sgst'          => (float) $g['sgst'],
                'igst'          => (float) $g['igst'],
            ];
        }

        $subtotal = round($subtotal, 2);

        // Apply discount and recompute GST proportionally
        $discountAmount = $discountType === 'pct'
            ? round($subtotal * $discountInput / 100, 2)
            : min(round($discountInput, 2), $subtotal);

        $discountRatio = $subtotal > 0 ? ($subtotal - $discountAmount) / $subtotal : 1.0;
        $totalTaxable  = 0.0;
        $totalCgst     = 0.0;
        $totalSgst     = 0.0;
        $totalIgst     = 0.0;
        $finalItems    = [];

        foreach ($saleItems as $item) {
            $adjLine     = round($item['line_total'] * $discountRatio, 4);
            $g           = $gst->compute((string) $adjLine, $item['tax_rate'], true, false);
            $totalTaxable += (float) $g['taxable'];
            $totalCgst    += (float) $g['cgst'];
            $totalSgst    += (float) $g['sgst'];
            $totalIgst    += (float) $g['igst'];
            $finalItems[] = array_merge($item, [
                'taxable_value' => (float) $g['taxable'],
                'cgst'          => (float) $g['cgst'],
                'sgst'          => (float) $g['sgst'],
                'igst'          => (float) $g['igst'],
            ]);
        }

        $grandTotal   = round($subtotal - $discountAmount, 2);
        $totalTaxable = round($totalTaxable, 2);
        $totalCgst    = round($totalCgst, 2);
        $totalSgst    = round($totalSgst, 2);
        $totalIgst    = round($totalIgst, 2);

        $code      = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', (string) ($shop['code'] ?? 'S')));
        $invoiceNo = 'POS-' . ($code !== '' ? $code . '-' : '') . date('Ymd') . '-' . rand(1000, 9999);
        $soldAt    = date('Y-m-d H:i:s');
        $saleId    = null;

        $addr      = json_decode((string) ($shop['address_json'] ?? '{}'), true);
        $addrParts = [];
        if (is_array($addr)) {
            foreach (['line1', 'area', 'city'] as $key) {
                $v = trim((string) ($addr[$key] ?? ''));
                if ($v !== '') {
                    $addrParts[] = $v;
                }
            }
        }
        if ((string) ($shop['pincode'] ?? '') !== '') {
            $addrParts[] = (string) $shop['pincode'];
        }

        try {
            $db->table('pos_sales')->insert([
                'uuid'              => bin2hex(random_bytes(18)),
                'vendor_id'         => (int) $vendor['id'],
                'shop_id'           => $shopId,
                'cashier_user_id'   => $this->userId(),
                'customer_name'     => $customerName !== '' ? $customerName : null,
                'customer_phone'    => $customerPhone !== '' ? $customerPhone : null,
                'server_invoice_no' => $invoiceNo,
                'subtotal'          => $subtotal,
                'discount_total'    => $discountAmount,
                'taxable_value'     => $totalTaxable,
                'cgst'              => $totalCgst,
                'sgst'              => $totalSgst,
                'igst'              => $totalIgst,
                'grand_total'       => $grandTotal,
                'payment_method'    => $paymentMethod,
                'sold_at'           => $soldAt,
            ]);
            $saleId = (int) $db->insertID();

            if ($saleId > 0) {
                foreach ($finalItems as $item) {
                    $db->table('pos_sale_items')->insert([
                        'uuid'                   => bin2hex(random_bytes(18)),
                        'pos_sale_id'            => $saleId,
                        'variant_id'             => $item['variant_id'],
                        'sku_snapshot'           => $item['sku'],
                        'product_title_snapshot' => $item['product_title'],
                        'hsn_snapshot'           => $item['hsn_code'] !== '' ? $item['hsn_code'] : null,
                        'qty'                    => $item['qty'],
                        'unit_price'             => $item['unit_price'],
                        'tax_rate'               => $item['tax_rate'],
                        'taxable_value'          => $item['taxable_value'],
                        'cgst'                   => $item['cgst'],
                        'sgst'                   => $item['sgst'],
                        'igst'                   => $item['igst'],
                        'line_total'             => $item['line_total'],
                        'is_gst_inclusive'       => 1,
                    ]);
                }

                $tenderType = $paymentMethod === 'credit' ? 'wallet' : $paymentMethod;
                $db->table('pos_sale_payments')->insert([
                    'uuid'        => bin2hex(random_bytes(18)),
                    'pos_sale_id' => $saleId,
                    'tender_type' => $tenderType,
                    'amount'      => $grandTotal,
                    'status'      => 'captured',
                ]);
            }
        } catch (\Throwable) {
            log_message('error', '[POS] pos_sales insert failed for vendor ' . $vendor['id']);
        }

        return $this->ok([
            'sale_id'        => $saleId,
            'invoice_no'     => $invoiceNo,
            'sold_at'        => $soldAt,
            'shop'           => [
                'name'    => (string) $shop['name'],
                'gstin'   => isset($shop['gstin']) && $shop['gstin'] !== '' ? (string) $shop['gstin'] : null,
                'address' => $addrParts !== [] ? implode(', ', $addrParts) : null,
            ],
            'customer_name'  => $customerName !== '' ? $customerName : null,
            'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
            'payment_method' => $paymentMethod,
            'subtotal'       => $subtotal,
            'discount'       => $discountAmount,
            'taxable_value'  => $totalTaxable,
            'total_cgst'     => $totalCgst,
            'total_sgst'     => $totalSgst,
            'total_igst'     => $totalIgst,
            'grand_total'    => $grandTotal,
            'items'          => array_map(static fn ($i) => [
                'product_title' => $i['product_title'],
                'sku'           => $i['sku'],
                'hsn_code'      => $i['hsn_code'],
                'qty'           => $i['qty'],
                'unit_price'    => $i['unit_price'],
                'tax_rate'      => $i['tax_rate'],
                'taxable_value' => $i['taxable_value'],
                'cgst'          => $i['cgst'],
                'sgst'          => $i['sgst'],
                'igst'          => $i['igst'],
                'line_total'    => $i['line_total'],
            ], $finalItems),
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/vendor/pos/catalog/(:shopId)
    // -------------------------------------------------------------------------

    public function catalogBootstrap(int $shopId)
    {
        $vendor = service('vendorAccountRepository')->findByOwnerUserId($this->userId());
        if ($vendor === null) {
            return $this->failWith('FORBIDDEN', 'This account is not a vendor owner.');
        }

        $db   = \Config\Database::connect();
        $shop = $db->table('shops')
            ->select('id, vendor_id, status')
            ->where('id', $shopId)
            ->where('deleted_at', null)
            ->get()->getRowArray();

        if ($shop === null || (int) $shop['vendor_id'] !== (int) $vendor['id']) {
            return $this->failWith('NOT_FOUND', 'Shop not found for this vendor.');
        }
        if ($shop['status'] !== 'active') {
            return $this->failWith('FORBIDDEN', 'Shop is not active.');
        }

        $catalog = $db->table('product_variants pv')
            ->select(
                'pv.id AS variant_id, pv.sku, pv.barcode, pv.mrp, pv.base_price,' .
                ' p.id AS product_id, p.title, p.product_type,' .
                ' hs.code AS hsn_code, tc.code AS tax_code, p.status AS product_status,' .
                ' c.name AS category_name,' .
                ' (SELECT ma.uuid FROM product_media pm JOIN media_assets ma ON ma.id = pm.media_id WHERE pm.product_id = p.id AND pm.deleted_at IS NULL AND ma.deleted_at IS NULL AND ma.status = \'active\' ORDER BY pm.is_primary DESC, pm.sort_order ASC LIMIT 1) AS image_uuid,' .
                ' (SELECT tr.igst FROM tax_rates tr WHERE tr.tax_class_id = p.tax_class_id' .
                "  AND tr.status = 'active' ORDER BY tr.valid_from DESC LIMIT 1) AS tax_rate_pct"
            )
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->join('hsn_sac_codes hs', 'hs.id = p.hsn_sac_id', 'left')
            ->join('tax_classes tc', 'tc.id = p.tax_class_id', 'left')
            ->join(
                'product_shops ps',
                'ps.product_id = p.id AND ps.shop_id = ' . $shopId,
                'left'
            )
            ->where('pv.vendor_id', (int) $vendor['id'])
            ->where('p.status', 'published')
            ->where('pv.deleted_at', null)
            ->where("(ps.id IS NULL OR ps.status = 'active')", null, false)
            ->where("COALESCE(c.path,'') NOT LIKE 'hotels-hospitality%'", null, false)
            ->get()->getResultArray();

        $stock = $db->table('inventory')
            ->select('variant_id, on_hand, reserved, available')
            ->where('shop_id', $shopId)
            ->get()->getResultArray();

        return $this->ok([
            'server_time' => date('Y-m-d H:i:s'),
            'items'       => $catalog,
            'stock'       => $stock,
            'total'       => count($catalog),
        ]);
    }
}
