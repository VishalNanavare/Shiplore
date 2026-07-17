<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Vendor\PurchaseController — the keyboard-first "Add Inventory" (purchase intake)
 * screen. Header + supplier + multi-line grid; posting raises stock via
 * PurchaseInvoiceService (Appendix A math).
 */
final class PurchaseController extends BaseVendorController
{
    public function add(): string|RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        return $this->render('vendor/purchase/add', 'inventory', 'Add Inventory', [
            'ourInvoiceNo' => 'INV-' . date('YmdHis'),
            'invoiceDate'  => date('d-m-Y'),
            'shopOptions'  => $this->shopOptions(),
            'shopId'       => $this->effectiveShopId(),
        ]);
    }

    /** Purchase-line product search (JSON) — prefills pricing + HSN GST. */
    public function searchProducts()
    {
        if ($this->vendorId() === null) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }
        $items = service('purchaseInvoiceRepository')->searchVariants($this->vendorId(), (string) $this->request->getGet('q'));

        return $this->response->setJSON(['ok' => true, 'items' => $items]);
    }

    /** Save the purchase (Ctrl+S). Body: {header:{...}, lines:[...]}. */
    public function store()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $in     = $this->request->getJSON(true) ?? [];
        $header = (array) ($in['header'] ?? []);
        $lines  = (array) ($in['lines'] ?? []);
        $shopId = $this->effectiveShopId() ?? ($this->allowedShopIds()[0] ?? null);
        if ($shopId === null) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'message' => 'No shop available to receive stock.']);
        }

        $res = service('purchaseInvoiceService')->post($this->vendorId(), (int) $shopId, $header, $lines, (int) session()->get('user_id'));
        $res['csrf'] = csrf_hash();

        return $this->response->setStatusCode($res['ok'] ? 200 : 422)->setJSON($res);
    }

    public function history(): string|RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        return $this->render('vendor/purchase/history', 'inventory', 'Purchase History', [
            'rows' => service('purchaseInvoiceRepository')->list($this->vendorId()),
        ]);
    }
}
