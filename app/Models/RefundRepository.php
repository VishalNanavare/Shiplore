<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * RefundRepository — admin oversight of refunds (joined to payment + order) and
 * status transitions: a refund can be marked completed or failed. Fail-safe.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md §6 (Refund Management)
 */
final class RefundRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('refunds r')
            ->select('r.id, r.amount, r.destination, r.status, r.gateway_ref, r.created_at, o.order_no, p.method')
            ->join('payments p', 'p.id = r.payment_id', 'left')
            ->join('orders o', 'o.id = p.order_id', 'left')
            ->orderBy('r.created_at', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('r.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('refunds')
            ->where('id', $id)->get()->getRowArray();

        return $row ?: null;
    }

    /** Joined detail for the admin show page. @return array<string,mixed>|null */
    public function detail(int $id): ?array
    {
        $row = Database::connect()->table('refunds r')
            ->select('r.*, o.order_no, o.id AS order_id, p.method, p.gateway AS pay_gateway, p.amount AS payment_amount')
            ->join('payments p', 'p.id = r.payment_id', 'left')
            ->join('orders o', 'o.id = p.order_id', 'left')
            ->where('r.id', $id)->get()->getRowArray();

        return $row ?: null;
    }

    /** Refunds linked to an order (via its payment or its return). @return list<array<string,mixed>> */
    public function forOrder(int $orderId): array
    {
        return Database::connect()->table('refunds r')
            ->select('r.id, r.amount, r.destination, r.status, r.gateway_ref, r.created_at, p.method')
            ->join('payments p', 'p.id = r.payment_id', 'left')
            ->join('returns ret', 'ret.id = r.return_id', 'left')
            ->groupStart()->where('p.order_id', $orderId)->orWhere('ret.order_id', $orderId)->groupEnd()
            ->orderBy('r.id', 'DESC')->get()->getResultArray();
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('refunds')
            ->where('id', $id)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }
}
