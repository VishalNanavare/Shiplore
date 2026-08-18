<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Manufacturer\InventoryController — stock held at manufacturing units.
 *
 * Two screens, mirroring the vendor panel's split: a whole-tenant grid, and a
 * per-product view with the movements that produced each balance.
 *
 * The vendor equivalent receives stock (goods bought in); a manufacturer PRODUCES it,
 * which is why the write action is "record production" and posts a `production`
 * movement. mfg_inventory_ledger.movement_type has no 'purchase' member — the schema
 * already encoded that distinction, it just had no code behind it.
 *
 * @see \App\Controllers\Vendor\InventoryController
 * @see \App\Controllers\Vendor\ProductInventoryController
 */
final class InventoryController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.inventory.view')) {
            return $denied;
        }

        // Scoped to the units this user may act on, so a store keeper sees only their
        // own unit's stock, not the whole business's.
        $unit  = $this->effectiveMshopId();
        $units = $unit !== null && $unit > 0 ? [$unit] : $this->allowedMshopIds();

        return $this->render('manufacturer/inventory/index', 'inventory', 'Stock', [
            'rows'        => service('manufacturerInventoryService')
                ->levelsForUnits((int) $this->manufacturerId(), $units),
            'unitOptions' => $this->mshopOptions(),
            'activeUnit'  => $unit !== null && $unit > 0 ? $unit : null,
            'canAdjust'   => $this->can('mfg.inventory.adjust'),
        ]);
    }

    /** Per-product stock across the user's units, with each variant's movements. */
    public function product(int $productId)
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.inventory.view')) {
            return $denied;
        }

        $product = service('manufacturerProductRepository')->findById($productId, (int) $this->manufacturerId());
        if ($product === null) {
            return redirect()->to('manufacturer/products')->with('error', 'Product not found.');
        }

        $svc = service('manufacturerInventoryService');

        // A unit carried from the stock grid wins, so "Manage" on Plant B's row opens
        // Plant B. Intersected with allowedMshopIds() so a hand-edited query string
        // cannot reach another plant. Falling back to the first allowed unit is the last
        // resort, and it is why this page must show which unit it is writing to.
        $requested = (int) ($this->request->getGet('mshop_id') ?? 0);
        $allowed   = $this->allowedMshopIds();
        $unit      = $this->effectiveMshopId();
        $unitId    = in_array($requested, $allowed, true)
            ? $requested
            : ($unit !== null && $unit > 0 ? $unit : ($allowed[0] ?? 0));
        $variants = service('productVariantRepository')->listWithValues($productId);

        $levels = $ledger = [];
        foreach ($variants as $v) {
            $vid          = (int) $v['id'];
            $levels[$vid] = $svc->levels($vid, $unitId);
            $ledger[$vid] = $svc->ledger($vid, $unitId, 10);
        }

        return $this->render('manufacturer/inventory/product', 'inventory', 'Stock — ' . ($product['title'] ?? ''), [
            'product'     => $product,
            'variants'    => $variants,
            'levels'      => $levels,
            'ledger'      => $ledger,
            'unitId'      => $unitId,
            'unitOptions' => $this->mshopOptions(),
            'canAdjust'   => $this->can('mfg.inventory.adjust'),
        ]);
    }

    /** Record production into a unit. */
    public function produce(int $productId): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guardAdjust($productId)) {
            return $denied;
        }

        [$variantId, $mshopId, $err] = $this->target();
        if ($err !== null) {
            return redirect()->back()->with('error', $err);
        }

        $qty = (float) $this->request->getPost('qty');
        if ($qty <= 0) {
            return redirect()->back()->with('error', 'Enter a quantity greater than zero.');
        }

        $ok = service('manufacturerInventoryService')->produce(
            $variantId,
            $mshopId,
            $qty,
            (float) $this->request->getPost('making_cost'),
            [
                'batch_no' => trim((string) $this->request->getPost('batch_no')),
                'mfg_date' => trim((string) $this->request->getPost('mfg_date')),
                'exp_date' => trim((string) $this->request->getPost('exp_date')),
            ],
            (int) session()->get('user_id'),
        );

        return redirect()->to('manufacturer/products/' . $productId . '/stock')
            ->with($ok ? 'success' : 'error', $ok ? 'Production recorded.' : 'Could not record production.');
    }

    /** Correct a balance up or down with a reason. */
    public function adjust(int $productId): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guardAdjust($productId)) {
            return $denied;
        }

        [$variantId, $mshopId, $err] = $this->target();
        if ($err !== null) {
            return redirect()->back()->with('error', $err);
        }

        $delta = (float) $this->request->getPost('qty_delta');
        if ($delta == 0.0) {
            return redirect()->back()->with('error', 'Enter a non-zero adjustment.');
        }

        $ok = service('manufacturerInventoryService')->adjust(
            $variantId,
            $mshopId,
            $delta,
            (string) $this->request->getPost('reason'),
            trim((string) $this->request->getPost('notes')),
            (int) session()->get('user_id'),
        );

        return redirect()->to('manufacturer/products/' . $productId . '/stock')
            ->with($ok ? 'success' : 'error', $ok ? 'Stock adjusted.' : 'Could not adjust stock.');
    }

    // ---- internals ---------------------------------------------------------

    /** Permission + tenant ownership of the product being written against. */
    private function guardAdjust(int $productId): ?RedirectResponse
    {
        if ($denied = $this->guard('mfg.inventory.adjust')) {
            return $denied;
        }
        if (service('manufacturerProductRepository')->findById($productId, (int) $this->manufacturerId()) === null) {
            return redirect()->to('manufacturer/products')->with('error', 'Product not found.');
        }

        return null;
    }

    /**
     * The variant and unit a stock write applies to.
     *
     * The unit is intersected with allowedMshopIds() rather than trusted, so a posted
     * id belonging to another manufacturer — or to a unit this staff member is not
     * assigned to — is refused rather than written.
     *
     * @return array{0:int,1:int,2:?string}
     */
    private function target(): array
    {
        $variantId = (int) $this->request->getPost('variant_id');
        $mshopId   = (int) $this->request->getPost('mshop_id');

        if ($variantId <= 0) {
            return [0, 0, 'Pick a variant.'];
        }
        if (! in_array($mshopId, $this->allowedMshopIds(), true)) {
            return [0, 0, 'Unit not found.'];
        }

        return [$variantId, $mshopId, null];
    }
}
