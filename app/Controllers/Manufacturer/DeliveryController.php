<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Manufacturer\DeliveryController — moving dispatched purchase orders to their buyers,
 * and the manufacturer's own rider roster.
 *
 * Deliveries are created by the PO flow (a record appears when an order is
 * dispatched), so there is no "create delivery" action here — only assigning a rider
 * and moving the delivery along. That mirrors the vendor panel, where a delivery
 * likewise comes into existence from an order rather than being raised by hand.
 *
 * @see \App\Controllers\Vendor\DeliveryController — the vendor counterpart
 */
final class DeliveryController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.delivery.assign')) {
            return $denied;
        }

        $repo   = service('manufacturerDeliveryRepository');
        $unit   = $this->effectiveMshopId();
        $units  = $unit !== null && $unit > 0 ? [$unit] : $this->allowedMshopIds();
        $status = trim((string) $this->request->getGet('status'));

        return $this->render('manufacturer/deliveries/index', 'deliveries', 'Deliveries', [
            'deliveries' => $repo->list((int) $this->manufacturerId(), $units, $status !== '' ? $status : null),
            'riders'     => $repo->riders((int) $this->manufacturerId()),
            'filters'    => ['status' => $status],
        ]);
    }

    public function assign(int $deliveryId): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.delivery.assign')) {
            return $denied;
        }

        $res = service('manufacturerDeliveryRepository')->assignRider(
            $deliveryId,
            (int) $this->manufacturerId(),
            (int) $this->request->getPost('rider_user_id'),
            (int) session()->get('user_id'),
        );

        return redirect()->to('manufacturer/deliveries')
            ->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Rider assigned.' : $res['error']);
    }

    /** Move a delivery along: picked_up / in_transit / delivered / failed / returned. */
    public function transition(int $deliveryId, string $to): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.delivery.assign')) {
            return $denied;
        }

        $res = service('manufacturerDeliveryRepository')->transition(
            $deliveryId,
            (int) $this->manufacturerId(),
            $to,
            (string) $this->request->getPost('reason'),
            (int) session()->get('user_id'),
        );

        return redirect()->to('manufacturer/deliveries')
            ->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Delivery updated.' : $res['error']);
    }

    // ---- riders ------------------------------------------------------------

    public function riders()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.rider.manage')) {
            return $denied;
        }

        return $this->render('manufacturer/deliveries/riders', 'riders', 'Riders', [
            'riders' => service('manufacturerDeliveryRepository')->riders((int) $this->manufacturerId()),
        ]);
    }

    /**
     * Create a rider login.
     *
     * Gated on mfg.rider.manage AND ownership, for the same reason the vendor panel
     * gates its own: this mints a login with a caller-chosen password, bound to the
     * business's fleet. That is the same class of action as hiring staff, so it takes
     * a comparable gate rather than the bare tenant check.
     */
    public function addRider(): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.rider.manage')) {
            return $denied;
        }
        if ($denied = $this->requireOwner('manufacturer/riders', 'Only the owner can add riders.')) {
            return $denied;
        }

        $name  = trim((string) $this->request->getPost('name'));
        $phone = trim((string) $this->request->getPost('phone'));

        if ($name === '' || ! preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
            return redirect()->to('manufacturer/riders')->with('error', 'Rider name and a valid phone (10-15 digits) are required.');
        }

        $repo = service('manufacturerDeliveryRepository');
        if ($repo->phoneExists($phone)) {
            return redirect()->to('manufacturer/riders')->with('error', 'That phone number is already registered.');
        }

        $id = $repo->addRider((int) $this->manufacturerId(), [
            'name'              => $name,
            'phone'             => $phone,
            'email'             => trim((string) $this->request->getPost('email')),
            'password'          => (string) $this->request->getPost('password'),
            'vehicle_type'      => (string) $this->request->getPost('vehicle_type'),
            'vehicle_no'        => trim((string) $this->request->getPost('vehicle_no')),
            'max_active_orders' => (int) $this->request->getPost('max_active_orders'),
        ], (int) session()->get('user_id'));

        return redirect()->to('manufacturer/riders')
            ->with($id ? 'success' : 'error', $id ? 'Rider added.' : 'Could not add the rider.');
    }
}
