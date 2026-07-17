<?php

declare(strict_types=1);

namespace App\Controllers\Rider;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/** Rider\DashboardController — the rider's home: availability, active trips, earnings. */
final class DashboardController extends BaseRiderController
{
    public function index()
    {
        if ($d = $this->requireRider()) {
            return $d;
        }
        $uid  = (int) $this->riderUserId();
        $repo = service('riderRepository');

        return view('rider/dashboard', [
            'profile' => $repo->profile($uid),
            'active'  => $repo->assignments($uid, 'active'),
            'live'    => $repo->poll($uid), // initial snapshot; the page then polls live
        ]);
    }

    /** Live poll (JSON): fresh offers + countdown, active count, today's stats. */
    public function poll(): ResponseInterface
    {
        if ($this->requireRider()) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }
        // Lazy dispatch, throttled so concurrent polls don't all run the engine.
        try {
            $cache = service('cache');
            if (! $cache->get('rider_dispatch_lock')) {
                $cache->save('rider_dispatch_lock', 1, 8);
                service('riderDispatchService')->run();
            }
        } catch (\Throwable) {
        }
        $data         = service('riderRepository')->poll((int) $this->riderUserId());
        $data['ok']   = true;
        $data['csrf'] = csrf_hash();

        return $this->response->setJSON($data);
    }

    /** Earnings breakdown (today / week / total + recent + cash-in-hand). */
    public function earnings()
    {
        if ($d = $this->requireRider()) {
            return $d;
        }

        return view('rider/earnings', ['title' => 'Earnings', 'earn' => service('riderRepository')->earningsDetail((int) $this->riderUserId())]);
    }

    /** Performance metrics (acceptance / on-time / rating / hours). */
    public function performance()
    {
        if ($d = $this->requireRider()) {
            return $d;
        }

        return view('rider/performance', ['title' => 'Performance', 'perf' => service('riderRepository')->performance((int) $this->riderUserId())]);
    }

    /** Toggle online/offline (available | offline) + open/close the duty shift. */
    public function setAvailability(): RedirectResponse
    {
        if ($d = $this->requireRider()) {
            return $d;
        }
        $uid  = (int) $this->riderUserId();
        $repo = service('riderRepository');
        $to   = $this->request->getPost('availability') === 'available' ? 'available' : 'offline';
        $repo->setAvailability($uid, $to);
        if ($to === 'available') {
            $repo->startShift($uid);
        } else {
            $repo->endShift($uid);
        }

        return redirect()->to('rider/dashboard')->with('success', $to === 'available' ? 'You are online — trips will come to you.' : 'You are offline.');
    }

    /** AJAX GPS ping from the rider's browser. */
    public function location(): ResponseInterface
    {
        if ($this->requireRider()) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }
        $lat = (float) $this->request->getPost('lat');
        $lng = (float) $this->request->getPost('lng');
        if ($lat === 0.0 && $lng === 0.0) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false]);
        }
        service('riderRepository')->updateLocation((int) $this->riderUserId(), $lat, $lng);

        return $this->response->setJSON(['ok' => true, 'csrf' => csrf_hash()]);
    }
}
