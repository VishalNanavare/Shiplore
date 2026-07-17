<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * CommissionRateRepository — gathers the candidate commission rates per level
 * for the resolver: the product's category (and its parent), the vendor's
 * business type, and the active global plan. Fail-safe.
 *
 * @see docs/architecture/31-BUSINESS-TYPE-COMMISSION.md
 */
final class CommissionRateRepository
{
    /** @return array<string,float|null> level => rate */
    public function ratesForProduct(int $productId): array
    {
        $rates = ['product' => null, 'subcategory' => null, 'category' => null, 'business_type' => null, 'global' => $this->globalRate()];

        try {
            $db      = Database::connect();
            $product = $db->table('products')->select('category_id, vendor_id')->where('id', $productId)->get()->getRowArray();
            if ($product === null) {
                return $rates;
            }

            $leaf = $db->table('categories')->select('parent_id, default_commission_rate')->where('id', $product['category_id'])->get()->getRowArray();
            if ($leaf !== null) {
                if ($leaf['parent_id'] !== null) {
                    $rates['subcategory'] = $this->num($leaf['default_commission_rate']);
                    $parent = $db->table('categories')->select('default_commission_rate')->where('id', $leaf['parent_id'])->get()->getRowArray();
                    $rates['category'] = $this->num($parent['default_commission_rate'] ?? null);
                } else {
                    $rates['category'] = $this->num($leaf['default_commission_rate']);
                }
            }

            $vendor = $db->table('vendors')->select('business_type_id')->where('id', $product['vendor_id'])->get()->getRowArray();
            if ($vendor !== null && $vendor['business_type_id'] !== null) {
                $bt = $db->table('business_types')->select('default_commission_rate')->where('id', $vendor['business_type_id'])->get()->getRowArray();
                $rates['business_type'] = $this->num($bt['default_commission_rate'] ?? null);
            }
        } catch (Throwable) {
            // keep whatever we resolved
        }

        return $rates;
    }

    /** @return array<string,float|null> Business-type + global only (no product context). */
    public function ratesForVendor(int $vendorId): array
    {
        $rates = ['business_type' => null, 'global' => $this->globalRate()];

        try {
            $db     = Database::connect();
            $vendor = $db->table('vendors')->select('business_type_id')->where('id', $vendorId)->get()->getRowArray();
            if ($vendor !== null && $vendor['business_type_id'] !== null) {
                $bt = $db->table('business_types')->select('default_commission_rate')->where('id', $vendor['business_type_id'])->get()->getRowArray();
                $rates['business_type'] = $this->num($bt['default_commission_rate'] ?? null);
            }
        } catch (Throwable) {
        }

        return $rates;
    }

    private function globalRate(): ?float
    {
        try {
            $row = Database::connect()->table('commission_plans')
                ->select('default_rate')->where('status', 'active')->where('deleted_at', null)
                ->orderBy('id', 'ASC')->get()->getRowArray();

            return $this->num($row['default_rate'] ?? null);
        } catch (Throwable) {
            return null;
        }
    }

    private function num(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}
