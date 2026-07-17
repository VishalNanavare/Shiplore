<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

/**
 * SlaTracker — delivery SLA status from the promised ETA. For a completed
 * delivery, compares delivered_at to the ETA; for an in-flight one, compares now
 * (or a reference time) to the ETA with an at-risk window. Pure.
 *
 * @see docs/architecture/36-DELIVERY-OPERATIONS.md
 */
final class SlaTracker
{
    private const AT_RISK_SECONDS = 900; // 15 min

    /** @return 'unknown'|'on_time'|'at_risk'|'breached' */
    public static function status(?string $etaAt, ?string $deliveredAt = null, ?int $now = null): string
    {
        if ($etaAt === null || $etaAt === '') {
            return 'unknown';
        }
        $eta = strtotime($etaAt);

        if ($deliveredAt !== null && $deliveredAt !== '') {
            return strtotime($deliveredAt) <= $eta ? 'on_time' : 'breached';
        }

        $ref = $now ?? time();
        if ($ref > $eta) {
            return 'breached';
        }

        return ($eta - $ref) <= self::AT_RISK_SECONDS ? 'at_risk' : 'on_time';
    }
}
