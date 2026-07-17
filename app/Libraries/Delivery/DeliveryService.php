<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

use App\Libraries\Store\LocationService;
use Config\Database;
use Throwable;

/**
 * DeliveryService — Phase 2: when a sub-order is accepted, auto-create its
 * delivery (idempotent) with a distance-based ETA and a tiered delivery fee, so
 * the existing vendor/rider pipeline can assign + fulfil it. Best-effort: a
 * failure here must never block the order-status change.
 */
final class DeliveryService
{
    /** Create a pending delivery for an accepted sub-order. @return int|null delivery id (null if exists/ineligible) */
    public function createForSubOrder(int $subOrderId, ?int $actorId = null): ?int
    {
        $db = Database::connect();
        if ($db->table('deliveries')->where('sub_order_id', $subOrderId)->where('deleted_at', null)->countAllResults() > 0) {
            return null; // idempotent — already has a delivery
        }
        $so = $db->table('sub_orders so')
            ->select('so.id, so.order_id, so.shop_id, so.grand_total, so.delivery_total, s.latitude AS shop_lat, s.longitude AS shop_lng, s.prep_time_min, oa.latitude AS drop_lat, oa.longitude AS drop_lng, oa.pincode')
            ->join('shops s', 's.id = so.shop_id', 'left')
            ->join('order_addresses oa', "oa.order_id = so.order_id AND oa.type = 'shipping'", 'left')
            ->where('so.id', $subOrderId)->where('so.deleted_at', null)->get()->getRowArray();
        if ($so === null || empty($so['shop_id'])) {
            return null;
        }

        $distance = 0.0;
        if ($so['shop_lat'] !== null && $so['drop_lat'] !== null) {
            $distance = LocationService::distanceKm((float) $so['shop_lat'], (float) $so['shop_lng'], (float) $so['drop_lat'], (float) $so['drop_lng']);
        }
        $etaMin = LocationService::etaMinutes($distance, $so['prep_time_min'] !== null ? (int) $so['prep_time_min'] : null);
        $eta    = date('Y-m-d H:i:s', time() + $etaMin * 60);

        $fee = $this->feeFor($distance, (float) ($so['grand_total'] ?? 0), (string) ($so['pincode'] ?? ''));
        if ($fee === null) {
            $fee = (float) ($so['delivery_total'] ?? 0); // fall back to the order's delivery charge
        }

        $db->table('deliveries')->insert([
            'uuid' => bin2hex(random_bytes(18)), 'sub_order_id' => $subOrderId, 'shop_id' => (int) $so['shop_id'],
            'mode' => 'pool', 'delivery_fee' => number_format($fee, 2, '.', ''), 'eta_at' => $eta,
            'status' => 'pending', 'created_by' => $actorId,
        ]);

        return (int) $db->insertID();
    }

    /** Distance-based ETA timestamp for a shop→drop pair (used by callers). */
    public static function etaFor(float $distanceKm, ?int $prepMin): string
    {
        return date('Y-m-d H:i:s', time() + LocationService::etaMinutes($distanceKm, $prepMin) * 60);
    }

    /** Pick the matching delivery_fees tier (zone + distance + order value). @return float|null */
    private function feeFor(float $distanceKm, float $orderValue, string $pincode): ?float
    {
        try {
            $db     = Database::connect();
            $zoneId = null;
            if ($pincode !== '') {
                $z = $db->table('delivery_zones')->select('id')
                    ->where('pincode_from <=', $pincode)->where('pincode_to >=', $pincode)
                    ->where('status', 'active')->where('deleted_at', null)->limit(1)->get()->getRowArray();
                $zoneId = $z ? (int) $z['id'] : null;
            }
            $b = $db->table('delivery_fees')->select('base_fee, per_km_fee')->where('status', 'active')
                ->where('min_distance_km <=', $distanceKm)->where('max_distance_km >=', $distanceKm)
                ->groupStart()->where('min_order_value', null)->orWhere('min_order_value <=', $orderValue)->groupEnd();
            if ($zoneId !== null) {
                $b->where('delivery_zone_id', $zoneId);
            }
            $tier = $b->orderBy('min_order_value', 'DESC')->limit(1)->get()->getRowArray();
            if ($tier === null) {
                return null;
            }

            return round((float) $tier['base_fee'] + $distanceKm * (float) $tier['per_km_fee'], 2);
        } catch (Throwable) {
            return null; // schema differences → caller falls back to delivery_total
        }
    }
}
