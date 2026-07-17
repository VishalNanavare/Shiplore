<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

/** Vendor\SupplierController — supplier master used by the purchase-intake screen. */
final class SupplierController extends BaseVendorController
{
    /** Autocomplete (Ctrl+U) — JSON list of the vendor's suppliers. */
    public function search()
    {
        if ($this->vendorId() === null) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }
        $items = service('supplierRepository')->search($this->vendorId(), (string) $this->request->getGet('q'));

        return $this->response->setJSON(['ok' => true, 'items' => $items]);
    }

    /** Create a supplier from the New-Supplier modal (Ctrl+X). */
    public function store()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $in = $this->request->getJSON(true) ?? $this->request->getPost();
        if (trim((string) ($in['name'] ?? '')) === '') {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'message' => 'Supplier name is required.']);
        }
        $id = service('supplierRepository')->create($this->vendorId(), $in, (int) session()->get('user_id'));
        if ($id === null) {
            return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'message' => 'Could not save the supplier.']);
        }

        return $this->response->setJSON(['ok' => true, 'csrf' => csrf_hash(), 'supplier' => service('supplierRepository')->find($id, $this->vendorId())]);
    }
}
