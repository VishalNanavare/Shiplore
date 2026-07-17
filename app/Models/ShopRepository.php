<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * ShopRepository — admin-side shop listing (across all vendors) + status
 * transitions (activate / deactivate). Joins the owning vendor for display.
 */
final class ShopRepository
{
    /**
     * Paginated shop list. limit=0 means no limit (used by export callers).
     * @param array<string,mixed> $f keys: status, q, limit, offset
     * @return list<array<string,mixed>>
     */
    public function list(array|string|null $f = null, int $limit = 50, int $offset = 0): array
    {
        // Back-compat: callers that pass a bare status string.
        if (! is_array($f)) {
            $f = ['status' => $f];
        }
        $b = $this->baseQuery();
        $this->applyFilters($b, $f);
        $l = (int) ($f['limit'] ?? $limit);
        if ($l > 0) {
            $b->limit($l, (int) ($f['offset'] ?? $offset));
        }

        return $b->get()->getResultArray();
    }

    /** @param array<string,mixed> $f */
    public function countList(array $f = []): int
    {
        $b = $this->baseQuery();
        $this->applyFilters($b, $f);

        return $b->countAllResults();
    }

    private function baseQuery(): object
    {
        return Database::connect()->table('shops s')
            ->select('s.id, s.name, s.code, s.pincode, s.state_code, s.gstin_status, s.status, s.created_at, v.display_name AS vendor')
            ->join('vendors v', 'v.id = s.vendor_id', 'left')
            ->where('s.deleted_at', null)
            ->orderBy('s.created_at', 'DESC');
    }

    /** @param array<string,mixed> $f */
    private function applyFilters(object $b, array $f): void
    {
        if (! empty($f['status'])) {
            $b->where('s.status', $f['status']);
        }
        if (isset($f['q']) && $f['q'] !== '') {
            $q = $f['q'];
            $b->groupStart()->like('s.name', $q)->orLike('s.code', $q)->orLike('s.pincode', $q)->orLike('v.display_name', $q)->groupEnd();
        }
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('shops')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        $ok = Database::connect()->table('shops')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
        if ($ok) {
            service('facetCache')->invalidate(); // delivery zone changed → refresh counts async
        }

        return $ok;
    }

    /** @return list<array<string,mixed>> Vendors for the shop-create select. */
    public function vendorsForSelect(): array
    {
        return Database::connect()->table('vendors')
            ->select('id, display_name')->where('deleted_at', null)
            ->orderBy('display_name', 'ASC')->get()->getResultArray();
    }

    /** @param array<string,mixed> $d @return int|null new shop id */
    public function create(array $d, ?int $actorId = null): ?int
    {
        $db = Database::connect();
        $db->table('shops')->insert([
            'uuid'         => bin2hex(random_bytes(18)),
            'vendor_id'    => (int) $d['vendor_id'],
            'name'         => mb_substr((string) $d['name'], 0, 191),
            'code'         => mb_substr((string) $d['code'], 0, 40),
            'gstin'        => $d['gstin'] ?: null,
            'address_json' => json_encode(['line1' => $d['address'], 'area' => $d['area'], 'city' => $d['city'], 'state' => $d['state_code']]),
            'pincode'      => mb_substr((string) $d['pincode'], 0, 10),
            'state_code'   => mb_substr((string) $d['state_code'], 0, 2),
            'latitude'     => $d['latitude'],
            'longitude'    => $d['longitude'],
            'delivery_radius_km' => $d['delivery_radius_km'] !== '' ? $d['delivery_radius_km'] : null,
            'prep_time_min'      => $d['prep_time_min'] !== '' ? (int) $d['prep_time_min'] : null,
            'pickup_enabled'     => ! empty($d['pickup_enabled']) ? 1 : 0,
            'status'       => 'active',
            'created_by'   => $actorId,
        ]);

        return ($id = (int) $db->insertID()) > 0 ? $id : null;
    }

    /** @param array<string,mixed> $d */
    public function update(int $id, array $d, ?int $actorId = null): bool
    {
        return Database::connect()->table('shops')->where('id', $id)->where('deleted_at', null)->update([
            'name'         => mb_substr((string) $d['name'], 0, 191),
            'address_json' => json_encode(['line1' => $d['address'], 'area' => $d['area'], 'city' => $d['city'], 'state' => $d['state_code']]),
            'pincode'      => mb_substr((string) $d['pincode'], 0, 10),
            'state_code'   => mb_substr((string) $d['state_code'], 0, 2),
            'latitude'     => $d['latitude'],
            'longitude'    => $d['longitude'],
            'delivery_radius_km' => $d['delivery_radius_km'] !== '' ? $d['delivery_radius_km'] : null,
            'prep_time_min'      => $d['prep_time_min'] !== '' ? (int) $d['prep_time_min'] : null,
            'pickup_enabled'     => ! empty($d['pickup_enabled']) ? 1 : 0,
            'updated_by'   => $actorId,
        ]);
    }
}
