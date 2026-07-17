<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * SettlementAdjustmentRepository — penalty / bonus / dispute-hold / manual lines
 * against a vendor settlement. Adding (or reversing) a line recomputes the
 * settlement's `adjustments` total and `net_payable` so the payout reflects it.
 *
 * net_payable = gross − commission_total − refund_total − tcs − tds − fees + adjustments
 */
final class SettlementAdjustmentRepository
{
    private const TYPES = ['penalty', 'bonus', 'dispute_hold', 'manual'];

    /** @return list<array<string,mixed>> */
    public function forSettlement(int $settlementId): array
    {
        return Database::connect()->table('settlement_adjustments')
            ->select('id, type, amount, reason, status, created_at')
            ->where('settlement_id', $settlementId)
            ->orderBy('id', 'DESC')->get()->getResultArray();
    }

    /**
     * Add an adjustment line and apply its money effect INCREMENTALLY to the
     * settlement (adjustments += signed, net_payable += signed). Incremental
     * deltas preserve the original settlement-run net (some legacy rows don't
     * reconcile column-by-column), so the change is always exactly the amount.
     */
    public function add(int $settlementId, string $type, float $amount, string $reason, ?int $actorId = null): bool
    {
        $type   = in_array($type, self::TYPES, true) ? $type : 'manual';
        $signed = round(match ($type) {
            'bonus'   => abs($amount),
            'penalty', 'dispute_hold' => -abs($amount),
            default   => $amount,            // manual: take the sign as entered
        }, 2);
        $db = Database::connect();
        $db->table('settlement_adjustments')->insert([
            'settlement_id' => $settlementId, 'type' => $type, 'amount' => $signed,
            'reason' => mb_substr($reason, 0, 191), 'status' => 'applied', 'created_by' => $actorId,
        ]);
        $db->table('settlements')->where('id', $settlementId)
            ->set('adjustments', 'adjustments + ' . $signed, false)
            ->set('net_payable', 'net_payable + ' . $signed, false)
            ->set('updated_by', $actorId)->update();

        return true;
    }

    /** Reverse a line (keeps the audit row, removes its money effect). */
    public function reverse(int $adjustmentId, int $settlementId, ?int $actorId = null): bool
    {
        $db   = Database::connect();
        $line = $db->table('settlement_adjustments')->select('amount, status')
            ->where('id', $adjustmentId)->where('settlement_id', $settlementId)->get()->getRowArray();
        if ($line === null || $line['status'] !== 'applied') {
            return false;
        }
        $amount = round((float) $line['amount'], 2);
        $db->table('settlement_adjustments')->where('id', $adjustmentId)->update(['status' => 'reversed', 'updated_by' => $actorId]);
        $db->table('settlements')->where('id', $settlementId)
            ->set('adjustments', 'adjustments - ' . $amount, false)
            ->set('net_payable', 'net_payable - ' . $amount, false)
            ->set('updated_by', $actorId)->update();

        return true;
    }
}
