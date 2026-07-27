<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Manufacturer\PurchaseOrderController — the seller side of msonline.
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
            'orders'  => service('purchaseOrderRepository')->listForSeller((int) $this->manufacturerId(), $status ?: null),
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
}
