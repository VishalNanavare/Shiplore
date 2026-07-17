<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * StoreCouponRepository — validates a coupon code at checkout against its
 * promotion (active + within validity). Percentage promotions return a discount
 * percent the cart applies.
 */
final class StoreCouponRepository
{
    /**
     * @param float    $orderTotal cart subtotal — enforces promotions.conditions.min_order_value (0 = skip)
     * @param int|null $customerId enforces coupons.per_user_limit (null = skip)
     * @return array{ok:bool,pct:float,message:string,code:string}
     */
    public function validate(string $code, float $orderTotal = 0.0, ?int $customerId = null): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['ok' => false, 'pct' => 0.0, 'message' => 'Enter a coupon code.', 'code' => ''];
        }

        $row = Database::connect()->table('coupons c')
            ->select('c.id AS coupon_id, c.status AS cstatus, c.valid_from, c.valid_to, c.usage_limit, c.per_user_limit, c.used_count, p.type, p.value, p.conditions, p.status AS pstatus')
            ->join('promotions p', 'p.id = c.promotion_id', 'left')
            ->where('c.code', $code)->where('c.deleted_at', null)
            ->get()->getRowArray();

        if ($row === null) {
            return ['ok' => false, 'pct' => 0.0, 'message' => 'Invalid coupon code.', 'code' => $code];
        }
        $today = date('Y-m-d');
        if ($row['cstatus'] !== 'active' || ($row['pstatus'] ?? '') !== 'active') {
            return ['ok' => false, 'pct' => 0.0, 'message' => 'This coupon is not active.', 'code' => $code];
        }
        if (($row['valid_from'] && $today < $row['valid_from']) || ($row['valid_to'] && $today > $row['valid_to'])) {
            return ['ok' => false, 'pct' => 0.0, 'message' => 'This coupon has expired.', 'code' => $code];
        }
        if ($row['usage_limit'] !== null && (int) $row['used_count'] >= (int) $row['usage_limit']) {
            return ['ok' => false, 'pct' => 0.0, 'message' => 'This coupon is exhausted.', 'code' => $code];
        }
        if ($row['type'] !== 'percentage') {
            return ['ok' => false, 'pct' => 0.0, 'message' => 'This coupon type is not supported online.', 'code' => $code];
        }

        // Minimum order value (promotions.conditions JSON: {"min_order_value": N}).
        $cond     = is_string($row['conditions'] ?? null) ? (json_decode((string) $row['conditions'], true) ?: []) : (is_array($row['conditions'] ?? null) ? $row['conditions'] : []);
        $minOrder = isset($cond['min_order_value']) ? (float) $cond['min_order_value'] : 0.0;
        if ($minOrder > 0 && $orderTotal > 0 && $orderTotal < $minOrder) {
            return ['ok' => false, 'pct' => 0.0, 'message' => 'Add ₹' . number_format($minOrder, 0) . ' worth of items to use this coupon.', 'code' => $code];
        }

        // Per-customer usage limit (across past redemptions).
        if ($customerId !== null && $row['per_user_limit'] !== null && (int) $row['per_user_limit'] > 0) {
            $used = Database::connect()->table('coupon_redemptions')
                ->where('coupon_id', (int) $row['coupon_id'])->where('customer_id', $customerId)->countAllResults();
            if ($used >= (int) $row['per_user_limit']) {
                return ['ok' => false, 'pct' => 0.0, 'message' => 'You have already used this coupon.', 'code' => $code];
            }
        }

        return ['ok' => true, 'pct' => (float) $row['value'], 'message' => 'Coupon applied: ' . $row['value'] . '% off.', 'code' => $code];
    }

    /**
     * Active, currently-valid percentage coupons for the checkout "see all coupons"
     * sheet. @return list<array{code:string,pct:float,min_order_value:float}>
     */
    public function activePublic(int $limit = 20): array
    {
        $today = date('Y-m-d');
        $rows  = Database::connect()->table('coupons c')
            ->select('c.code, p.value AS pct, p.conditions')
            ->join('promotions p', 'p.id = c.promotion_id', 'left')
            ->where('c.deleted_at', null)->where('c.status', 'active')->where('p.status', 'active')->where('p.type', 'percentage')
            ->groupStart()->where('c.valid_from', null)->orWhere('c.valid_from <=', $today . ' 23:59:59')->groupEnd()
            ->groupStart()->where('c.valid_to', null)->orWhere('c.valid_to >=', $today)->groupEnd()
            ->groupStart()->where('c.usage_limit', null)->orWhere('c.used_count < c.usage_limit', null, false)->groupEnd()
            ->orderBy('p.value', 'DESC')->limit($limit)
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $cond  = is_string($r['conditions'] ?? null) ? (json_decode((string) $r['conditions'], true) ?: []) : [];
            $out[] = [
                'code'            => (string) $r['code'],
                'pct'             => (float) $r['pct'],
                'min_order_value' => isset($cond['min_order_value']) ? (float) $cond['min_order_value'] : 0.0,
            ];
        }

        return $out;
    }
}
