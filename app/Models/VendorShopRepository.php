<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * VendorShopRepository — a vendor's own shops. Every read/write is scoped by
 * vendor_id so a vendor can never touch another vendor's shop (tenant
 * isolation). Edits shop details, weekly opening hours and holidays.
 */
final class VendorShopRepository
{
    /** @return list<array<string,mixed>> */
    public function list(int $vendorId): array
    {
        return Database::connect()->table('shops')
            ->select('id, name, code, pincode, state_code, delivery_radius_km, prep_time_min, pickup_enabled, gstin_status, status')
            ->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->orderBy('name', 'ASC')
            ->limit(200)
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null Scoped: returns null if the shop isn't this vendor's. */
    public function findById(int $id, int $vendorId): ?array
    {
        $row = Database::connect()->table('shops')
            ->where('id', $id)->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> Weekly hours for a shop (vendor-scoped via the shop). */
    public function hours(int $shopId): array
    {
        return Database::connect()->table('shop_hours')
            ->select('day_of_week, open_time, close_time, is_closed')
            ->where('shop_id', $shopId)
            ->orderBy('day_of_week', 'ASC')
            ->get()->getResultArray();
    }

    public function updateStatus(int $id, int $vendorId, string $status, ?int $actorId = null): bool
    {
        $ok = Database::connect()->table('shops')
            ->where('id', $id)->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
        if ($ok) {
            service('facetCache')->invalidate(); // delivery zone changed → refresh counts async
        }

        return $ok;
    }

    /** Create a new shop for the vendor. @param array<string,mixed> $d @return int|null new shop id */
    public function create(int $vendorId, array $d, ?int $actorId = null): ?int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $address = array_filter([
            'line1' => trim((string) ($d['address'] ?? '')),
            'area'  => trim((string) ($d['area'] ?? '')),
            'city'  => trim((string) ($d['city'] ?? '')),
        ], static fn ($v) => $v !== '');

        $slug = strtoupper(substr((string) (preg_replace('/[^A-Za-z0-9]+/', '', $name) ?: 'SHOP'), 0, 6));
        $db   = Database::connect();

        $ok = $db->table('shops')->insert([
            'uuid'               => bin2hex(random_bytes(18)),
            'vendor_id'          => $vendorId,
            'name'               => mb_substr($name, 0, 191),
            'code'               => $slug . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)),
            'address_json'       => json_encode($address !== [] ? $address : ['line1' => $name]),
            'pincode'            => mb_substr((string) ($d['pincode'] ?? ''), 0, 10) ?: '000000',
            'state_code'         => mb_substr((string) ($d['state_code'] ?? '') ?: '27', 0, 2),
            'latitude'           => ($d['latitude'] ?? '') !== '' ? $d['latitude'] : 0,
            'longitude'          => ($d['longitude'] ?? '') !== '' ? $d['longitude'] : 0,
            'delivery_radius_km' => ($d['delivery_radius_km'] ?? '') !== '' ? $d['delivery_radius_km'] : null,
            'status'             => 'active',
            'created_by'         => $actorId,
        ]);

        return $ok ? (int) $db->insertID() : null;
    }

    /** Vendor edits its own shop's details/timing/geo/address (scoped). @param array<string,mixed> $d */
    public function update(int $id, int $vendorId, array $d, ?int $actorId = null): bool
    {
        $address = array_filter([
            'line'  => trim((string) ($d['address_line'] ?? '')),
            'city'  => trim((string) ($d['city'] ?? '')),
            'phone' => trim((string) ($d['phone'] ?? '')),
        ], static fn ($v) => $v !== '');

        return Database::connect()->table('shops')
            ->where('id', $id)->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->update([
                'name' => mb_substr((string) $d['name'], 0, 191),
                'delivery_radius_km' => ($d['delivery_radius_km'] ?? '') !== '' ? $d['delivery_radius_km'] : null,
                'prep_time_min' => ($d['prep_time_min'] ?? '') !== '' ? (int) $d['prep_time_min'] : null,
                'pickup_enabled' => ! empty($d['pickup_enabled']) ? 1 : 0,
                'latitude' => ($d['latitude'] ?? '') !== '' ? $d['latitude'] : null,
                'longitude' => ($d['longitude'] ?? '') !== '' ? $d['longitude'] : null,
                'pincode' => ($d['pincode'] ?? '') !== '' ? mb_substr((string) $d['pincode'], 0, 10) : null,
                'state_code' => ($d['state_code'] ?? '') !== '' ? mb_substr((string) $d['state_code'], 0, 2) : null,
                'address_json' => $address !== [] ? json_encode($address) : null,
                'updated_by' => $actorId,
            ]);
    }

    /**
     * Replace a shop's weekly opening hours. $rows is keyed by day_of_week (0-6)
     * with ['open','close','is_closed']. Vendor-scoped via the shop.
     * @param array<int,array<string,mixed>> $rows
     */
    public function saveHours(int $shopId, int $vendorId, array $rows, ?int $actorId = null): bool
    {
        if ($this->findById($shopId, $vendorId) === null) {
            return false;
        }
        $db = Database::connect();
        $db->transBegin();
        try {
            $db->table('shop_hours')->where('shop_id', $shopId)->delete();
            for ($day = 0; $day <= 6; $day++) {
                $r      = $rows[$day] ?? [];
                $closed = ! empty($r['is_closed']);
                $db->table('shop_hours')->insert([
                    'shop_id' => $shopId, 'day_of_week' => $day,
                    'open_time' => $closed ? null : (($r['open'] ?? '') ?: null),
                    'close_time' => $closed ? null : (($r['close'] ?? '') ?: null),
                    'is_closed' => $closed ? 1 : 0, 'created_by' => $actorId,
                ]);
            }
            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }

    /** @return list<array<string,mixed>> upcoming holidays for a shop */
    public function holidays(int $shopId): array
    {
        return Database::connect()->table('shop_holidays')
            ->select('id, holiday_date, reason')->where('shop_id', $shopId)
            ->orderBy('holiday_date', 'ASC')->get()->getResultArray();
    }

    public function addHoliday(int $shopId, int $vendorId, string $date, string $reason, ?int $actorId = null): bool
    {
        if ($this->findById($shopId, $vendorId) === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        return (bool) Database::connect()->table('shop_holidays')->insert([
            'shop_id' => $shopId, 'holiday_date' => $date, 'reason' => mb_substr($reason, 0, 191) ?: null, 'created_by' => $actorId,
        ]);
    }

    public function deleteHoliday(int $holidayId, int $shopId, int $vendorId): bool
    {
        if ($this->findById($shopId, $vendorId) === null) {
            return false;
        }

        return (bool) Database::connect()->table('shop_holidays')->where('id', $holidayId)->where('shop_id', $shopId)->delete();
    }
}
