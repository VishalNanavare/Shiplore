<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * WarehouseRepository — admin oversight of vendor warehouses (future expansion).
 * Lists warehouses with owning vendor and toggles active/inactive. Fail-safe.
 *
 * @see docs/architecture/32-OPS-EXPANSION-SEO-DR.md
 */
final class WarehouseRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('warehouses w')
            ->select('w.id, w.name, w.code, w.pincode, w.state_code, w.status, v.display_name AS vendor')
            ->join('vendors v', 'v.id = w.vendor_id', 'left')
            ->where('w.deleted_at', null)
            ->orderBy('w.name', 'ASC');

        if ($status !== null && $status !== '') {
            $builder->where('w.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('warehouses')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('warehouses')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }

    /** @return list<array<string,mixed>> */
    public function vendorsForSelect(): array
    {
        return Database::connect()->table('vendors')->select('id, display_name')
            ->where('deleted_at', null)->orderBy('display_name')->get()->getResultArray();
    }

    /** @param array<string,mixed> $d @return int|null */
    public function create(array $d, ?int $actorId = null): ?int
    {
        $db = Database::connect();
        $db->table('warehouses')->insert([
            'uuid' => bin2hex(random_bytes(18)), 'vendor_id' => (int) $d['vendor_id'],
            'name' => mb_substr((string) $d['name'], 0, 191), 'code' => mb_substr((string) $d['code'], 0, 40),
            'address_json' => json_encode(['line1' => $d['address'] ?? '', 'city' => $d['city'] ?? '']),
            'pincode' => $d['pincode'] ?: null, 'state_code' => $d['state_code'] ?: null,
            'latitude' => $d['latitude'] ?: null, 'longitude' => $d['longitude'] ?: null,
            'status' => 'active', 'created_by' => $actorId,
        ]);

        return ($id = (int) $db->insertID()) > 0 ? $id : null;
    }

    /** @param array<string,mixed> $d */
    public function update(int $id, array $d, ?int $actorId = null): bool
    {
        return Database::connect()->table('warehouses')->where('id', $id)->where('deleted_at', null)->update([
            'name' => mb_substr((string) $d['name'], 0, 191),
            'address_json' => json_encode(['line1' => $d['address'] ?? '', 'city' => $d['city'] ?? '']),
            'pincode' => $d['pincode'] ?: null, 'state_code' => $d['state_code'] ?: null,
            'latitude' => $d['latitude'] ?: null, 'longitude' => $d['longitude'] ?: null, 'updated_by' => $actorId,
        ]);
    }
}
