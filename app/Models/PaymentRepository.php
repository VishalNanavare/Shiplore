<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * PaymentRepository — admin read-only oversight of captured payments across all
 * vendors, joined to their order. Payments are immutable money records; the
 * admin views but does not mutate them (refunds are a separate flow).
 *
 * @see docs/architecture/24-ADMIN-PANEL.md §5 (Payment Management)
 */
final class PaymentRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null, ?string $q = null, ?string $method = null): array
    {
        $builder = Database::connect()->table('payments p')
            ->select('p.id, p.gateway, p.method, p.amount, p.currency, p.status, p.gateway_ref, p.captured_at, p.created_at, o.order_no')
            ->select("(SELECT COALESCE(SUM(amount), 0) FROM refunds rf WHERE rf.payment_id = p.id AND rf.status = 'completed') AS refunded", false)
            ->join('orders o', 'o.id = p.order_id', 'left')
            ->orderBy('p.created_at', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('p.status', $status);
        }
        if ($q !== null && $q !== '') {
            $builder->groupStart()->like('p.gateway_ref', $q)->orLike('o.order_no', $q)->groupEnd();
        }
        if ($method !== null && $method !== '') {
            $builder->where('p.method', $method);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('payments p')
            ->select('p.*, o.order_no')
            ->join('orders o', 'o.id = p.order_id', 'left')
            ->where('p.id', $id)
            ->get()->getRowArray();

        return $row ?: null;
    }
}
