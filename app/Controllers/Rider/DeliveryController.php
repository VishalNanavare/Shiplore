<?php

declare(strict_types=1);

namespace App\Controllers\Rider;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Rider\DeliveryController — the rider's trips: list, detail, accept an offer,
 * advance status (picked up / out for delivery / delivered / failed), capture
 * proof-of-delivery and collect COD. Every action is rider-scoped + ownership
 * checked inside RiderRepository.
 */
final class DeliveryController extends BaseRiderController
{
    public function index()
    {
        if ($d = $this->requireRider()) {
            return $d;
        }
        $status = (string) $this->request->getGet('status');

        return view('rider/delivery/index', [
            'deliveries' => service('riderRepository')->assignments((int) $this->riderUserId(), $status ?: null),
            'status'     => $status,
        ]);
    }

    /** Phase 5 — optimised multi-stop route for the rider's active trips. */
    public function route()
    {
        if ($d = $this->requireRider()) {
            return $d;
        }
        $uid     = (int) $this->riderUserId();
        $profile = service('riderRepository')->profile($uid);
        $active  = service('riderRepository')->assignments($uid, 'active');

        $stops = [];
        foreach ($active as $a) {
            $stops[] = [
                'delivery_id' => $a['delivery_id'],
                'order_no'    => $a['order_no'],
                'status'      => $a['status'],
                'name'        => $a['drop']['name'] ?? null,
                'address'     => $a['drop']['address'] ?? null,
                'phone'       => $a['drop']['phone'] ?? null,
                'cod_amount'  => $a['cod_amount'],
                'lat'         => isset($a['drop']['lat']) && $a['drop']['lat'] !== null ? (float) $a['drop']['lat'] : null,
                'lng'         => isset($a['drop']['lng']) && $a['drop']['lng'] !== null ? (float) $a['drop']['lng'] : null,
            ];
        }
        $fromLat = $profile && $profile['current_lat'] !== null ? (float) $profile['current_lat'] : null;
        $fromLng = $profile && $profile['current_lng'] !== null ? (float) $profile['current_lng'] : null;
        $route   = service('riderRouteService')->optimize($stops, $fromLat, $fromLng);

        return view('rider/route', [
            'title'    => 'My route',
            'route'    => $route,
            'mapsUrl'  => \App\Libraries\Delivery\RiderRouteService::mapsUrl($route['stops'], $fromLat, $fromLng),
            'hasOrigin' => $fromLat !== null,
        ]);
    }

    public function show(int $id)
    {
        if ($d = $this->requireRider()) {
            return $d;
        }
        $delivery = service('riderRepository')->assignment($id, (int) $this->riderUserId());
        if ($delivery === null) {
            return redirect()->to('rider/deliveries')->with('error', 'Trip not found.');
        }

        return view('rider/delivery/show', ['d' => $delivery]);
    }

    public function accept(int $id): RedirectResponse
    {
        if ($d = $this->requireRider()) {
            return $d;
        }
        $ok = service('riderRepository')->accept($id, (int) $this->riderUserId());

        return redirect()->to('rider/deliveries/' . $id)->with($ok ? 'success' : 'error', $ok ? 'Trip accepted.' : 'Could not accept this trip.');
    }

    public function decline(int $id): RedirectResponse
    {
        if ($d = $this->requireRider()) {
            return $d;
        }
        $reason = trim((string) $this->request->getPost('reason')) ?: null;
        $ok     = service('riderRepository')->decline($id, (int) $this->riderUserId(), $reason);

        return redirect()->to('rider/deliveries')->with($ok ? 'success' : 'error', $ok ? 'Trip declined — re-offered to another rider.' : 'Could not decline this trip.');
    }

    public function status(int $id): RedirectResponse
    {
        if ($d = $this->requireRider()) {
            return $d;
        }
        $to     = (string) $this->request->getPost('status');
        $reason = trim((string) $this->request->getPost('reason')) ?: null;
        $lat    = $this->request->getPost('lat') !== null ? (float) $this->request->getPost('lat') : null;
        $lng    = $this->request->getPost('lng') !== null ? (float) $this->request->getPost('lng') : null;
        if (! in_array($to, ['picked_up', 'out_for_delivery', 'delivered', 'failed', 'returned'], true)) {
            return redirect()->to('rider/deliveries/' . $id)->with('error', 'Invalid status.');
        }
        if ($to === 'failed' && $reason === null) {
            return redirect()->to('rider/deliveries/' . $id)->with('error', 'A reason is required to mark a trip failed.');
        }
        $ok = service('riderRepository')->updateStatus($id, (int) $this->riderUserId(), $to, $reason, $lat, $lng);

        return redirect()->to('rider/deliveries/' . $id)->with($ok ? 'success' : 'error',
            $ok ? 'Trip updated to ' . str_replace('_', ' ', $to) . '.' : 'Could not update this trip.');
    }

    public function pod(int $id): RedirectResponse
    {
        if ($d = $this->requireRider()) {
            return $d;
        }
        $type = (string) $this->request->getPost('pod_type');
        $ref  = trim((string) $this->request->getPost('pod_ref'));
        if (! in_array($type, ['otp', 'photo', 'signature'], true) || $ref === '') {
            return redirect()->to('rider/deliveries/' . $id)->with('error', 'Choose a proof type and enter the reference (OTP / note).');
        }
        $ok = service('riderRepository')->recordPod($id, (int) $this->riderUserId(), $type, $ref);

        return redirect()->to('rider/deliveries/' . $id)->with($ok ? 'success' : 'error', $ok ? 'Delivered — proof recorded.' : 'Could not record proof.');
    }

    public function cod(int $id): RedirectResponse
    {
        if ($d = $this->requireRider()) {
            return $d;
        }
        $ok = service('riderRepository')->recordCod($id, (int) $this->riderUserId());

        return redirect()->to('rider/deliveries/' . $id)->with($ok ? 'success' : 'error', $ok ? 'Cash collected — order marked paid.' : 'Could not record COD.');
    }
}
