<?php

declare(strict_types=1);

namespace App\Controllers\Rider;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * BaseRiderController — shared base for the standalone RIDER web panel. Rider
 * identity is a separate session namespace (`rider_id`) so it never collides with
 * customer/store, vendor or admin sessions. All rider data flows through
 * RiderRepository, scoped to the logged-in rider's user id.
 */
abstract class BaseRiderController extends BaseController
{
    protected function riderUserId(): ?int
    {
        $id = session()->get('rider_id');

        return $id ? (int) $id : null;
    }

    protected function requireRider(): ?RedirectResponse
    {
        if ($this->riderUserId() === null) {
            return redirect()->to('rider/login')->with('error', 'Please sign in to continue.');
        }

        return null;
    }
}
