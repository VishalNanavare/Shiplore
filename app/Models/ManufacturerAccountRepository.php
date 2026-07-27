<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * ManufacturerAccountRepository — the anchor of manufacturer-panel tenant isolation,
 * mirroring VendorAccountRepository for the manufacturer side.
 *
 * Every lookup here is constrained by `vendors.party_type = 'manufacturer'`. That
 * constraint is what stops the manufacturer panel resolving an ordinary vendor (and
 * vice versa) when both share the `vendors` table: without it, a vendor owner whose
 * session reached /manufacturer/* would resolve a real tenant and be let in.
 *
 * Manufacturer staff are `vendor_staff` rows (the table is reused), but their unit
 * assignments live in `mfg_staff_assignments` against `mshops`, not
 * `staff_shop_assignments` against `shops`.
 *
 * @see App\Models\VendorAccountRepository — the vendor counterpart
 */
final class ManufacturerAccountRepository
{
    public const PARTY_TYPE = 'manufacturer';

    /** The manufacturer owned by this user, or null. @return array<string,mixed>|null */
    public function findByOwnerUserId(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        return Database::connect()->table('vendors')
            ->select('id, display_name, legal_name, slug, gstin, gstin_status, status, business_type_id')
            ->where('owner_user_id', $userId)
            ->where('party_type', self::PARTY_TYPE)
            ->where('deleted_at', null)
            ->get()->getRowArray();
    }

    /** The manufacturer this user is active staff of, or null. @return array<string,mixed>|null */
    public function findStaffManufacturer(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        return Database::connect()->table('vendor_staff vs')
            ->select('v.id, v.display_name, v.legal_name, v.slug, v.gstin, v.gstin_status, v.status, v.business_type_id, vs.id AS vendor_staff_id, vs.staff_type')
            ->join('vendors v', 'v.id = vs.vendor_id')
            ->where('vs.user_id', $userId)
            ->where('vs.status', 'active')
            ->where('vs.deleted_at', null)
            ->where('v.party_type', self::PARTY_TYPE)
            ->where('v.deleted_at', null)
            ->get()->getRowArray();
    }

    /** Every unit of this manufacturer. @return list<int> */
    public function mshopIdsForManufacturer(int $manufacturerId): array
    {
        if ($manufacturerId <= 0) {
            return [];
        }
        $rows = Database::connect()->table('mshops')
            ->select('id')
            ->where('vendor_id', $manufacturerId)
            ->where('deleted_at', null)
            ->get()->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /** Only the units this staff member is assigned to. @return list<int> */
    public function mshopIdsForStaff(int $vendorStaffId): array
    {
        if ($vendorStaffId <= 0) {
            return [];
        }
        $rows = Database::connect()->table('mfg_staff_assignments')
            ->select('mshop_id')
            ->where('vendor_staff_id', $vendorStaffId)
            ->where('status', 'active')
            ->where('deleted_at', null)
            ->get()->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['mshop_id'], $rows);
    }
}
