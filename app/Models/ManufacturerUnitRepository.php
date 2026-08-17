<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * ManufacturerUnitRepository — CRUD for `mshops` (manufacturing units/factories).
 *
 * Every read and write is scoped by vendor_id, and findById() returns null when the
 * unit belongs to someone else, so a wrong id reads as "not found" rather than leaking.
 *
 * `mshops` deliberately has NO delivery columns — no delivery_radius_km, no polygon, no
 * pickup_enabled, no prep_time_min. A manufacturer cannot set a delivery range because
 * there is nowhere to put one. That is requirement 2 enforced by schema rather than by
 * a validation rule someone can later forget.
 *
 * @see App\Models\VendorShopRepository — the vendor counterpart (which does carry them)
 */
final class ManufacturerUnitRepository
{
    /** @return list<array<string,mixed>> */
    public function list(int $manufacturerId): array
    {
        if ($manufacturerId <= 0) {
            return [];
        }

        return Database::connect()->table('mshops')
            ->select('id, name, code, pincode, state_code, gstin, gstin_status, status, created_at')
            ->where('vendor_id', $manufacturerId)
            ->where('deleted_at', null)
            ->orderBy('name')
            ->limit(200)
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null Null when the unit is not this manufacturer's. */
    public function findById(int $id, int $manufacturerId): ?array
    {
        if ($id <= 0 || $manufacturerId <= 0) {
            return null;
        }

        return Database::connect()->table('mshops')
            ->where('id', $id)
            ->where('vendor_id', $manufacturerId)
            ->where('deleted_at', null)
            ->get()->getRowArray();
    }

    /** @param array<string,mixed> $d */
    public function create(int $manufacturerId, array $d, ?int $actorId = null): ?int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($manufacturerId <= 0 || $name === '') {
            return null;
        }

        $db   = Database::connect();
        $code = $this->uniqueCode($db, $manufacturerId, $name);

        $db->table('mshops')->insert([
            'uuid'         => $this->uuid(),
            'vendor_id'    => $manufacturerId,
            'name'         => $name,
            'code'         => $code,
            'gstin'        => ($g = trim((string) ($d['gstin'] ?? ''))) !== '' ? strtoupper($g) : null,
            'address_json' => json_encode([
                'line1' => trim((string) ($d['address'] ?? '')),
                'area'  => trim((string) ($d['area'] ?? '')),
                'city'  => trim((string) ($d['city'] ?? '')),
                'state' => trim((string) ($d['state_code'] ?? '')),
            ], JSON_UNESCAPED_UNICODE),
            'pincode'    => trim((string) ($d['pincode'] ?? '')),
            'state_code' => strtoupper(trim((string) ($d['state_code'] ?? ''))),
            'latitude'   => (float) ($d['latitude'] ?? 0),
            'longitude'  => (float) ($d['longitude'] ?? 0),
            'status'     => 'active',
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        return (int) $db->insertID() ?: null;
    }

    /** @param array<string,mixed> $d */
    public function update(int $id, int $manufacturerId, array $d, ?int $actorId = null): bool
    {
        if ($this->findById($id, $manufacturerId) === null) {
            return false;
        }

        $set = ['updated_by' => $actorId];
        foreach (['name', 'pincode'] as $k) {
            if (isset($d[$k]) && trim((string) $d[$k]) !== '') {
                $set[$k] = trim((string) $d[$k]);
            }
        }
        if (isset($d['state_code']) && trim((string) $d['state_code']) !== '') {
            $set['state_code'] = strtoupper(trim((string) $d['state_code']));
        }
        foreach (['latitude', 'longitude'] as $k) {
            if (isset($d[$k]) && $d[$k] !== '') {
                $set[$k] = (float) $d[$k];
            }
        }
        if (isset($d['address']) || isset($d['city'])) {
            $set['address_json'] = json_encode([
                'line1' => trim((string) ($d['address'] ?? '')),
                'area'  => trim((string) ($d['area'] ?? '')),
                'city'  => trim((string) ($d['city'] ?? '')),
                'state' => trim((string) ($d['state_code'] ?? '')),
            ], JSON_UNESCAPED_UNICODE);
        }

        // Serviceability (75_manufacturer_delivery.sql). Written only when the caller
        // passes `serviceability` — the plain unit form does not, so an edit that never
        // touched these fields cannot blank them. The controller sets that flag only
        // for a request that actually carries the serviceability section AND holds
        // mfg.unit.serviceability.
        if (! empty($d['serviceability'])) {
            $set['delivery_enabled'] = ! empty($d['delivery_enabled']) ? 1 : 0;
            $set['pickup_enabled']   = ! empty($d['pickup_enabled']) ? 1 : 0;

            foreach (['delivery_radius_km', 'min_order_value', 'delivery_fee', 'free_delivery_above'] as $k) {
                $set[$k] = isset($d[$k]) && $d[$k] !== '' ? (float) $d[$k] : null;
            }
            $set['prep_time_min'] = isset($d['prep_time_min']) && $d['prep_time_min'] !== ''
                ? max(0, (int) $d['prep_time_min'])
                : null;
        }

        Database::connect()->table('mshops')
            ->where('id', $id)->where('vendor_id', $manufacturerId)
            ->update($set);

        return true;
    }

    public function setStatus(int $id, int $manufacturerId, string $status, ?int $actorId = null): bool
    {
        if (! in_array($status, ['active', 'inactive', 'closed_temp'], true)) {
            return false;
        }
        if ($this->findById($id, $manufacturerId) === null) {
            return false;
        }

        Database::connect()->table('mshops')
            ->where('id', $id)->where('vendor_id', $manufacturerId)
            ->update(['status' => $status, 'updated_by' => $actorId]);

        return true;
    }

    /** Unique per manufacturer — `uq_mshops_vendor_code` would otherwise reject the insert. */
    private function uniqueCode(object $db, int $manufacturerId, string $name): string
    {
        $stem = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $name) ?: 'UNIT', 0, 6)) ?: 'UNIT';
        for ($i = 1; $i < 1000; $i++) {
            $code  = $stem . '-' . $i;
            $taken = $db->table('mshops')->where('vendor_id', $manufacturerId)->where('code', $code)->countAllResults();
            if ($taken === 0) {
                return $code;
            }
        }

        return $stem . '-' . bin2hex(random_bytes(3));
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0F) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
