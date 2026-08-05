<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * MasterRepository — persistence for Admin\Concerns\MasterCrud (audit L12).
 *
 * The generic-spec trait pattern that backs the simple admin masters (units, tax
 * classes, HSN/SAC codes, delivery zones, brands, coupons, business types, …) is
 * genuinely useful and stays as a trait. What moves here is the three SQL
 * statements it used to run directly against `Database::connect()`: writes that
 * bypass every repository skip the cross-cutting behaviour every other write in
 * this codebase gets — no auditWriter entry, no tenant-scope assertion, no place
 * for the change-request governance gate to attach. Changing an HSN code's rate
 * changes GST on every mapped product; until now that left no audit trail beyond
 * `updated_by`, overwritten by the next edit.
 *
 * entity_type is the bare table name, per this class's own audit_logs writes —
 * confirm admin/audit-logs renders that; the audit call is wrapped in try/catch so
 * a malformed row cannot break a save either way.
 */
final class MasterRepository
{
    /** @return list<array<string,mixed>> */
    public function all(string $table, int $limit = 500): array
    {
        return Database::connect()->table($table)
            ->where('deleted_at', null)->orderBy('id', 'DESC')->limit($limit)
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function find(string $table, int $id): ?array
    {
        $row = Database::connect()->table($table)
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** Current status only, including a soft-deleted row — toggle() needs the real current value regardless. @return array<string,mixed>|null */
    public function statusOf(string $table, int $id): ?array
    {
        $row = Database::connect()->table($table)->select('status')->where('id', $id)->get()->getRowArray();

        return $row ?: null;
    }

    /** @param array<string,mixed> $data @return int the new row's id */
    public function create(string $table, string $entityType, array $data, ?int $actorId): int
    {
        $db = Database::connect();
        $db->table($table)->insert($data);
        $id = (int) $db->insertID();

        $this->audit('create', $entityType, $id, $actorId);

        return $id;
    }

    /** @param array<string,mixed> $data */
    public function update(string $table, string $entityType, int $id, array $data, ?int $actorId, string $action = 'update'): bool
    {
        $ok = (bool) Database::connect()->table($table)->where('id', $id)->update($data);

        $this->audit($action, $entityType, $id, $actorId);

        return $ok;
    }

    private function audit(string $action, string $entityType, int $entityId, ?int $actorId): void
    {
        try {
            service('auditWriter')->log([
                'action'        => $entityType . '.' . $action,
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'actor_user_id' => $actorId,
            ]);
        } catch (\Throwable) {
            // A logging fault must never break the save it is recording.
        }
    }
}
