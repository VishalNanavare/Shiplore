<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Governance\ChangeRequestStore;
use Config\Database;

/**
 * ChangeRequestRepository — persistence for the change-request engine
 * (change_requests + change_request_approvals). Owns JSON encoding; the
 * engine sees arrays only. List methods feed the vendor approval inbox and
 * the admin unified queue (X3/X4).
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §1
 */
final class ChangeRequestRepository implements ChangeRequestStore
{
    private const JSON_COLS = ['payload_old', 'payload_new', 'required_levels'];

    public function create(array $row): int
    {
        $db                = Database::connect();
        $row['uuid']       = $this->uuid();
        $row['request_no'] = 'CR-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        foreach (self::JSON_COLS as $c) {
            if (array_key_exists($c, $row) && $row[$c] !== null) {
                $row[$c] = json_encode($row[$c]);
            }
        }
        $db->table('change_requests')->insert($row);

        return (int) $db->insertID();
    }

    public function find(int $id): ?array
    {
        $row = Database::connect()->table('change_requests')->where('id', $id)->get()->getRowArray();

        return $row !== null ? $this->decode($row) : null;
    }

    public function update(int $id, array $fields): void
    {
        foreach (self::JSON_COLS as $c) {
            if (array_key_exists($c, $fields) && $fields[$c] !== null) {
                $fields[$c] = json_encode($fields[$c]);
            }
        }
        Database::connect()->table('change_requests')->where('id', $id)->update($fields);
    }

    public function addDecision(array $row): void
    {
        Database::connect()->table('change_request_approvals')->insert($row);
    }

    public function hasOpen(string $entityType, ?int $entityId, string $fieldGroup, ?int $excludeId = null): bool
    {
        $b = Database::connect()->table('change_requests')
            ->where('entity_type', $entityType)
            ->where('field_group', $fieldGroup)
            ->whereIn('status', ['pending_l1', 'pending_l2', 'changes_requested', 'approved', 'apply_failed']);
        $entityId === null ? $b->where('entity_id IS NULL', null, false) : $b->where('entity_id', $entityId);
        if ($excludeId !== null) {
            $b->where('id !=', $excludeId);
        }

        return $b->countAllResults() > 0;
    }

    public function expireOverdue(string $nowSql): array
    {
        $db   = Database::connect();
        $rows = $db->table('change_requests')
            ->whereIn('status', ['pending_l1', 'pending_l2'])
            ->where('sla_due_at IS NOT NULL', null, false)
            ->where('sla_due_at <', $nowSql)
            ->get()->getResultArray();

        foreach ($rows as $r) {
            $db->table('change_requests')->where('id', $r['id'])->update(['status' => 'expired']);
        }

        return array_map(fn (array $r) => $this->decode($r), $rows);
    }

    // ---- list/read methods for the inbox + queue UIs --------------------

    /** Vendor approval inbox: requests waiting on this vendor's L-level. @return list<array<string,mixed>> */
    public function pendingForVendor(int $vendorId, ?string $status = null): array
    {
        $b = Database::connect()->table('change_requests cr')
            ->select('cr.*, u.name AS requester_name')
            ->join('users u', 'u.id = cr.requested_by', 'left')
            ->where('cr.vendor_id', $vendorId)
            ->orderBy('cr.id', 'DESC')->limit(300);
        $status === null
            ? $b->whereIn('cr.status', ['pending_l1', 'pending_l2'])
            : $b->where('cr.status', $status);

        return array_map(fn (array $r) => $this->decode($r), $b->get()->getResultArray());
    }

    /** Admin unified queue. @return list<array<string,mixed>> */
    public function pendingForAdmin(?string $status = null): array
    {
        $b = Database::connect()->table('change_requests cr')
            ->select('cr.*, u.name AS requester_name, v.display_name AS vendor_name')
            ->join('users u', 'u.id = cr.requested_by', 'left')
            ->join('vendors v', 'v.id = cr.vendor_id', 'left')
            ->orderBy('cr.sla_due_at', 'ASC')->limit(300);
        $status === null ? $b->where('cr.status', 'pending_l2') : $b->where('cr.status', $status);

        return array_map(fn (array $r) => $this->decode($r), $b->get()->getResultArray());
    }

    /** Requester's own requests. @return list<array<string,mixed>> */
    public function listForRequester(int $userId): array
    {
        $rows = Database::connect()->table('change_requests')
            ->where('requested_by', $userId)->orderBy('id', 'DESC')->limit(100)->get()->getResultArray();

        return array_map(fn (array $r) => $this->decode($r), $rows);
    }

    /** @return list<array<string,mixed>> level decisions for a request */
    public function decisions(int $requestId): array
    {
        return Database::connect()->table('change_request_approvals a')
            ->select('a.*, u.name AS approver_name')
            ->join('users u', 'u.id = a.approver_user_id', 'left')
            ->where('a.change_request_id', $requestId)
            ->orderBy('a.id')->get()->getResultArray();
    }

    private function decode(array $row): array
    {
        foreach (self::JSON_COLS as $c) {
            if (isset($row[$c]) && is_string($row[$c])) {
                $row[$c] = json_decode($row[$c], true);
            }
        }

        return $row;
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0F | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
