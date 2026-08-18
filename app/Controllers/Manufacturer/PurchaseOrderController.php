<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Manufacturer\PurchaseOrderController — the seller side of monline.
 *
 * Incoming purchase orders from vendors and shops: accept or reject, then pack and
 * dispatch. The buyer confirms receipt on their side, which is what raises their stock —
 * a manufacturer cannot mark their own delivery as received.
 *
 * Every transition goes through PurchaseOrderRepository, which validates the move
 * against StatusMachine and scopes the PO to this manufacturer.
 */
final class PurchaseOrderController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.po.view')) {
            return $denied;
        }

        $status = trim((string) $this->request->getGet('status'));

        return $this->render('manufacturer/orders/index', 'orders', 'Purchase Orders', [
            // Owners span every unit and pass null; unit staff get their own units only.
            'orders'  => service('purchaseOrderRepository')->listForSeller(
                (int) $this->manufacturerId(),
                $status ?: null,
                $this->isOwner() ? null : $this->allowedMshopIds(),
            ),
            'filters' => ['status' => $status],
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.po.view')) {
            return $denied;
        }

        $found = service('purchaseOrderRepository')->findFor($id, (int) $this->manufacturerId(), 'seller');
        if ($found === null) {
            return redirect()->to('manufacturer/purchase-orders')->with('error', 'Purchase order not found.');
        }
        if ($denied = $this->requireUnitOnPo($found['po'])) {
            return $denied;
        }

        return $this->render('manufacturer/orders/show', 'orders', (string) $found['po']['po_no'], [
            'po'    => $found['po'],
            'items' => $found['items'],
            'units' => $this->mshopOptions(),
        ]);
    }

    /** accept · reject · pack · dispatch — one entry point, validated by the state machine. */
    public function transition(int $id, string $action): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.po.manage')) {
            return $denied;
        }

        $map = [
            'accept'   => 'accepted',
            'reject'   => 'rejected',
            'pack'     => 'packed',
            'dispatch' => 'dispatched',
        ];
        if (! isset($map[$action])) {
            return redirect()->to('manufacturer/purchase-orders/' . $id)->with('error', 'Unknown action.');
        }

        // The unit already assigned to this PO gates EVERY action, not just accept.
        // Once accepted against unit B the order carries seller_mshop_id, and dispatch
        // calls shipStockForPo(), which decrements THAT unit's mfg_inventory — so
        // without this a store keeper on unit A could ship unit B's stock.
        $found = service('purchaseOrderRepository')->findFor($id, (int) $this->manufacturerId(), 'seller');
        if ($found === null) {
            return redirect()->to('manufacturer/purchase-orders')->with('error', 'Purchase order not found.');
        }
        if ($denied = $this->requireUnitOnPo($found['po'])) {
            return $denied;
        }

        $extra = [];
        if ($action === 'accept') {
            // The fulfilling unit is chosen on accept. It must be one this user may act
            // on, so a store keeper cannot commit another unit's stock.
            $unit = (int) $this->request->getPost('seller_mshop_id');
            if ($unit > 0) {
                if ($denied = $this->requireMshopAccess($unit)) {
                    return $denied;
                }
                $extra['seller_mshop_id'] = $unit;
            }
        }
        if ($action === 'reject') {
            $reason = trim((string) $this->request->getPost('reject_reason'));
            $extra['reject_reason'] = $reason !== '' ? mb_substr($reason, 0, 255) : null;
        }

        $res = service('purchaseOrderRepository')->transition(
            $id,
            (int) $this->manufacturerId(),
            'seller',
            $map[$action],
            (int) session()->get('user_id'),
            $extra,
        );

        return $res['ok']
            ? redirect()->to('manufacturer/purchase-orders/' . $id)->with('success', 'Order marked ' . $map[$action] . '.')
            : redirect()->to('manufacturer/purchase-orders/' . $id)->with('error', $res['error']);
    }

    /**
     * Refuse a purchase order that belongs to a unit this user may not act on.
     *
     * Reads the unit ALREADY STORED on the row, which is what the accept-branch check
     * cannot cover: that one validates the unit being chosen, and every later transition
     * re-reads the stored one.
     *
     * An unassigned PO passes deliberately. A pending order has no seller_mshop_id yet
     * and every unit of this manufacturer is still a legitimate candidate — refusing here
     * would make it impossible to accept anything. requireMshopAccess() then does the
     * real work on the unit that gets chosen. Owners span all units and pass either way.
     *
     * @param array<string,mixed> $po
     */
    private function requireUnitOnPo(array $po): ?RedirectResponse
    {
        $unit = (int) ($po['seller_mshop_id'] ?? 0);
        if ($unit <= 0) {
            return null;
        }

        return $this->requireMshopAccess($unit);
    }
}
