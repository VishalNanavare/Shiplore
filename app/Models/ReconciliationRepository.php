<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * ReconciliationRepository — gateway settlement-file reconciliation rows
 * (expected vs actual, with the variance). Admins review mismatches and resolve
 * or flag them as chargebacks. (Automated importer of gateway payout files is a
 * separate worker; this exposes + manages the records.)
 */
final class ReconciliationRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null, int $limit = 300): array
    {
        $b = Database::connect()->table('reconciliations r')
            ->select('r.id, r.gateway, r.settlement_file_ref, r.expected_amount, r.actual_amount, r.variance, r.recon_date, r.status, p.gateway_ref AS payment_ref, o.order_no')
            ->join('payments p', 'p.id = r.payment_id', 'left')
            ->join('orders o', 'o.id = p.order_id', 'left')
            ->orderBy('r.id', 'DESC')->limit($limit);
        if ($status !== null && $status !== '') {
            $b->where('r.status', $status);
        }

        return $b->get()->getResultArray();
    }

    /** @return array<string,int> */
    public function countsByStatus(): array
    {
        $out = [];
        foreach (Database::connect()->table('reconciliations')->select('status, COUNT(*) AS n')->groupBy('status')->get()->getResultArray() as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }

        return $out;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        if (! in_array($status, ['matched', 'mismatch', 'missing', 'chargeback', 'resolved'], true)) {
            return false;
        }

        return (bool) Database::connect()->table('reconciliations')->where('id', $id)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }
}
