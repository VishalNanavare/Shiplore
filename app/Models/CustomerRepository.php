<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * CustomerRepository — admin oversight of customers (identity lives in `users`).
 * Lists customers with contact + lifetime value, shows a profile with recent
 * orders, and blocks / unblocks an account. Fail-safe.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md §8 (Customer Management)
 */
final class CustomerRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('customers c')
            ->select('c.id, u.name, u.email, u.phone, c.lifetime_value, c.status, c.created_at')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->where('c.deleted_at', null)
            ->orderBy('c.created_at', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('c.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('customers c')
            ->select('c.*, u.name, u.email, u.phone')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->where('c.id', $id)->where('c.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> Recent orders for a customer. */
    public function recentOrders(int $customerId, int $limit = 10): array
    {
        return Database::connect()->table('orders')
            ->select('id, order_no, grand_total, payment_status, status, created_at')
            ->where('customer_id', $customerId)->where('deleted_at', null)
            ->orderBy('created_at', 'DESC')->limit($limit)
            ->get()->getResultArray();
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('customers')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }
}
