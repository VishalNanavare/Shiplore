<?php

declare(strict_types=1);

namespace App\Libraries\Commission;

/**
 * CommissionResolver — applies the commission priority hierarchy
 * (Product > Subcategory > Category > BusinessType > Global Default) and
 * computes the commission amount. Pure: it is given candidate rates per level
 * (fetched by CommissionRateRepository) and returns the winning rate + level.
 *
 * @see docs/architecture/31-BUSINESS-TYPE-COMMISSION.md
 */
final class CommissionResolver
{
    private const PRIORITY = ['product', 'subcategory', 'category', 'business_type', 'global'];

    /**
     * @param array<string,float|null> $rates level => rate (or null/absent)
     * @return array{rate:float,level:string}
     */
    public function resolve(array $rates): array
    {
        foreach (self::PRIORITY as $level) {
            if (isset($rates[$level]) && $rates[$level] !== null) {
                return ['rate' => (float) $rates[$level], 'level' => $level];
            }
        }

        return ['rate' => 0.0, 'level' => 'none'];
    }

    /** Commission amount for a base, as a 2-decimal string. */
    public function amount(float $base, float $ratePct): string
    {
        return number_format(round($base * $ratePct / 100, 2), 2, '.', '');
    }
}
