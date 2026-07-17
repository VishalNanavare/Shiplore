<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * RegistrationRepository — uniqueness guards (mobile / email / GSTIN must not
 * already exist) and the atomic creation of a verified vendor + its first shop
 * + the vendor_owner role assignment.
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md
 */
final class RegistrationRepository
{
    private const VENDOR_OWNER_ROLE = 10;

    public function mobileExists(string $phone): bool
    {
        return (bool) Database::connect()->table('users')->where('phone', $phone)->where('deleted_at', null)->countAllResults();
    }

    public function emailExists(string $email): bool
    {
        return (bool) Database::connect()->table('users')->where('email', $email)->where('deleted_at', null)->countAllResults();
    }

    public function gstinExists(string $gstin): bool
    {
        $db = Database::connect();

        return (bool) $db->table('vendors')->where('gstin', $gstin)->where('deleted_at', null)->countAllResults()
            || (bool) $db->table('shops')->where('gstin', $gstin)->where('deleted_at', null)->countAllResults();
    }

    /**
     * Create user + vendor + shop + role in one transaction.
     * @param array<string,mixed> $d
     * @return int|null new vendor id, or null on failure
     */
    public function createVendorWithShop(array $d): ?int
    {
        $db = Database::connect();
        $db->transBegin();

        try {
            $now  = date('Y-m-d H:i:s');
            $slug = $this->slugify($d['display_name']);

            $db->table('users')->insert([
                'uuid'              => bin2hex(random_bytes(18)),
                'principal_type'    => 'vendor',
                'name'              => $d['display_name'],
                'email'             => $d['email'],
                'phone'             => $d['mobile'],
                'email_verified_at' => $now,
                'phone_verified_at' => $now,
                'password_hash'     => password_hash($d['password'], PASSWORD_BCRYPT),
                'status'            => 'active',
            ]);
            $userId = (int) $db->insertID();

            $db->table('vendors')->insert([
                'uuid'             => bin2hex(random_bytes(18)),
                'owner_user_id'    => $userId,
                'business_type_id' => $d['business_type_id'],
                'legal_name'       => $d['legal_name'],
                'display_name'     => $d['display_name'],
                'slug'             => $slug,
                'gstin'            => $d['gstin'],
                'gstin_status'     => 'verified',
                'status'           => 'submitted',
                'created_by'       => $userId,
            ]);
            $vendorId = (int) $db->insertID();

            $db->table('shops')->insert([
                'uuid'         => bin2hex(random_bytes(18)),
                'vendor_id'    => $vendorId,
                'name'         => $d['shop_name'],
                'code'         => strtoupper(substr($slug, 0, 6)) . '-1',
                'gstin'        => $d['gstin'],
                'gstin_status' => 'verified',
                'address_json' => json_encode([
                    'line1' => $d['address'], 'area' => $d['area'], 'city' => $d['city'], 'state' => $d['state_code'],
                ]),
                'pincode'      => $d['pincode'],
                'state_code'   => $d['state_code'],
                'latitude'     => $d['latitude'],
                'longitude'    => $d['longitude'],
                'delivery_radius_km' => ($rad = (float) ($d['delivery_radius'] ?? 0)) > 0 ? $rad : null,
                'status'       => 'active',
                'created_by'   => $userId,
            ]);

            $db->table('user_roles')->insert([
                'user_id'    => $userId,
                'role_id'    => self::VENDOR_OWNER_ROLE,
                'scope_type' => 'vendor',
                'scope_id'   => $vendorId,
            ]);

            $db->transComplete();

            return $db->transStatus() ? $vendorId : null;
        } catch (Throwable) {
            $db->transRollback();

            return null;
        }
    }

    private function slugify(string $name): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');

        return ($slug === '' ? 'vendor' : $slug) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }
}
