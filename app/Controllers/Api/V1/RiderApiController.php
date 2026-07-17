<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;

/**
 * RiderApiController — Delivery Boy app API. JWT-authenticated; every call is
 * scoped to the logged-in rider (a rider only sees/acts on their own
 * assignments).
 *
 * Live dispatch parity with the rider web app (app/Controllers/Rider): riders
 * `poll` for time-boxed offers (each with the seconds left to accept), then
 * accept/decline; going `available` opens a duty shift. Covers active trips
 * (pickup + drop + COD), navigation, status updates, proof-of-delivery, COD
 * collection, earnings breakdown, performance, cash-in-hand and notifications.
 *
 * @see docs/architecture/28-DELIVERY-APP.md, app/Controllers/Rider/DashboardController.php
 */
final class RiderApiController extends BaseApiController
{
    public function me()
    {
        $rider = service('riderRepository')->profile($this->userId());

        return $rider === null ? $this->notRider() : $this->ok($rider);
    }

    /**
     * Live snapshot the app polls every few seconds: online state, fresh offers
     * (each with `seconds` left to accept), active-trip count and today's stats.
     */
    public function poll()
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }
        // Lazily flow offers while riders are online, throttled with a short cache
        // lock so concurrent polls don't all run the dispatch engine (the cron also runs).
        try {
            $cache = service('cache');
            if (! $cache->get('rider_dispatch_lock')) {
                $cache->save('rider_dispatch_lock', 1, 8);
                service('riderDispatchService')->run();
            }
        } catch (\Throwable) {
        }

        return $this->ok(service('riderRepository')->poll($this->userId()));
    }

    public function availability()
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }
        $status = (string) ($this->input()['status'] ?? '');
        if (! in_array($status, ['available', 'busy', 'offline'], true)) {
            return $this->failWith('VALIDATION_ERROR', 'status must be available, busy or offline.');
        }
        $repo = service('riderRepository');
        $repo->setAvailability($this->userId(), $status);
        // Going online opens a duty shift; going offline closes it (busy stays on duty).
        if ($status === 'available') {
            $repo->startShift($this->userId());
        } elseif ($status === 'offline') {
            $repo->endShift($this->userId());
        }

        return $this->ok([
            'availability'        => $status,
            'shift_minutes_today' => $repo->shiftMinutesToday($this->userId()),
        ]);
    }

    public function location()
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }
        $in  = $this->input();
        $lat = isset($in['lat']) ? (float) $in['lat'] : null;
        $lng = isset($in['lng']) ? (float) $in['lng'] : null;
        // Reject missing or null-island (0,0) coords + out-of-range — they would corrupt
        // the rider's dispatch position (parity with the web GPS ping).
        if ($lat === null || $lng === null || ($lat === 0.0 && $lng === 0.0) || abs($lat) > 90 || abs($lng) > 180) {
            return $this->failWith('VALIDATION_ERROR', 'A valid lat/lng is required.');
        }
        service('riderRepository')->updateLocation($this->userId(), $lat, $lng);

        return $this->ok(['recorded' => true]);
    }

    public function orders()
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }
        $status = $this->request->getGet('status');
        $items  = service('riderRepository')->assignments($this->userId(), is_string($status) ? $status : null);

        return $this->collection($items, ['total' => count($items)]);
    }

    public function order(int $id)
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }
        $row = service('riderRepository')->assignment($id, $this->userId());

        return $row === null ? $this->failWith('NOT_FOUND', 'Delivery not assigned to you.') : $this->ok($row);
    }

    /**
     * Accept a live offer. Distinguishes "never offered to you" (404) from "the
     * offer lapsed or was taken by another rider" (409) so the app can react.
     */
    public function accept(int $id)
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }
        $repo = service('riderRepository');
        if ($repo->accept($id, $this->userId())) {
            return $this->ok(['message' => 'Delivery accepted.', 'delivery_id' => $id]);
        }
        if (! $repo->owns($id, $this->userId())) {
            return $this->failWith('NOT_FOUND', 'This delivery was not offered to you.');
        }

        return $this->failWith('CONFLICT', 'This offer is no longer available — it expired or was taken by another rider.');
    }

    /** Decline a live offer → it re-offers to the next-nearest rider. */
    public function decline(int $id)
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }
        $reason = isset($this->input()['reason']) ? (string) $this->input()['reason'] : null;
        $ok     = service('riderRepository')->decline($id, $this->userId(), $reason);

        return $ok ? $this->ok(['message' => 'Trip declined.']) : $this->failWith('CONFLICT', 'No live offer to decline.');
    }

    public function status(int $id)
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }
        $in     = $this->input();
        $status = (string) ($in['status'] ?? '');
        // 'returned' is a valid delivery state (parity with the rider web app + schema).
        if (! in_array($status, ['picked_up', 'arrived', 'out_for_delivery', 'delivered', 'failed', 'returned'], true)) {
            return $this->failWith('VALIDATION_ERROR', 'Invalid delivery status.');
        }
        if (in_array($status, ['failed', 'returned'], true) && trim((string) ($in['reason'] ?? '')) === '') {
            return $this->failWith('VALIDATION_ERROR', 'A reason is required to mark a trip ' . $status . '.');
        }
        $reason = isset($in['reason']) ? (string) $in['reason'] : null;
        $lat    = isset($in['lat']) ? (float) $in['lat'] : null;
        $lng    = isset($in['lng']) ? (float) $in['lng'] : null;

        return $this->mutate(static fn ($r, $u) => $r->updateStatus($id, $u, $status, $reason, $lat, $lng), 'Status updated.');
    }

    public function pod(int $id)
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }
        $in   = $this->input();
        $type = (string) ($in['type'] ?? '');
        $ref  = trim((string) ($in['ref'] ?? ''));
        if (! in_array($type, ['otp', 'photo', 'signature'], true)) {
            return $this->failWith('VALIDATION_ERROR', 'pod type must be otp, photo or signature.');
        }
        if ($ref === '') {
            return $this->failWith('VALIDATION_ERROR', 'A proof reference is required (OTP code / photo / signature ref).');
        }
        // Distinguish "not your delivery" (404) from "OTP didn't match" (409) — recordPod
        // returns false for both, which previously surfaced a misleading 404.
        $repo = service('riderRepository');
        if (! $repo->ownsAcceptedDelivery($id, $this->userId())) {
            return $this->failWith('NOT_FOUND', 'Delivery not assigned to you.');
        }
        if (! $repo->recordPod($id, $this->userId(), $type, $ref)) {
            return $this->failWith('CONFLICT', 'Proof could not be recorded — the delivery OTP did not match.');
        }

        return $this->ok(['message' => 'Proof of delivery recorded; order delivered.']);
    }

    public function cod(int $id)
    {
        return $this->mutate(static fn ($r, $u) => $r->recordCod($id, $u), 'COD collected and recorded.');
    }

    /** Earnings breakdown: today/week/total + cash-in-hand + recent payout entries. */
    public function earnings()
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }
        $repo    = service('riderRepository');
        $summary = $repo->earnings($this->userId());
        if (($summary['enabled'] ?? true) === false) {
            return $this->ok(['enabled' => false]);
        }

        return $this->ok(['enabled' => true] + $repo->earningsDetail($this->userId()));
    }

    /** Performance metrics: acceptance, on-time, rating, completed, hours. */
    public function performance()
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }

        return $this->ok(service('riderRepository')->performance($this->userId()));
    }

    /** COD cash the rider is currently holding (collected, not yet deposited). */
    public function cash()
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }

        return $this->ok([
            'cash_in_hand' => number_format(service('riderRepository')->cashInHand($this->userId()), 2, '.', ''),
            'currency'     => 'INR',
        ]);
    }

    public function shiftStart()
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }
        service('riderRepository')->startShift($this->userId());

        return $this->ok(['on_duty' => true, 'minutes_today' => service('riderRepository')->shiftMinutesToday($this->userId())]);
    }

    public function shiftEnd()
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }
        service('riderRepository')->endShift($this->userId());

        return $this->ok(['on_duty' => false, 'minutes_today' => service('riderRepository')->shiftMinutesToday($this->userId())]);
    }

    public function notifications()
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }
        $items = service('riderRepository')->notifications($this->userId());

        return $this->collection($items, ['total' => count($items)]);
    }

    // ---- helpers ----
    private function mutate(callable $fn, string $message)
    {
        if ($this->notRiderGuard()) {
            return $this->notRider();
        }
        $ok = $fn(service('riderRepository'), $this->userId());

        return $ok ? $this->ok(['message' => $message]) : $this->failWith('NOT_FOUND', 'Delivery not assigned to you.');
    }

    private function notRiderGuard(): bool
    {
        $p = service('riderRepository')->profile($this->userId());

        // A suspended/terminated rider (delivery_boys.status) loses operational access
        // immediately — not just visibility. me() still shows the profile so they see why.
        return $p === null || ($p['status'] ?? '') !== 'active';
    }

    private function notRider()
    {
        return $this->failWith('FORBIDDEN', 'This account is not a delivery rider.');
    }
}
