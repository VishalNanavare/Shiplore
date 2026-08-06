<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * VendorAccountRepository — resolves the vendor that a logged-in user belongs to.
 * This is the anchor of vendor-panel tenant isolation: the panel derives the
 * acting vendor_id from the session user, never from request input. A user may
 * belong as the OWNER or as an active vendor_staff member (branch manager,
 * cashier, …) — both reach the vendor panel, but staff are shop-scoped.
 */
final class VendorAccountRepository
{
    /**
     * Vendors and manufacturers share the `vendors` table, distinguished by
     * `party_type`. ManufacturerAccountRepository already constrains every lookup
     * with `->where('party_type', self::PARTY_TYPE)`, stated to stop the panels
     * resolving each other's tenants "and vice versa" — but this repository had no
     * mirror constraint, so the guard was one-directional: a manufacturer owner
     * reaching /vendor/* got their own `vendors` row back and requireVendor()
     * passed (audit M11).
     *
     * PRE-DEPLOY CHECK (cannot be run from here — no DB access): confirm no
     * existing row has a NULL or unexpected party_type before this ships, or that
     * vendor is silently locked out of their own panel and mobile app —
     * `SELECT party_type, COUNT(*) FROM vendors WHERE deleted_at IS NULL GROUP BY party_type;`
     * must show only 'vendor' and 'manufacturer', no NULLs.
     */
    private const PARTY_TYPE = 'vendor';

    /** @return array<string,mixed>|null the vendor a user owns */
    public function findByOwnerUserId(int $userId): ?array
    {
        $row = Database::connect()->table('vendors')
            ->select('id, display_name, legal_name, slug, gstin, gstin_status, status, business_type_id')
            ->where('owner_user_id', $userId)
            ->where('party_type', self::PARTY_TYPE)
            ->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * Resolve the vendor for an active STAFF member (not an owner). Adds
     * `vendor_staff_id` so callers can scope the staff to their shops. The
     * owner path stays on findByOwnerUserId(); BaseVendorController tries that
     * first and only falls back here. @return array<string,mixed>|null
     */
    public function findStaffVendor(int $userId): ?array
    {
        $row = Database::connect()->table('vendor_staff vs')
            ->select('v.id, v.display_name, v.legal_name, v.slug, v.gstin, v.gstin_status, v.status, v.business_type_id, vs.id AS vendor_staff_id, vs.staff_type')
            ->join('vendors v', 'v.id = vs.vendor_id', 'left')
            ->where('vs.user_id', $userId)->where('vs.status', 'active')->where('vs.deleted_at', null)
            ->where('v.deleted_at', null)
            // Mirrors findByOwnerUserId(): also (correctly) drops any staff row whose
            // vendor was somehow a manufacturer — an orphaned assignment, not a
            // legitimate vendor staffer.
            ->where('v.party_type', self::PARTY_TYPE)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $row['vendor_staff_id'] = (int) $row['vendor_staff_id'];

        return $row;
    }

    /** @return list<int> all active shop ids of a vendor (the owner sees them all) */
    public function shopIdsForVendor(int $vendorId): array
    {
        return array_map('intval', array_column(
            Database::connect()->table('shops')->select('id')
                ->where('vendor_id', $vendorId)->where('deleted_at', null)
                ->get()->getResultArray(),
            'id',
        ));
    }

    /** @return list<int> shop ids a staff member is actively assigned to */
    public function shopIdsForStaff(int $vendorStaffId): array
    {
        return array_map('intval', array_column(
            Database::connect()->table('staff_shop_assignments')->select('shop_id')
                ->where('vendor_staff_id', $vendorStaffId)->where('status', 'active')->where('deleted_at', null)
                ->get()->getResultArray(),
            'shop_id',
        ));
    }
}
