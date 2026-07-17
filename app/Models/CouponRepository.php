<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * CouponRepository — read-only oversight of coupons (joined to their promotion).
 *
 * @see docs/architecture/24-ADMIN-PANEL.md
 */
final class CouponRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('coupons c')
            ->select('c.id, c.code, c.usage_limit, c.per_user_limit, c.used_count, c.valid_from, c.valid_to, c.status, p.name AS promotion')
            ->join('promotions p', 'p.id = c.promotion_id', 'left')
            ->where('c.deleted_at', null)
            ->orderBy('c.id', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('c.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function detail(int $id): ?array
    {
        $row = Database::connect()->table('coupons c')
            ->select('c.*, p.name AS promotion')
            ->join('promotions p', 'p.id = c.promotion_id', 'left')
            ->where('c.id', $id)->where('c.deleted_at', null)->get()->getRowArray();

        return $row ?: null;
    }

    /** Redemption history (who used it, on which order, how much). @return list<array<string,mixed>> */
    public function redemptions(int $couponId, int $limit = 200): array
    {
        return Database::connect()->table('coupon_redemptions cr')
            ->select('cr.discount_amount, cr.redeemed_at, o.order_no, o.id AS order_id, u.name AS customer')
            ->join('orders o', 'o.id = cr.order_id', 'left')
            ->join('customers c', 'c.id = cr.customer_id', 'left')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->where('cr.coupon_id', $couponId)
            ->orderBy('cr.redeemed_at', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /** @return array{count:int,total:float} aggregate usage */
    public function usageStats(int $couponId): array
    {
        $row = Database::connect()->table('coupon_redemptions')
            ->select('COUNT(*) AS n, COALESCE(SUM(discount_amount), 0) AS total')
            ->where('coupon_id', $couponId)->get()->getRowArray();

        return ['count' => (int) ($row['n'] ?? 0), 'total' => (float) ($row['total'] ?? 0)];
    }
}
