<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\HTTP\RedirectResponse;

/** Vendor\InventoryController — stock + price management for own variants. */
final class InventoryController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        return $this->render('vendor/inventory/index', 'inventory', 'Inventory & Pricing', [
            'rows' => service('vendorInventoryRepository')->list($this->vendorId(), $this->effectiveShopId()),
            'shopOptions' => $this->shopOptions(),
            'shopId'      => $this->requestedShopId(),
        ]);
    }

    public function update(int $inventoryId): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        $repo      = service('vendorInventoryRepository');
        $vid       = $this->vendorId();
        $onHand    = (float) $this->request->getPost('on_hand');
        $variantId = (int) $this->request->getPost('variant_id');
        $base      = (float) $this->request->getPost('base_price');
        $mrp       = (float) $this->request->getPost('mrp');

        $stockOk = $repo->updateStock($inventoryId, $vid, max(0, $onHand));
        if ($variantId > 0) {
            $repo->updatePrice($variantId, $vid, max(0, $base), max(0, $mrp));
        }

        return redirect()->to('vendor/inventory')
            ->with($stockOk ? 'success' : 'error', $stockOk ? 'Inventory updated.' : 'Could not update that item.');
    }
}
