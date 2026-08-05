<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

/**
 * Vendor\PosController — the in-browser web POS for a vendor's store. A fast,
 * keyboard-first billing screen: search/scan -> cart -> pay (split) -> save +
 * 80mm receipt. Prices and GST are computed server-side (PosSaleRepository);
 * the screen never trusts client totals. Tenant + shop scoped.
 */
final class PosController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        // S0: only the shops this user is assigned to (owner = all). A foreign
        // ?shop_id is ignored by requestedShopId(); we fall back to the first
        // allowed shop so a cashier can never bill at an unassigned store.
        $shops  = $this->allowedShops();
        $shopId = $this->requestedShopId() ?? (int) ($shops[0]['id'] ?? 0);
        $shop   = null;
        foreach ($shops as $s) {
            if ((int) $s['id'] === $shopId) {
                $shop = $s;
            }
        }

        return $this->render('vendor/pos/index', 'pos', 'Point of Sale', [
            'shops' => $shops, 'shopId' => $shopId, 'shop' => $shop,
            'can' => [
                'sell' => $this->posCan('pos.sell'),
                'discount' => $this->posCan('pos.discount.apply'),
                'credit' => $this->posCan('pos.credit.sale'),
            ],
        ]);
    }

    /** Bill / receipt settings for the active store (header note, terms, footer). */
    public function settings()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $shopId = $this->activeShopId() ?? ($this->allowedShopIds()[0] ?? 0);
        if ($shopId <= 0) {
            return redirect()->to('vendor/pos')->with('error', 'No store to configure.');
        }

        return $this->render('vendor/pos/settings', 'pos', 'Bill Settings', [
            'bill'   => service('shopBillSettingsRepository')->forShop((int) $shopId),
            'shopId' => (int) $shopId,
            'canEdit' => $this->isOwner() || $this->can('shop.update'),
        ]);
    }

    public function saveSettings(): \CodeIgniter\HTTP\RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $shopId = (int) $this->request->getPost('shop_id');
        if (! in_array($shopId, $this->allowedShopIds(), true)) {
            return redirect()->to('vendor/pos/settings')->with('error', 'That is not your store.');
        }
        if (! $this->isOwner() && ! $this->can('shop.update')) {
            return redirect()->to('vendor/pos/settings')->with('error', "You don't have permission to change bill settings.");
        }
        service('shopBillSettingsRepository')->save($shopId, [
            'header_note'  => (string) $this->request->getPost('header_note'),
            'terms'        => (string) $this->request->getPost('terms'),
            'footer_note'  => (string) $this->request->getPost('footer_note'),
            'show_savings' => (bool) $this->request->getPost('show_savings'),
        ], (int) session()->get('user_id'));

        return redirect()->to('vendor/pos/settings')->with('success', 'Bill settings saved.');
    }

    public function returns()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->posCan('pos.return.process')) {
            return redirect()->to('vendor/pos')->with('error', "You don't have permission to process returns.");
        }
        $ref  = trim((string) $this->request->getGet('ref'));
        $sale = $ref !== '' ? service('posReturnRepository')->findSale($ref, $this->vendorId()) : null;

        return $this->render('vendor/pos/returns', 'pos', 'Returns / Exchange', [
            'ref' => $ref, 'sale' => $sale, 'recent' => service('posReturnRepository')->recent($this->vendorId(), 20),
        ]);
    }

    public function processReturn()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->posCan('pos.return.process')) {
            return redirect()->to('vendor/pos')->with('error', "You don't have permission to process returns.");
        }
        $items = [];
        foreach ((array) $this->request->getPost('qty') as $itemId => $qty) {
            if ((float) $qty > 0) {
                $items[] = ['pos_sale_item_id' => (int) $itemId, 'qty' => (float) $qty];
            }
        }
        $res = service('posReturnRepository')->createReturn(
            (int) $this->request->getPost('sale_id'), $this->vendorId(), $items,
            (string) $this->request->getPost('reason'), (string) $this->request->getPost('refund_method'), (int) session()->get('user_id'),
        );
        // on success go straight to the printable Credit Note (its own bill no.)
        if ($res['ok'] && ! empty($res['return_id'])) {
            return redirect()->to('vendor/pos/credit-note/' . (int) $res['return_id'])->with('success', $res['message']);
        }

        return redirect()->to('vendor/pos/returns')->with('error', $res['message']);
    }

    /** Standalone (walk-in) Credit Note screen — a refund without the original bill. */
    public function creditNote()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->posCan('pos.return.process')) {
            return redirect()->to('vendor/pos')->with('error', "You don't have permission to issue credit notes.");
        }
        $shopId = $this->activeShopId() ?? ($this->allowedShopIds()[0] ?? 0);

        return $this->render('vendor/pos/credit_note', 'pos', 'Credit Note', [
            'shopId' => (int) $shopId,
            'recent' => service('posReturnRepository')->recent($this->vendorId(), 20),
        ]);
    }

    public function createCreditNote(): \CodeIgniter\HTTP\RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->posCan('pos.return.process')) {
            return redirect()->to('vendor/pos')->with('error', "You don't have permission to issue credit notes.");
        }
        $shopId = (int) $this->request->getPost('shop_id');
        if (! in_array($shopId, $this->allowedShopIds(), true)) {
            return redirect()->to('vendor/pos/credit-note')->with('error', 'That is not your store.');
        }
        $lines = [];
        foreach ((array) $this->request->getPost('line_title') as $i => $title) {
            $title = trim((string) $title);
            $qty   = (float) (((array) $this->request->getPost('line_qty'))[$i] ?? 0);
            $price = (float) (((array) $this->request->getPost('line_price'))[$i] ?? 0);
            if ($title !== '' && $qty > 0 && $price > 0) {
                $lines[] = [
                    'variant_id' => (int) (((array) $this->request->getPost('line_variant'))[$i] ?? 0) ?: null,
                    'title'      => $title, 'qty' => $qty, 'unit_price' => $price,
                    'tax_rate'   => (float) (((array) $this->request->getPost('line_tax'))[$i] ?? 0),
                ];
            }
        }
        $res = service('posReturnRepository')->createStandalone(
            $this->vendorId(), $shopId, $lines,
            ['name' => $this->request->getPost('customer_name'), 'phone' => $this->request->getPost('customer_phone')],
            (string) $this->request->getPost('reason'), (string) $this->request->getPost('refund_method'), (int) session()->get('user_id'),
        );
        if ($res['ok'] && ! empty($res['return_id'])) {
            return redirect()->to('vendor/pos/credit-note/' . (int) $res['return_id'])->with('success', $res['message']);
        }

        return redirect()->to('vendor/pos/credit-note')->with('error', $res['message']);
    }

    /** 80mm Credit Note print. */
    public function creditNoteReceipt(int $id)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $cn = service('posReturnRepository')->findCreditNote($id, $this->vendorId());
        if ($cn === null) {
            return redirect()->to('vendor/pos/returns')->with('error', 'Credit note not found.');
        }

        return view('vendor/pos/cn_receipt', [
            'cn'   => $cn,
            'bill' => service('shopBillSettingsRepository')->forShop((int) $cn['shop_id']),
        ]);
    }

    public function reports()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $vid    = $this->vendorId();
        // S0: staff reports are confined to their shop(s) — effectiveShopId()
        // returns the owner's chosen/all (null) or the staff member"s shop, never
        // the whole vendor for a branch manager.
        $shops  = $this->allowedShops();
        $shopId = $this->effectiveShopId();
        $from   = (string) ($this->request->getGet('from') ?: date('Y-m-d', strtotime('-29 days')));
        $to     = (string) ($this->request->getGet('to') ?: date('Y-m-d'));
        $repo   = service('posReportRepository');

        return $this->render('vendor/pos/reports', 'pos', 'POS Reports', [
            'shops' => $shops, 'shopId' => $shopId, 'from' => $from, 'to' => $to,
            'summary' => $repo->summary($vid, $shopId, $from, $to),
            'byMethod' => $repo->byMethod($vid, $shopId, $from, $to),
            'byDay' => $repo->byDay($vid, $shopId, $from, $to),
            'top' => $repo->topProducts($vid, $shopId, $from, $to),
            'credit' => service('creditRepository')->summary($vid),
        ]);
    }

    /**
     * S5 — export the active store's daily POS sales as CSV. A report, so it
     * needs no approval; gated by report.export (the owner always may).
     */
    public function exportReport()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->isOwner() && ! $this->can('report.export')) {
            return redirect()->to('vendor/pos/reports')->with('error', "You don't have permission to export reports.");
        }
        $shopId = $this->effectiveShopId(); // owner: chosen/all; staff: their store
        $from   = (string) ($this->request->getGet('from') ?: date('Y-m-d', strtotime('-29 days')));
        $to     = (string) ($this->request->getGet('to') ?: date('Y-m-d'));
        // Same guard as Admin\ReportController::period(): these are interpolated into
        // the quoted Content-Disposition filename below, so a `"` would close the
        // parameter early and let the caller append their own. Only a literal Y-m-d
        // may pass; the UI's date inputs always produce exactly that.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-d', strtotime('-29 days'));
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-d');
        }
        $rows = service('posReportRepository')->byDay((int) $this->vendorId(), $shopId, $from, $to);

        $csv = \App\Libraries\Reports\ReportExportService::toCsv(
            ['date', 'bills', 'sales'],
            array_map(static fn (array $r): array => [$r['day'], $r['bills'], $r['sales']], $rows),
        );

        return $this->response
            ->setHeader('Content-Type', 'text/csv')
            ->setHeader('Content-Disposition', 'attachment; filename="pos-sales-' . $from . '_' . $to . '.csv"')
            ->setBody($csv);
    }

    /** Store-scoped POS permission check (RBAC via PolicyEngine). */
    private function posCan(string $permission): bool
    {
        return service('policyEngine')->can(service('scopeContext')->all(), $permission);
    }

    public function search()
    {
        if ($this->vendorId() <= 0) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }
        // S0: never search a store the user isn't assigned to.
        $shopId = (int) $this->request->getGet('shop_id');
        if ($shopId > 0 && ! in_array($shopId, $this->allowedShopIds(), true)) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'message' => 'Not your store.']);
        }
        $items = service('posSaleRepository')->search($this->vendorId(), $shopId, (string) $this->request->getGet('q'));

        return $this->response->setJSON(['ok' => true, 'items' => $items]);
    }

    public function scan(string $code)
    {
        if ($this->vendorId() <= 0) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }
        // a barcode may map to several items (different pack/price) -> picker.
        // Scope to the cashier's shop so a scan can't resolve a product not listed here.
        $shopId = $this->activeShopId() ?? ($this->allowedShopIds()[0] ?? 0);
        $scope  = $shopId > 0 ? (int) $shopId : null;
        $hits = service('productBarcodeRepository')->resolveAll($code, $this->vendorId(), $scope);
        $variantIds = array_values(array_unique(array_filter(array_map(static fn ($h) => (int) ($h['resolved_variant_id'] ?? 0), $hits))));
        if ($variantIds === []) {
            return $this->response->setJSON(['ok' => false, 'message' => 'No product for that barcode.']);
        }
        $items = service('posSaleRepository')->resolveLines($this->vendorId(), array_fill_keys($variantIds, 1), $scope);

        return $this->response->setJSON(['ok' => true, 'items' => $items, 'multiple' => count($items) > 1, 'item' => $items[0] ?? null, 'csrf' => csrf_hash()]);
    }

    public function sale()
    {
        if ($this->requireVendor() !== null || ! $this->posCan('pos.sell')) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'message' => "You don't have permission to bill at this POS.", 'csrf' => csrf_hash()]);
        }
        // S0: the cashier may bill ONLY at a store they are assigned to
        // (allowedShopIds covers both vendor ownership and shop assignment).
        $shopId = (int) $this->request->getPost('shop_id');
        if (! in_array($shopId, $this->allowedShopIds(), true)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'message' => 'Pick a valid store.']);
        }
        // discount permission + cap (the cashier may not be allowed, or only up to a % cap)
        $billDiscount = (float) $this->request->getPost('bill_discount');
        if ($billDiscount > 0 && ! $this->posCan('pos.discount.apply')) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'message' => "You're not allowed to apply a discount.", 'csrf' => csrf_hash()]);
        }
        // cart: [{variant_id, qty}] — prices are re-resolved on the server, not trusted from the client
        $cart = [];
        foreach ((array) $this->request->getPost('cart') as $row) {
            $vid = (int) ($row['variant_id'] ?? 0);
            $qty = (float) ($row['qty'] ?? 0);
            if ($vid > 0 && $qty > 0) {
                $cart[$vid] = ($cart[$vid] ?? 0) + $qty;
            }
        }
        $lines = service('posSaleRepository')->resolveLines($this->vendorId(), $cart, $shopId);
        // temp products: free-text lines that aren't in the catalogue (variant_id = 0)
        foreach ((array) $this->request->getPost('temp_lines') as $t) {
            $price = (float) ($t['price'] ?? 0);
            $qty   = (float) ($t['qty'] ?? 0);
            if (($t['name'] ?? '') !== '' && $price > 0 && $qty > 0) {
                $lines[] = ['variant_id' => 0, 'sku' => 'TEMP', 'title' => mb_substr((string) $t['name'], 0, 191), 'hsn' => '', 'qty' => $qty, 'unit_price' => $price, 'tax_rate' => (float) ($t['tax_rate'] ?? 0)];
            }
        }
        $payments = [];
        foreach ((array) $this->request->getPost('payments') as $p) {
            if ((float) ($p['amount'] ?? 0) > 0) {
                $payments[] = ['tender_type' => (string) ($p['tender_type'] ?? 'cash'), 'amount' => (float) $p['amount'], 'reference' => (string) ($p['reference'] ?? '')];
            }
        }

        // credit / udhaar: needs the credit permission, then name + mobile
        $mode = $this->request->getPost('mode') === 'credit' ? 'credit' : 'sale';
        if ($mode === 'credit' && ! $this->posCan('pos.credit.sale')) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'message' => "You're not allowed to sell on credit.", 'csrf' => csrf_hash()]);
        }
        $customerId = $this->request->getPost('customer_id') ?: null;
        if ($mode === 'credit' && $customerId === null) {
            $customerId = service('creditRepository')->findOrCreateCustomer(
                (string) $this->request->getPost('customer_name'),
                (string) $this->request->getPost('customer_mobile'),
                (string) $this->request->getPost('customer_email'),
                (int) session()->get('user_id'),
            );
            if ($customerId === null) {
                return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'message' => "Credit sale needs the customer's name and mobile number.", 'csrf' => csrf_hash()]);
            }
        }

        // discount cap: the cashier's role may limit the discount % (user_roles.attributes.discount_cap)
        $cap = (float) (service('scopeContext')->all()['attributes']['discount_cap'] ?? 0);
        if ($billDiscount > 0 && $cap > 0) {
            $subtotal = 0.0;
            foreach ($lines as $l) {
                $subtotal += (float) $l['unit_price'] * (float) $l['qty'];
            }
            $pct = $subtotal > 0 ? $billDiscount / $subtotal * 100 : 100.0;
            if ($pct > $cap + 0.01) {
                return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'message' => 'Discount ' . round($pct, 1) . '% exceeds your cap of ' . rtrim(rtrim(number_format($cap, 2), '0'), '.') . '%.', 'csrf' => csrf_hash()]);
            }
        }

        // order type: TAKE AWAY (default — handed over at the counter) or DELIVERY.
        // A delivery bill requires the customer's name + mobile + address (email
        // optional); the same details drive the delivery order created below.
        $deliver = (bool) $this->request->getPost('deliver');
        if ($deliver) {
            $dName = trim((string) $this->request->getPost('deliver_name'));
            $dPhone = trim((string) $this->request->getPost('deliver_phone'));
            $dAddr = trim((string) $this->request->getPost('deliver_address'));
            if ($dName === '' || $dPhone === '' || $dAddr === '') {
                return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'message' => "Delivery needs the customer's name, mobile and address.", 'csrf' => csrf_hash()]);
            }
        }
        $orderType   = $deliver ? 'delivery' : 'takeaway';
        $deliveryFee = $deliver ? (float) $this->request->getPost('delivery_fee') : 0.0;

        $res = service('posSaleRepository')->createSale(
            ['shop_id' => $shopId, 'vendor_id' => $this->vendorId(), 'cashier_user_id' => (int) session()->get('user_id')],
            $lines,
            $payments,
            [
                'customer_id' => $customerId, 'mode' => $mode, 'order_type' => $orderType,
                'due_date' => (string) $this->request->getPost('due_date'),
                'bill_discount' => (float) $this->request->getPost('bill_discount'),
                'delivery_fee' => $deliveryFee,
                'client_uuid' => (string) $this->request->getPost('client_uuid'),
            ],
        );

        if ($res['ok'] && $deliver) {
            $deliveryId = service('posDeliveryRepository')->createForSale(
                (int) $res['sale_id'], $shopId,
                [
                    'name' => (string) $this->request->getPost('deliver_name'),
                    'phone' => (string) $this->request->getPost('deliver_phone'),
                    'address' => (string) $this->request->getPost('deliver_address'),
                ],
                $deliveryFee, (string) $this->request->getPost('deliver_date'), (int) session()->get('user_id'),
            );
            $res['delivery_id'] = $deliveryId;
            $res['message']     = $deliveryId ? 'Sale saved — delivery order created.' : 'Sale saved, but the delivery needs a name + address.';
        }
        $res['csrf'] = csrf_hash();

        return $this->response->setJSON($res);
    }

    public function credits()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $repo = service('creditRepository');

        return $this->render('vendor/pos/credits', 'pos', 'Credit / Udhaar', [
            'credits' => $repo->outstanding($this->vendorId()),
            'summary' => $repo->summary($this->vendorId()),
        ]);
    }

    public function repay(int $creditId)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $res = service('creditRepository')->repay(
            $creditId, $this->vendorId(), (float) $this->request->getPost('amount'),
            (string) $this->request->getPost('method'), (string) $this->request->getPost('reference'), (int) session()->get('user_id'),
        );

        return redirect()->to('vendor/pos/credits')->with($res['ok'] ? 'success' : 'error', $res['message']);
    }

    public function receipt(int $saleId)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $sale = service('posSaleRepository')->findForReceipt($saleId, $this->vendorId());
        if ($sale === null) {
            return redirect()->to('vendor/pos')->with('error', 'Receipt not found.');
        }

        return view('vendor/pos/receipt', [
            'sale' => $sale,
            'bill' => service('shopBillSettingsRepository')->forShop((int) $sale['shop_id']),
        ]);
    }

    /** 80mm sale-receipt PDF (DMart-style). */
    public function receiptPdf(int $saleId)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $sale = service('posSaleRepository')->findForReceipt($saleId, $this->vendorId());
        if ($sale === null) {
            return redirect()->to('vendor/pos')->with('error', 'Receipt not found.');
        }
        $res = service('documentPdfService')->posReceiptPdf($sale, service('shopBillSettingsRepository')->forShop((int) $sale['shop_id']));
        if (! ($res['ok'] ?? false)) {
            return redirect()->to('vendor/pos/receipt/' . $saleId)->with('error', 'Could not generate the PDF.');
        }

        return $this->response->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $res['filename'] . '"')
            ->setBody($res['bytes']);
    }

    /** 80mm credit-note PDF (DMart-style). */
    public function creditNotePdf(int $id)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $cn = service('posReturnRepository')->findCreditNote($id, $this->vendorId());
        if ($cn === null) {
            return redirect()->to('vendor/pos/returns')->with('error', 'Credit note not found.');
        }
        $res = service('documentPdfService')->posCreditNotePdf($cn, service('shopBillSettingsRepository')->forShop((int) $cn['shop_id']));
        if (! ($res['ok'] ?? false)) {
            return redirect()->to('vendor/pos/credit-note/' . $id)->with('error', 'Could not generate the PDF.');
        }

        return $this->response->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $res['filename'] . '"')
            ->setBody($res['bytes']);
    }
}
