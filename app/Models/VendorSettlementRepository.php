<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * VendorSettlementRepository — a vendor's own settlements + commission ledger.
 * Read-only for the vendor; scoped by vendor_id.
 */
final class VendorSettlementRepository
{
    /** @return list<array<string,mixed>> */
    public function list(int $vendorId, int $limit = 30, int $offset = 0): array
    {
        return Database::connect()->table('settlements')
            ->select('id, period_start, period_end, gross, commission_total, refund_total, net_payable, status')
            ->where('vendor_id', $vendorId)
            ->orderBy('period_end', 'DESC')
            ->limit($limit, $offset)
            ->get()->getResultArray();
    }

    public function count(int $vendorId): int
    {
        return Database::connect()->table('settlements')
            ->where('vendor_id', $vendorId)
            ->countAllResults();
    }

    /** @return array<string,mixed>|null Scoped: null if not this vendor's settlement. */
    public function findById(int $id, int $vendorId): ?array
    {
        $row = Database::connect()->table('settlements')
            ->where('id', $id)->where('vendor_id', $vendorId)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function lines(int $settlementId): array
    {
        return Database::connect()->table('settlement_lines')
            ->select('ref_type, ref_id, sub_order_id, amount, direction')
            ->where('settlement_id', $settlementId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> Commission ledger entries for the vendor. */
    public function commissionLedger(int $vendorId): array
    {
        return Database::connect()->table('commission_ledger cl')
            ->select('cl.id, cl.base_amount, cl.rate, cl.commission_amount, cl.status, cl.created_at, so.sub_order_no')
            ->join('sub_orders so', 'so.id = cl.sub_order_id', 'left')
            ->where('cl.vendor_id', $vendorId)
            ->orderBy('cl.id', 'DESC')
            ->get()->getResultArray();
    }
}
