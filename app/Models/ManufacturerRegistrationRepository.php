<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * ManufacturerRegistrationRepository — the atomic write for manufacturer self-registration.
 *
 * Mirrors RegistrationRepository::createVendorWithShop() with three differences, all of
 * them requirements rather than incidental:
 *
 *   1. users.principal_type  => 'manufacturer'   (not 'vendor')
 *   2. vendors.party_type    => 'manufacturer'
 *   3. the first location is an `mshops` row, NOT a `shops` row — and mshops has no
 *      delivery_radius_km column at all, so no delivery range is written or writable.
 *
 * The uniqueness pre-checks are shared with the vendor flow on purpose: `users.email`,
 * `users.phone` and `vendors.gstin` are unique ACROSS both party types, so a manufacturer
 * must not be able to claim an identifier already used by a vendor (or vice versa).
 *
 * @see App\Models\RegistrationRepository — the vendor counterpart
 */
final class ManufacturerRegistrationRepository
{
    /** roles.id 22, seeded by database/sql/70_manufacturer.sql. */
    public const MANUFACTURER_OWNER_ROLE = 22;

    public function mobileExists(string $phone): bool
    {
        return Database::connect()->table('users')->where('phone', $phone)->countAllResults() > 0;
    }

    public function emailExists(string $email): bool
    {
        return Database::connect()->table('users')->where('email', $email)->countAllResults() > 0;
    }

    /** GSTIN is unique across vendors AND their locations, both kinds. */
    public function gstinExists(string $gstin): bool
    {
        $db = Database::connect();
        if ($db->table('vendors')->where('gstin', $gstin)->countAllResults() > 0) {
            return true;
        }
        if ($db->table('shops')->where('gstin', $gstin)->countAllResults() > 0) {
            return true;
        }

        return $db->table('mshops')->where('gstin', $gstin)->countAllResults() > 0;
    }

    /**
     * Create user + manufacturer + first unit + owner role in one transaction.
     *
     * @param array<string,mixed> $d
     * @return int|null the new manufacturer id, or null when the write failed
     */
    public function createManufacturerWithUnit(array $d): ?int
    {
        $db = Database::connect();
        $db->transBegin();

        try {
            $now  = date('Y-m-d H:i:s');
            $slug = $this->slugify((string) $d['display_name']);

            $db->table('users')->insert([
                'uuid'              => bin2hex(random_bytes(18)),
                'principal_type'    => 'manufacturer',
                'name'              => $d['display_name'],
                'email'             => $d['email'],
                'phone'             => $d['mobile'],
                'email_verified_at' => $now,
                'phone_verified_at' => $now,
                'password_hash'     => password_hash((string) $d['password'], PASSWORD_BCRYPT),
                'status'            => 'active',
            ]);
            $userId = (int) $db->insertID();

            $db->table('vendors')->insert([
                'uuid'             => bin2hex(random_bytes(18)),
                'owner_user_id'    => $userId,
                'party_type'       => 'manufacturer',
                'business_type_id' => $d['business_type_id'],
                'legal_name'       => $d['legal_name'],
                'display_name'     => $d['display_name'],
                'slug'             => $slug,
                'gstin'            => $d['gstin'],
                'gstin_status'     => 'verified',
                'status'           => 'submitted',
                'created_by'       => $userId,
            ]);
            $manufacturerId = (int) $db->insertID();

            // The first unit. Note the absence of delivery_radius_km — mshops has no
            // such column, which is how "a manufacturer cannot set a delivery range"
            // is enforced structurally rather than by validation.
            $db->table('mshops')->insert([
                'uuid'         => bin2hex(random_bytes(18)),
                'vendor_id'    => $manufacturerId,
                'name'         => $d['unit_name'],
                'code'         => strtoupper(substr($slug, 0, 6)) . '-1',
                'gstin'        => $d['gstin'],
                'gstin_status' => 'verified',
                'address_json' => json_encode([
                    'line1' => $d['address'], 'area' => $d['area'], 'city' => $d['city'], 'state' => $d['state_code'],
                ]),
                'pincode'    => $d['pincode'],
                'state_code' => $d['state_code'],
                'latitude'   => $d['latitude'],
                'longitude'  => $d['longitude'],
                'status'     => 'active',
                'created_by' => $userId,
            ]);

            $db->table('user_roles')->insert([
                'user_id'    => $userId,
                'role_id'    => self::MANUFACTURER_OWNER_ROLE,
                'scope_type' => 'manufacturer',
                'scope_id'   => $manufacturerId,
            ]);

            $db->transComplete();

            return $db->transStatus() ? $manufacturerId : null;
        } catch (Throwable) {
            $db->transRollback();

            return null;
        }
    }

    private function slugify(string $s): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)) ?? '', '-');

        return $slug !== '' ? $slug : 'manufacturer-' . bin2hex(random_bytes(3));
    }
}
