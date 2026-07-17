<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * SettlementRepository — admin oversight of vendor settlements (payout cycles).
 * Lists settlements with the vendor, exposes the line breakdown, and transitions
 * status (approve, mark paid). Fail-safe.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md §7 (Settlement Management)
 */
final class SettlementRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('settlements s')
            ->select('s.id, v.display_name AS vendor, s.period_start, s.period_end, s.gross, s.commission_total, s.refund_total, s.net_payable, s.status')
            ->join('vendors v', 'v.id = s.vendor_id', 'left')
            ->orderBy('s.period_end', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('s.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('settlements s')
            ->select('s.*, v.display_name AS vendor')
            ->join('vendors v', 'v.id = s.vendor_id', 'left')
            ->where('s.id', $id)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function lines(int $settlementId): array
    {
        return Database::connect()->table('settlement_lines')
            ->select('id, ref_type, ref_id, sub_order_id, amount, direction')
            ->where('settlement_id', $settlementId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('settlements')
            ->where('id', $id)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }

    /** A vendor's settlements overlapping a period, for the statement. @return list<array<string,mixed>> */
    public function forVendorPeriod(int $vendorId, string $from, string $to): array
    {
        return Database::connect()->table('settlements')
            ->select('id, period_start, period_end, gross, commission_total, refund_total, tcs, tds, fees, adjustments, net_payable, status')
            ->where('vendor_id', $vendorId)
            ->where('period_end >=', $from)->where('period_start <=', $to)
            ->orderBy('period_start', 'ASC')->get()->getResultArray();
    }
}
