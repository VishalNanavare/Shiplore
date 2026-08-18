<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Manufacturer\TransferController — stock moving between a manufacturer's own units.
 *
 * mfg.transfer.view and mfg.transfer.manage were seeded by 76_manufacturer_parity.sql
 * with no screen behind them for two phases. This is the screen.
 *
 * Two steps, mirroring the repository: dispatch decrements the source, receipt credits
 * the destination. Goods between the two are in transit and belong to neither, which is
 * why a single "move" button would be wrong.
 *
 * @see \App\Controllers\Vendor\TransferController the vendor counterpart
 */
final class TransferController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.transfer.view')) {
            return $denied;
        }

        $status = trim((string) $this->request->getGet('status'));

        return $this->render('manufacturer/transfers/index', 'transfers', 'Stock Transfers', [
            'transfers' => service('manufacturerTransferRepository')
                ->list((int) $this->manufacturerId(), $status ?: null),
            'units'   => $this->mshopOptions(),
            'filters' => ['status' => $status],
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.transfer.view')) {
            return $denied;
        }

        $t = service('manufacturerTransferRepository')->find($id, (int) $this->manufacturerId());
        if ($t === null) {
            return redirect()->to('manufacturer/transfers')->with('error', 'Transfer not found.');
        }

        return $this->render('manufacturer/transfers/show', 'transfers', (string) $t['transfer_no'], ['t' => $t]);
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.transfer.manage')) {
            return $denied;
        }

        // Both ends are re-checked against allowedMshopIds() as well as against the
        // manufacturer, so unit staff cannot move another plant's stock even though the
        // repository's own tenant check would already have passed.
        $from = (int) $this->request->getPost('from_mshop_id');
        $to   = (int) $this->request->getPost('to_mshop_id');

        foreach ([$from, $to] as $unit) {
            if ($denied = $this->requireMshopAccess($unit)) {
                return $denied;
            }
        }

        $res = service('manufacturerTransferRepository')->create(
            (int) $this->manufacturerId(),
            $from,
            $to,
            (array) ($this->request->getPost('items') ?? []),
            trim((string) $this->request->getPost('notes')),
            (int) session()->get('user_id'),
        );

        return $res['ok']
            ? redirect()->to('manufacturer/transfers/' . (int) $res['id'])->with('success', 'Transfer ' . $res['transfer_no'] . ' created.')
            : redirect()->back()->withInput()->with('error', $res['error']);
    }

    /** dispatch · receive — the two state changes, each guarded by the source/destination. */
    public function transition(int $id, string $action): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.transfer.manage')) {
            return $denied;
        }

        $repo = service('manufacturerTransferRepository');
        $mid  = (int) $this->manufacturerId();
        $t    = $repo->find($id, $mid);
        if ($t === null) {
            return redirect()->to('manufacturer/transfers')->with('error', 'Transfer not found.');
        }

        // Dispatch is the SOURCE unit's action, receipt the DESTINATION's. Guarding on
        // the wrong end would let a warehouse ship a plant's stock.
        $unit = $action === 'dispatch' ? (int) $t['from_mshop_id'] : (int) $t['to_mshop_id'];
        if ($denied = $this->requireMshopAccess($unit)) {
            return $denied;
        }

        $uid = (int) session()->get('user_id');

        $res = match ($action) {
            'dispatch' => $repo->dispatch($id, $mid, $uid),
            'receive'  => $repo->receive($id, $mid, $this->receivedQuantities(), $uid),
            default    => ['ok' => false, 'error' => 'Unknown action.'],
        };

        return redirect()->to('manufacturer/transfers/' . $id)
            ->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Transfer ' . $action . 'ed.' : $res['error']);
    }

    /**
     * variant_id => qty actually received.
     *
     * A blank field means "all of it" rather than zero, so a receiver who only corrects
     * the one short line does not accidentally zero every other one.
     *
     * @return array<int,float>
     */
    private function receivedQuantities(): array
    {
        $out = [];

        foreach ((array) ($this->request->getPost('received') ?? []) as $vid => $qty) {
            if (trim((string) $qty) !== '') {
                $out[(int) $vid] = (float) $qty;
            }
        }

        return $out;
    }
}
