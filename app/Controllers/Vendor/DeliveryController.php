<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Vendor\DeliveryController — delivery operations for the vendor: branch-scoped
 * delivery list with SLA + masked customer contact, manual / auto / re-assign to
 * the vendor's own riders, self-delivery, failure reasons, status history and
 * delivery reports. All scoped to the vendor's shops (tenant + branch isolation).
 *
 * @see docs/architecture/36-DELIVERY-OPERATIONS.md
 */
final class DeliveryController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $repo   = service('vendorDeliveryRepository');
        $vid    = $this->vendorId();
        $status = $this->request->getGet('status');

        return $this->render('vendor/deliveries/index', 'deliveries', 'Deliveries', [
            'deliveries' => $repo->deliveries($vid, is_string($status) ? $status : null, $this->effectiveShopId()),
            'reports'    => $repo->reports($vid),
            'status'     => $status,
            'shopOptions' => $this->shopOptions(),
            'shopId'      => $this->requestedShopId(),
            'filterKeep'  => ['status' => is_string($status) ? $status : null],
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $repo     = service('vendorDeliveryRepository');
        $vid      = $this->vendorId();
        $delivery = $repo->find($id, $vid);
        if ($delivery === null) {
            return redirect()->to('vendor/deliveries')->with('error', 'Delivery not found.');
        }

        return $this->render('vendor/deliveries/show', 'deliveries', 'Delivery ' . $delivery['order_no'], [
            'delivery' => $delivery,
            'riders'   => $repo->riders($vid),
            'history'  => $repo->history($id, $vid),
        ]);
    }

    public function assign(int $id): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $rider = (int) $this->request->getPost('rider_user_id');
        $mode  = $this->request->getPost('mode') === 'self' ? 'self' : 'pool';
        $ok    = $rider > 0 && service('vendorDeliveryRepository')->assign($id, $rider, $this->vendorId(), (int) session()->get('user_id'), $mode);

        return redirect()->to('vendor/deliveries/' . $id)->with($ok ? 'success' : 'error', $ok ? 'Delivery assigned.' : 'Could not assign.');
    }

    public function autoAssign(int $id): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $rider = service('vendorDeliveryRepository')->autoAssign($id, $this->vendorId(), (int) session()->get('user_id'));

        return redirect()->to('vendor/deliveries/' . $id)
            ->with($rider ? 'success' : 'error', $rider ? 'Auto-assigned to rider #' . $rider . '.' : 'No available rider to auto-assign.');
    }

    public function reassign(int $id): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $rider  = (int) $this->request->getPost('rider_user_id');
        $reason = trim((string) $this->request->getPost('reason')) ?: 'manual reassignment';
        $ok     = $rider > 0 && service('vendorDeliveryRepository')->reassign($id, $rider, $this->vendorId(), $reason, (int) session()->get('user_id'));

        return redirect()->to('vendor/deliveries/' . $id)->with($ok ? 'success' : 'error', $ok ? 'Delivery reassigned.' : 'Could not reassign.');
    }

    public function fail(int $id): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $reason = trim((string) $this->request->getPost('reason')) ?: 'unspecified';
        service('vendorDeliveryRepository')->markFailed($id, $this->vendorId(), $reason, (int) session()->get('user_id'));

        return redirect()->to('vendor/deliveries/' . $id)->with('success', 'Marked failed: ' . $reason);
    }
}
