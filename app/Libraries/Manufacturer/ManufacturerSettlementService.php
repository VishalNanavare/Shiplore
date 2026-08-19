<?php

declare(strict_types=1);

namespace App\Libraries\Manufacturer;

use Config\Database;
use Throwable;

/**
 * Builds a manufacturer's payout run.
 *
 * REUSES `settlements` rather than forking it. The header is keyed on vendor_id with an
 * FK to vendors(id), and a manufacturer is a vendors row — it already carries the period,
 * gross, commission_total, refund_total, net_payable, an idempotency_key and a status
 * that includes 'held'.
 *
 * That is the opposite call from mfg_deliveries and mfg_stock_transfers, and the
 * difference is the FK: those were blocked because deliveries.sub_order_id and
 * stock_transfers.shop_id point at tables a manufacturer has no rows in. settlements has
 * no such column, so forking it would only have split payout reporting in two.
 *
 * Revenue is recognised on RECEIPT, matching ManufacturerEarningsRepository — a
 * settlement that counted dispatched stock would pay out for goods that can still be
 * refused at the door.
 *
 * @see \App\Libraries\Settlement\SettlementService the vendor counterpart
 */
final class ManufacturerSettlementService
{
    /** Statuses where the buyer has taken delivery, so the money is genuinely owed. */
    private const EARNED = ['received', 'partially_received', 'closed'];

    /**
     * Build (or return) the settlement for one manufacturer over one period.
     *
     * IDEMPOTENT. A payout run is triggered by a scheduler, and schedulers retry: the
     * same period must yield the same settlement rather than a second one, which is how
     * a manufacturer gets paid twice and nobody notices until the bank reconciliation.
     * The key is derived from the tenant and the period, and the table's UNIQUE index on
     * it is the backstop if this check is ever bypassed.
     *
     * @return array{ok:bool,settlement_id?:int,gross?:float,commission?:float,net?:float,error?:string}
     */
    public function buildForPeriod(int $manufacturerId, string $periodStart, string $periodEnd, ?int $actorId = null): array
    {
        if ($manufacturerId <= 0) {
            return ['ok' => false, 'error' => 'Unknown manufacturer.'];
        }

        $db  = Database::connect();
        $key = 'mfg-' . $manufacturerId . '-' . $periodStart . '-' . $periodEnd;

        $existing = $db->table('settlements')->where('idempotency_key', $key)->get()->getRowArray();
        if ($existing !== null) {
            return ['ok' => true, 'settlement_id' => (int) $existing['id'],
                'gross' => (float) $existing['gross'], 'commission' => (float) $existing['commission_total'],
                'net' => (float) $existing['net_payable']];
        }

        $orders = $db->table('mfg_purchase_orders')
            ->select('id, po_no, grand_total')
            ->where('seller_vendor_id', $manufacturerId)
            ->where('deleted_at', null)
            ->whereIn('status', self::EARNED)
            ->where('updated_at >=', $periodStart . ' 00:00:00')
            ->where('updated_at <=', $periodEnd . ' 23:59:59')
            ->orderBy('id')
            ->get()->getResultArray();

        // No settlement at all rather than a zero one: an empty payout run is noise in a
        // list whose whole purpose is "what am I owed", and it would consume an
        // idempotency key that a later, non-empty run for the same period needs.
        if ($orders === []) {
            return ['ok' => false, 'error' => 'Nothing settled in that period.'];
        }

        $policy     = service('b2bPolicy');
        $gross      = 0.0;
        foreach ($orders as $o) {
            $gross += (float) $o['grand_total'];
        }
        $commission = $policy->commissionOn($gross);
        $net        = max(0.0, round($gross - $commission, 2));

        $db->transBegin();

        try {
            $db->table('settlements')->insert([
                'uuid' => $this->uuid(), 'vendor_id' => $manufacturerId,
                'period_start' => $periodStart, 'period_end' => $periodEnd,
                'gross' => $this->n($gross), 'commission_total' => $this->n($commission),
                'refund_total' => '0.0000', 'tcs' => '0.0000', 'tds' => '0.0000',
                'fees' => '0.0000', 'adjustments' => '0.0000',
                'net_payable' => $this->n($net),
                'idempotency_key' => $key,
                // 'calculated', never 'paid': approving and paying are separate
                // decisions a human makes, and a run that marked itself paid would
                // assert money left an account it has no way of knowing about.
                'status' => 'calculated',
                'created_by' => $actorId,
            ]);
            $sid = (int) $db->insertID();

            foreach ($orders as $o) {
                $db->table('settlement_lines')->insert([
                    'settlement_id' => $sid,
                    // 'mfg_sale', not 'sale': for a vendor 'sale' means a sub_order, and
                    // sharing one member would make ref_id resolvable only by looking up
                    // the settlement's owner.
                    'ref_type' => 'mfg_sale', 'ref_id' => (int) $o['id'],
                    'sub_order_id' => null,
                    'amount' => $this->n($o['grand_total']), 'direction' => 'credit',
                ]);
            }

            if ($commission > 0) {
                $db->table('settlement_lines')->insert([
                    'settlement_id' => $sid, 'ref_type' => 'commission', 'ref_id' => null,
                    'sub_order_id' => null, 'amount' => $this->n($commission), 'direction' => 'debit',
                ]);
            }

            $db->transComplete();

            return $db->transStatus()
                ? ['ok' => true, 'settlement_id' => $sid, 'gross' => round($gross, 2),
                    'commission' => round($commission, 2), 'net' => $net]
                : ['ok' => false, 'error' => 'Could not build the settlement.'];
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'mfg settlement build failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'Could not build the settlement.'];
        }
    }

    /** @return list<array<string,mixed>> settlements for this manufacturer, newest first */
    public function list(int $manufacturerId, ?string $status = null, int $limit = 100): array
    {
        if ($manufacturerId <= 0) {
            return [];
        }
        $b = Database::connect()->table('settlements')
            ->where('vendor_id', $manufacturerId);

        if ($status !== null && $status !== '') {
            $b->where('status', $status);
        }

        return $b->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /** @return array<string,mixed>|null one settlement and its lines, tenant-scoped */
    public function find(int $settlementId, int $manufacturerId): ?array
    {
        $db = Database::connect();
        $s  = $db->table('settlements')
            ->where('id', $settlementId)->where('vendor_id', $manufacturerId)
            ->get()->getRowArray();

        if ($s === null) {
            return null;
        }
        $s['lines'] = $db->table('settlement_lines')
            ->where('settlement_id', $settlementId)->orderBy('id')->get()->getResultArray();

        return $s;
    }

    private function n(mixed $v): string
    {
        return number_format((float) $v, 4, '.', '');
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
