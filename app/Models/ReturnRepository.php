<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * ReturnRepository — Phase X4: the admin returns pipeline (the panel had no
 * returns module at all). Reads the register, exposes the forward-only state
 * machine, and applies audited transitions. Money effects stay where they
 * already live: refunds via RefundService (which also cancels commission,
 * X2a) and credit notes on refund completion (X2b).
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §6, 43 §9.6
 */
final class ReturnRepository
{
    /** Forward transitions per returns.status (verbatim enum). */
    public const ALLOWED = [
        'requested'       => ['approved', 'rejected'],
        'approved'        => ['pickup_scheduled'],
        'pickup_scheduled' => ['picked_up'],
        'picked_up'       => ['qc'],
        'qc'              => ['refund_approved', 'write_off'],
        'refund_approved' => ['refunded'],
        'refunded'        => ['restocked'],
        'rejected'        => [],
        'restocked'       => [],
        'write_off'       => [],
    ];

    /** Pure: is this transition legal? */
    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    /** @return list<array<string,mixed>> the admin register */
    public function listForAdmin(?string $status = null): array
    {
        $b = Database::connect()->table('returns r')
            ->select('r.id, r.status, r.channel, r.refund_to, r.qc_result, r.created_at, '
                . 'o.order_no, so.sub_order_no, u.name AS customer, v.display_name AS vendor, rr.label AS reason')
            ->join('orders o', 'o.id = r.order_id', 'left')
            ->join('sub_orders so', 'so.id = r.sub_order_id', 'left')
            ->join('customers c', 'c.id = r.customer_id', 'left')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->join('vendors v', 'v.id = so.vendor_id', 'left')
            ->join('rejection_reasons rr', 'rr.id = r.reason_code_id', 'left')
            ->where('r.deleted_at', null)
            ->orderBy('r.id', 'DESC')->limit(300);
        if ($status !== null && $status !== '') {
            $b->where('r.status', $status);
        }

        return $b->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return Database::connect()->table('returns')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
    }

    /** Full detail (joined) for the admin show page. @return array<string,mixed>|null */
    public function detail(int $id): ?array
    {
        return Database::connect()->table('returns r')
            ->select('r.*, o.order_no, so.sub_order_no, u.name AS customer, u.email AS customer_email, v.display_name AS vendor, rr.label AS reason')
            ->join('orders o', 'o.id = r.order_id', 'left')
            ->join('sub_orders so', 'so.id = r.sub_order_id', 'left')
            ->join('customers c', 'c.id = r.customer_id', 'left')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->join('vendors v', 'v.id = so.vendor_id', 'left')
            ->join('rejection_reasons rr', 'rr.id = r.reason_code_id', 'left')
            ->where('r.id', $id)->where('r.deleted_at', null)->get()->getRowArray() ?: null;
    }

    /** Returns raised against an order (for the order detail page). @return list<array<string,mixed>> */
    public function forOrder(int $orderId): array
    {
        return Database::connect()->table('returns r')
            ->select('r.id, r.status, r.refund_to, r.qc_result, r.created_at, rr.label AS reason')
            ->join('rejection_reasons rr', 'rr.id = r.reason_code_id', 'left')
            ->where('r.order_id', $orderId)->where('r.deleted_at', null)
            ->orderBy('r.id', 'DESC')->get()->getResultArray();
    }

    /** Validated, audited state transition. @return array{ok:bool,reason?:string} */
    public function transition(int $id, string $to, ?int $actorId = null): array
    {
        $row = $this->find($id);
        if ($row === null) {
            return ['ok' => false, 'reason' => 'return not found'];
        }
        if (! self::canTransition((string) $row['status'], $to)) {
            return ['ok' => false, 'reason' => "cannot move a {$row['status']} return to {$to}"];
        }

        Database::connect()->table('returns')->where('id', $id)
            ->update(['status' => $to, 'updated_by' => $actorId]);

        try {
            service('auditWriter')->log([
                'action' => 'return.' . $to, 'entity_type' => 'return', 'entity_id' => $id,
                'actor_user_id' => $actorId, 'principal_type' => 'platform', 'scope_type' => 'platform',
                'before' => ['status' => $row['status']], 'after' => ['status' => $to],
            ]);
        } catch (\Throwable) {
        }

        return ['ok' => true];
    }

    /** @return array<string,int> counts per status for the register header */
    public function countsByStatus(): array
    {
        $rows = Database::connect()->table('returns')
            ->select('status, COUNT(*) AS n')->where('deleted_at', null)
            ->groupBy('status')->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }

        return $out;
    }
}
