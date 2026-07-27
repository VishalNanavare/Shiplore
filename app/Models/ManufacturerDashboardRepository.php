<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * ManufacturerDashboardRepository — headline counts for the manufacturer panel.
 *
 * Deliberately narrow: units, products and stock. There are no order or sales metrics
 * yet because manufacturers do not sell to consumers — their sales arrive through
 * msonline purchase orders, which land in phase B.
 */
final class ManufacturerDashboardRepository
{
    /** @return array{units:int,products:int,published:int,drafts:int} */
    public function metrics(int $manufacturerId): array
    {
        if ($manufacturerId <= 0) {
            return ['units' => 0, 'products' => 0, 'published' => 0, 'drafts' => 0];
        }
        $db = Database::connect();

        $units = $db->table('mshops')
            ->where('vendor_id', $manufacturerId)->where('deleted_at', null)
            ->countAllResults();

        $byStatus = [];
        $rows     = $db->table('products')
            ->select('status, COUNT(*) AS cnt')
            ->where('vendor_id', $manufacturerId)->where('deleted_at', null)
            ->groupBy('status')
            ->get()->getResultArray();
        foreach ($rows as $r) {
            $byStatus[(string) $r['status']] = (int) $r['cnt'];
        }

        return [
            'units'     => $units,
            'products'  => array_sum($byStatus),
            'published' => $byStatus['published'] ?? 0,
            'drafts'    => $byStatus['draft'] ?? 0,
        ];
    }

    /** Recently touched products, for an at-a-glance list. @return list<array<string,mixed>> */
    public function recentProducts(int $manufacturerId, int $limit = 10): array
    {
        if ($manufacturerId <= 0) {
            return [];
        }

        return Database::connect()->table('products p')
            ->select('p.id, p.title, p.status, p.updated_at, pv.making_price, pv.base_price')
            ->join('product_variants pv', 'pv.product_id = p.id AND pv.is_default = 1', 'left')
            ->where('p.vendor_id', $manufacturerId)
            ->where('p.deleted_at', null)
            ->orderBy('p.updated_at', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }
}
