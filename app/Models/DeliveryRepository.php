<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Workflow\StatusMachine;
use Config\Database;
use Throwable;

/**
 * DeliveryRepository — read-only delivery monitoring across shops.
 *
 * @see docs/architecture/28-DELIVERY-APP.md
 */
final class DeliveryRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('deliveries d')
            ->select('d.id, d.mode, d.status, d.delivery_fee, d.eta_at, d.created_at, so.sub_order_no, s.name AS shop')
            ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
            ->join('shops s', 's.id = d.shop_id', 'left')
            ->where('d.deleted_at', null)
            ->orderBy('d.created_at', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('d.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /** Joined detail for the admin show page. @return array<string,mixed>|null */
    public function detail(int $id): ?array
    {
        $row = Database::connect()->table('deliveries d')
            ->select('d.*, so.sub_order_no, so.order_id, s.name AS shop, v.display_name AS vendor, o.order_no')
            ->join('sub_orders so', 'so.id = d.sub_order_id', 'left')
            ->join('shops s', 's.id = d.shop_id', 'left')
            ->join('vendors v', 'v.id = s.vendor_id', 'left')
            ->join('orders o', 'o.id = so.order_id', 'left')
            ->where('d.id', $id)->where('d.deleted_at', null)->get()->getRowArray();

        return $row ?: null;
    }

    /** All real delivery states — the allowed targets for a $force admin override. */
    public const STATES = ['pending', 'assigned', 'picked_up', 'arrived', 'out_for_delivery', 'delivered', 'failed', 'returned'];

    /**
     * Apply a delivery status change.
     *
     * @param bool $force Admin override — bypass the forward FLOW (any valid state,
     *                    including backward corrections and same-state no-ops). The
     *                    normal admin/rider path leaves this false to keep FLOW intact.
     */
    public function updateStatus(int $id, string $to, ?int $actorId = null, bool $force = false): bool
    {
        $db  = Database::connect();
        $row = $db->table('deliveries')->select('status')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
        if ($row === null) {
            return false;
        }
        $from = (string) $row['status'];
        if ($force) {
            if (! in_array($to, self::STATES, true)) {
                return false; // override still requires a real target state
            }
        } elseif (! StatusMachine::canDelivery($from, $to)) {
            return false; // normal path: only forward transitions (StatusMachine::allowed() treats from===to as a no-op)
        }

        $patch = ['status' => $to, 'updated_by' => $actorId];
        if ($to === 'delivered') {
            $patch['delivered_at'] = date('Y-m-d H:i:s'); // Phase 3 — on-time / SLA metric
        } elseif ($force) {
            $patch['delivered_at'] = null; // force-correction away from delivered clears the stamp
        }

        $ok = (bool) $db->table('deliveries')->where('id', $id)->update($patch);
        if (! $ok && $from !== $to) {
            return false;
        }

        // Keep the customer-visible sub-order timeline in sync (mirrors
        // RiderRepository::syncOrderFromDelivery — forward-only, best-effort).
        $this->syncOrderFromDelivery($id, $to);

        // Audit the manual override on the delivery's own status history.
        try {
            $db->table('delivery_status_history')->insert([
                'delivery_id' => $id,
                'from_status' => $from,
                'to_status'   => $to,
                'actor_id'    => $actorId,
                'note'        => 'admin override',
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
        }

        return true;
    }

    /**
     * Mirror an admin delivery-status change onto the parent sub-order (and order)
     * so the customer-facing timeline stays correct. Forward-only and best-effort:
     * mirrors RiderRepository::syncOrderFromDelivery.
     */
    private function syncOrderFromDelivery(int $deliveryId, string $deliveryStatus): void
    {
        $target = ['out_for_delivery' => 'out_for_delivery', 'delivered' => 'delivered', 'returned' => 'returned', 'failed' => 'returned'][$deliveryStatus] ?? null;
        if ($target === null) {
            return;
        }
        try {
            $db  = Database::connect();
            $sub = $db->table('deliveries')->select('sub_order_id')->where('id', $deliveryId)->get()->getRowArray();
            if ($sub === null) {
                return;
            }
            $subId = (int) $sub['sub_order_id'];
            $patch = ['status' => $target];
            if ($target === 'delivered') {
                $patch['delivered_at'] = date('Y-m-d H:i:s');
            }
            $db->table('sub_orders')->where('id', $subId)->whereNotIn('status', ['cancelled', 'returned'])->update($patch);

            if ($target === 'delivered') {
                $oid = (int) ($db->table('sub_orders')->select('order_id')->where('id', $subId)->get()->getRowArray()['order_id'] ?? 0);
                if ($oid > 0) {
                    $subs  = $db->table('sub_orders')->select('status')->where('order_id', $oid)->whereNotIn('status', ['cancelled', 'returned'])->get()->getResultArray();
                    $total = count($subs);
                    $done  = 0;
                    foreach ($subs as $s) {
                        if (in_array($s['status'], ['delivered', 'completed'], true)) {
                            $done++;
                        }
                    }
                    if ($total > 0 && $done === $total) {
                        $db->table('orders')->where('id', $oid)->update(['status' => 'completed']);
                    } elseif ($done > 0) {
                        $db->table('orders')->where('id', $oid)->whereIn('status', ['created', 'confirmed', 'partially_fulfilled'])->update(['status' => 'partially_fulfilled']);
                    }
                }
            }
        } catch (Throwable) {
        }
    }
}
