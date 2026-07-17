<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * OrderRepository — admin-side order oversight across all vendors. Lists orders
 * (with customer name), exposes a full order breakdown (sub-orders per
 * vendor/shop + line items + status history) and cancels an order, cascading
 * the cancellation to its non-terminal sub-orders with history — atomic.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md §4 (Order Management)
 */
final class OrderRepository
{
    /** Sub-order statuses that can no longer be cancelled. */
    private const TERMINAL = ['delivered', 'completed', 'returned', 'cancelled'];

    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('orders o')
            ->select('o.id, o.order_no, o.channel, o.grand_total, o.payment_status, o.status, o.placed_at, o.created_at, u.name AS customer')
            ->join('customers c', 'c.id = o.customer_id', 'left')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->where('o.deleted_at', null)
            ->orderBy('o.created_at', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('o.status', $status);
        }

        return $builder->limit(500)->get()->getResultArray();
    }

    /**
     * Filtered order list for the admin index + CSV export.
     * @param array{status?:string,payment_status?:string,q?:string,from?:string,to?:string,limit?:int} $opts
     * @return list<array<string,mixed>>
     */
    public function search(array $opts = []): array
    {
        $b = Database::connect()->table('orders o')
            ->select('o.id, o.order_no, o.channel, o.grand_total, o.payment_status, o.status, o.placed_at, o.created_at, u.name AS customer, u.email AS customer_email')
            ->join('customers c', 'c.id = o.customer_id', 'left')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->where('o.deleted_at', null);
        if (! empty($opts['status'])) {
            $b->where('o.status', $opts['status']);
        }
        if (! empty($opts['payment_status'])) {
            $b->where('o.payment_status', $opts['payment_status']);
        }
        if (! empty($opts['q'])) {
            $b->groupStart()->like('o.order_no', $opts['q'])->orLike('u.name', $opts['q'])->orLike('u.email', $opts['q'])->groupEnd();
        }
        if (! empty($opts['from'])) {
            $b->where('o.created_at >=', $opts['from'] . ' 00:00:00');
        }
        if (! empty($opts['to'])) {
            $b->where('o.created_at <=', $opts['to'] . ' 23:59:59');
        }

        return $b->orderBy('o.created_at', 'DESC')->limit((int) ($opts['limit'] ?? 500))->get()->getResultArray();
    }

    /** @return array<string,mixed>|null Order header + customer contact. */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('orders o')
            ->select('o.*, u.name AS customer, u.email AS customer_email')
            ->join('customers c', 'c.id = o.customer_id', 'left')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->where('o.id', $id)->where('o.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> Sub-orders (per vendor/shop) for an order. */
    public function subOrders(int $orderId): array
    {
        return Database::connect()->table('sub_orders so')
            ->select('so.id, so.sub_order_no, so.status, so.grand_total, so.commission_amount, so.taxable_value, so.cgst, so.sgst, so.igst, v.display_name AS vendor, s.name AS shop')
            ->join('vendors v', 'v.id = so.vendor_id', 'left')
            ->join('shops s', 's.id = so.shop_id', 'left')
            ->where('so.order_id', $orderId)->where('so.deleted_at', null)
            ->orderBy('so.id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> Line items for an order (carry sub_order_id for grouping). */
    public function items(int $orderId): array
    {
        return Database::connect()->table('order_items')
            ->select('sub_order_id, product_title_snapshot, sku_snapshot, qty, unit_price, discount_amount, taxable_value, tax_rate')
            ->where('order_id', $orderId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Cancel an order and cascade to its non-terminal sub-orders, writing a
     * status-history row per affected sub-order. Atomic; fail-safe.
     */
    public function cancel(int $id, int $actorId, ?string $reason = null): bool
    {
        $order = $this->findById($id);
        if ($order === null || in_array($order['status'], ['cancelled', 'completed'], true)) {
            return false;
        }

        $db = Database::connect();
        $db->transBegin();

        try {
            $db->table('orders')->where('id', $id)->update(['status' => 'cancelled', 'updated_by' => $actorId]);

            $subs = $db->table('sub_orders')
                ->select('id, status')->where('order_id', $id)
                ->whereNotIn('status', self::TERMINAL)
                ->get()->getResultArray();

            foreach ($subs as $sub) {
                $db->table('sub_orders')->where('id', $sub['id'])->update(['status' => 'cancelled', 'updated_by' => $actorId]);
                $db->table('order_status_history')->insert([
                    'sub_order_id' => $sub['id'],
                    'from_status'  => $sub['status'],
                    'to_status'    => 'cancelled',
                    'actor_id'     => $actorId,
                    'reason'       => $reason !== null ? mb_substr($reason, 0, 191) : 'Cancelled by admin',
                ]);
            }

            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }
}
