<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Manufacturer\PosController — the factory-outlet counter.
 *
 * A manufacturer sells mainly B2B through monline purchase orders; this is the other
 * channel: someone walks up to the plant and buys. It runs entirely on mfg_pos_*
 * tables and mfg_inventory, and touches nothing the shipped mobile/Windows POS clients
 * read — see ManufacturerPosSaleRepository's docblock.
 *
 * Deliberately NOT ported from the vendor POS in v1, each for a reason:
 *   - khata / customer credit — needs `customers`, and whether a factory outlet sells
 *     on credit at all is an unmade business decision;
 *   - returns and credit notes — mutually dependent and need their own numbering
 *     series, so shipping one without the other strands it;
 *   - shift open/close — the schema is there, but a single-counter outlet has no
 *     cash-drawer reconciliation to do yet;
 *   - POS settings — ShopBillSettingsRepository::forShop() is keyed on shops.id, so
 *     passing an mshop id would return ANOTHER tenant's bill settings onto the
 *     receipt. Static defaults until a manufacturer equivalent exists.
 *
 * @see \App\Controllers\Vendor\PosController — the vendor counterpart
 */
final class PosController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.pos.view')) {
            return $denied;
        }

        $unitId = $this->outletUnitId();
        if ($unitId <= 0) {
            return redirect()->to('manufacturer/units')
                ->with('error', 'Pick a unit to sell from — the counter is per unit.');
        }

        return $this->render('manufacturer/pos/index', 'pos', 'Counter', [
            'unitId'      => $unitId,
            'unitOptions' => $this->mshopOptions(),
            'canSell'     => $this->can('mfg.pos.sell'),
            'recent'      => service('manufacturerPosSaleRepository')
                ->recent((int) $this->manufacturerId(), [$unitId], 10),
        ]);
    }

    /** Counter search (JSON). */
    public function search(): ResponseInterface
    {
        if ($this->requireManufacturer() || ! $this->can('mfg.pos.sell')) {
            return $this->deny();
        }

        $unitId = $this->outletUnitId();
        if ($unitId <= 0) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'message' => 'No unit selected.']);
        }

        return $this->response->setJSON([
            'ok'   => true,
            'rows' => service('manufacturerPosSaleRepository')->search(
                (int) $this->manufacturerId(),
                $unitId,
                (string) $this->request->getGet('q'),
            ),
        ]);
    }

    /** Ring up a sale (JSON). */
    public function sale(): ResponseInterface
    {
        if ($this->requireManufacturer() || ! $this->can('mfg.pos.sell')) {
            return $this->deny();
        }

        $unitId = $this->outletUnitId();
        if ($unitId <= 0) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'No unit selected.', 'csrf' => csrf_hash()]);
        }

        $repo = service('manufacturerPosSaleRepository');
        $mid  = (int) $this->manufacturerId();

        // Cart carries variant ids and quantities only — every price, tax rate and
        // title is re-read from the database, so a tampered cart cannot set a price.
        $lines = $repo->resolveLines($mid, (array) ($this->request->getPost('items') ?? []), $unitId);
        if ($lines === []) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'Cart is empty.', 'csrf' => csrf_hash()]);
        }

        $res = $repo->createSale(
            ['mshop_id' => $unitId, 'vendor_id' => $mid, 'cashier_user_id' => (int) session()->get('user_id')],
            $lines,
            (array) ($this->request->getPost('payments') ?? []),
            [
                'client_uuid'    => (string) $this->request->getPost('client_uuid'),
                'bill_discount'  => (float) $this->request->getPost('bill_discount'),
                'notes'          => trim((string) $this->request->getPost('notes')),
                'customer_name'  => trim((string) $this->request->getPost('customer_name')),
                'customer_phone' => trim((string) $this->request->getPost('customer_phone')),
            ],
        );

        $res['csrf'] = csrf_hash();

        return $this->response->setStatusCode(($res['ok'] ?? false) ? 200 : 422)->setJSON($res);
    }

    public function receipt(int $saleId)
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.pos.view')) {
            return $denied;
        }

        $sale = service('manufacturerPosSaleRepository')->findForReceipt($saleId, (int) $this->manufacturerId());
        if ($sale === null) {
            return redirect()->to('manufacturer/pos')->with('error', 'Receipt not found.');
        }

        // Standalone 80mm page — deliberately NOT extending the panel layout.
        return view('manufacturer/pos/receipt', ['sale' => $sale]);
    }

    // ---- internals ---------------------------------------------------------

    /**
     * The unit this counter is selling from.
     *
     * Unit staff are pinned to theirs; an owner spanning units must choose one, since
     * an invoice series and a stock balance both belong to a single unit. Always
     * intersected with allowedMshopIds(), so a posted id cannot reach another plant.
     */
    private function outletUnitId(): int
    {
        $requested = (int) ($this->request->getGet('mshop_id') ?: $this->request->getPost('mshop_id'));
        if ($requested > 0 && in_array($requested, $this->allowedMshopIds(), true)) {
            return $requested;
        }

        $effective = $this->effectiveMshopId();
        if ($effective !== null && $effective > 0) {
            return $effective;
        }

        // An owner with exactly one unit needs no picker.
        $allowed = $this->allowedMshopIds();

        return count($allowed) === 1 ? (int) $allowed[0] : 0;
    }

    private function deny(): ResponseInterface
    {
        return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'message' => 'Not allowed.']);
    }
}
