<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * RiderAuthFilter — session guard for the rider web panel. Rider sessions use
 * `rider_id` (separate namespace from the staff `user_id`) to avoid collisions.
 *
 * @see BaseRiderController::requireRider()
 */
final class RiderAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $riderId = session()->get('rider_id');
        if (! $riderId) {
            return redirect()->to('rider/login')->with('error', 'Please sign in to continue.');
        }

        // Vendor status lifecycle, phase 6 — and a pre-existing gap fixed alongside it:
        // this filter never re-checked ANYTHING about the rider's account per request,
        // unlike WebAuthFilter's equivalent staff re-check (apiAuthRepository->isActive()
        // on every page load). A suspended rider's WEB session simply kept working for
        // the session's lifetime. Both checks are added together here because they
        // share the one query and were introduced in the same build; both stay
        // log-only until the operator opts in — see VendorStatusGate.
        //
        // Fail OPEN on an indeterminate answer (a DB fault), closed only on a
        // DEFINITIVE not-active — same contract as WebAuthFilter's own re-check: this
        // runs on every rider page load, so an uncaught fault here would mass-log-out
        // every active rider mid-delivery, a worse outage than the risk being closed.
        try {
            $profile = service('riderRepository')->profile((int) $riderId);
        } catch (\Throwable $e) {
            log_message('critical', 'RiderAuthFilter: status re-check unavailable (suspended riders retain web sessions): ' . $e->getMessage());
            $profile = null;
        }

        if ($profile === null) {
            return null; // nothing to check — fail open, same reasoning as the DB-fault case
        }

        $gate      = service('vendorStatusGate');
        $enforcing = $gate->isEnforcing();
        $riderOk   = ($profile['status'] ?? '') === 'active';

        if (! $riderOk) {
            log_message(
                $enforcing ? 'warning' : 'notice',
                sprintf(
                    'vendor-status gate [%s]: rider #%d own status="%s" is not active',
                    $enforcing ? 'BLOCKED' : 'would block',
                    $riderId,
                    (string) ($profile['status'] ?? '(none)'),
                ),
            );
        }

        $vendor        = ['id' => $profile['vendor_id'] ?? null, 'status' => $profile['vendor_status'] ?? null];
        $vendorBlocked = $gate->shouldBlockForVendorStatus($vendor, 'Rider panel login for rider #' . $riderId);

        if (($enforcing && ! $riderOk) || $vendorBlocked) {
            session()->destroy();

            return redirect()->to('rider/login')->with('error', 'Your account is no longer active. Please contact your administrator.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
