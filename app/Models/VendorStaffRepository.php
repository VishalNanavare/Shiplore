<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * VendorStaffRepository — a vendor's own staff and delivery riders. Scoped by
 * vendor_id (a vendor manages only its own team).
 */
final class VendorStaffRepository
{
    /** staff_type -> the shop-scoped role granted on login. */
    private const ROLE_FOR_TYPE = [
        'branch_manager' => 'vendor_shop_manager',
        'cashier'        => 'vendor_pos_cashier',
        'packer'         => 'vendor_packer',
        'helper'         => 'vendor_helper',
    ];

    /** @return list<array<string,mixed>> */
    public function staff(int $vendorId): array
    {
        return Database::connect()->table('vendor_staff vs')
            ->select('vs.id, vs.staff_type, vs.employee_code, vs.designation, vs.status, u.name, u.email, u.phone')
            ->join('users u', 'u.id = vs.user_id', 'left')
            ->where('vs.vendor_id', $vendorId)->where('vs.deleted_at', null)
            ->orderBy('vs.id', 'ASC')
            ->get()->getResultArray();
    }

    /** Staff list with their assigned shop names (for the management table). @return list<array<string,mixed>> */
    public function staffWithShops(int $vendorId): array
    {
        return Database::connect()->table('vendor_staff vs')
            ->select('vs.id, vs.user_id, vs.staff_type, vs.employee_code, vs.designation, vs.status, u.name, u.email, u.phone, GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ", ") AS shops')
            ->join('users u', 'u.id = vs.user_id', 'left')
            ->join('staff_shop_assignments ssa', "ssa.vendor_staff_id = vs.id AND ssa.status = 'active' AND ssa.deleted_at IS NULL", 'left')
            ->join('shops s', 's.id = ssa.shop_id', 'left')
            ->where('vs.vendor_id', $vendorId)->where('vs.deleted_at', null)
            ->groupBy('vs.id')->orderBy('vs.id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null one staff member (vendor-scoped) for editing */
    public function findStaff(int $staffId, int $vendorId): ?array
    {
        $row = Database::connect()->table('vendor_staff vs')
            ->select('vs.id, vs.user_id, vs.staff_type, vs.employee_code, vs.designation, vs.status, u.name, u.email, u.phone')
            ->join('users u', 'u.id = vs.user_id', 'left')
            ->where('vs.id', $staffId)->where('vs.vendor_id', $vendorId)->where('vs.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** Active staff assigned to one shop (for the shop console). @return list<array<string,mixed>> */
    public function staffForShop(int $shopId, int $vendorId): array
    {
        return Database::connect()->table('vendor_staff vs')
            ->select('vs.id, vs.staff_type, vs.designation, vs.status, u.name, u.email, u.phone, ssa.is_primary')
            ->join('staff_shop_assignments ssa', 'ssa.vendor_staff_id = vs.id AND ssa.shop_id = ' . (int) $shopId . " AND ssa.status = 'active' AND ssa.deleted_at IS NULL", 'inner')
            ->join('users u', 'u.id = vs.user_id', 'left')
            ->where('vs.vendor_id', $vendorId)->where('vs.deleted_at', null)
            ->groupBy('vs.id')
            ->orderBy('ssa.is_primary', 'DESC')->orderBy('u.name', 'ASC')
            ->get()->getResultArray();
    }

    /** @return list<int> shop ids the staff member is actively assigned to */
    public function staffShops(int $staffId): array
    {
        return array_map('intval', array_column(
            Database::connect()->table('staff_shop_assignments')->select('shop_id')
                ->where('vendor_staff_id', $staffId)->where('status', 'active')->where('deleted_at', null)
                ->get()->getResultArray(),
            'shop_id',
        ));
    }

    public function emailExists(string $email, ?int $exceptUserId = null): bool
    {
        $b = Database::connect()->table('users')->where('email', $email)->where('deleted_at', null);
        if ($exceptUserId !== null) {
            $b->where('id !=', $exceptUserId);
        }

        return (bool) $b->countAllResults();
    }

    /**
     * Create a staff member with a panel login, shop assignment(s) and a
     * shop-scoped role — all in one transaction.
     * @param array<string,mixed> $d name, email, password, phone, staff_type,
     *        shop_ids (int[]), primary_shop (int), employee_code, designation
     * @return int|null new vendor_staff id
     */
    public function createStaff(int $vendorId, array $d, ?int $actorId = null): ?int
    {
        $db = Database::connect();
        $db->transBegin();
        try {
            $db->table('users')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'principal_type' => 'vendor',
                'name' => mb_substr((string) $d['name'], 0, 191), 'email' => ($d['email'] ?? '') ?: null, 'phone' => ($d['phone'] ?? '') ?: null,
                'password_hash' => ! empty($d['password']) ? password_hash((string) $d['password'], PASSWORD_BCRYPT) : null,
                'status' => 'active', 'created_by' => $actorId,
            ]);
            $userId = (int) $db->insertID();

            $type = array_key_exists((string) ($d['staff_type'] ?? ''), self::ROLE_FOR_TYPE) ? $d['staff_type'] : 'helper';
            $db->table('vendor_staff')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'user_id' => $userId, 'vendor_id' => $vendorId,
                'staff_type' => $type, 'employee_code' => ($d['employee_code'] ?? '') ?: null, 'designation' => ($d['designation'] ?? '') ?: null,
                'joined_at' => date('Y-m-d'), 'status' => 'active', 'created_by' => $actorId,
            ]);
            $staffId = (int) $db->insertID();

            $shopIds = array_values(array_unique(array_map('intval', (array) ($d['shop_ids'] ?? []))));
            $this->writeShops($db, $staffId, $shopIds, (int) ($d['primary_shop'] ?? 0), $actorId);
            $this->writeRole($db, $userId, self::ROLE_FOR_TYPE[$type], $shopIds, $actorId);

            $db->transComplete();

            return $db->transStatus() ? $staffId : null;
        } catch (Throwable) {
            $db->transRollback();

            return null;
        }
    }

    /** Update a staff member's profile, shop assignments and role (one transaction). */
    public function updateStaff(int $staffId, int $vendorId, array $d, ?int $actorId = null): bool
    {
        $staff = $this->findStaff($staffId, $vendorId);
        if ($staff === null) {
            return false;
        }
        $userId = (int) $staff['user_id'];
        $db     = Database::connect();
        $db->transBegin();
        try {
            $userPatch = ['name' => mb_substr((string) $d['name'], 0, 191), 'email' => ($d['email'] ?? '') ?: null, 'phone' => ($d['phone'] ?? '') ?: null, 'updated_by' => $actorId];
            if (! empty($d['password'])) {
                $userPatch['password_hash'] = password_hash((string) $d['password'], PASSWORD_BCRYPT);
            }
            $db->table('users')->where('id', $userId)->update($userPatch);

            $type = array_key_exists((string) ($d['staff_type'] ?? ''), self::ROLE_FOR_TYPE) ? $d['staff_type'] : $staff['staff_type'];
            $db->table('vendor_staff')->where('id', $staffId)->update([
                'staff_type' => $type, 'employee_code' => ($d['employee_code'] ?? '') ?: null, 'designation' => ($d['designation'] ?? '') ?: null, 'updated_by' => $actorId,
            ]);

            $shopIds = array_values(array_unique(array_map('intval', (array) ($d['shop_ids'] ?? []))));
            $this->writeShops($db, $staffId, $shopIds, (int) ($d['primary_shop'] ?? 0), $actorId);
            $this->writeRole($db, $userId, self::ROLE_FOR_TYPE[$type] ?? 'vendor_helper', $shopIds, $actorId);

            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }

    /** Suspend / reactivate a staff member (and their login). */
    public function setStatus(int $staffId, int $vendorId, string $status, ?int $actorId = null): bool
    {
        $staff = $this->findStaff($staffId, $vendorId);
        if ($staff === null || ! in_array($status, ['active', 'suspended'], true)) {
            return false;
        }
        $db = Database::connect();
        $db->table('vendor_staff')->where('id', $staffId)->update(['status' => $status, 'updated_by' => $actorId]);
        $db->table('users')->where('id', (int) $staff['user_id'])->update(['status' => $status === 'active' ? 'active' : 'suspended', 'updated_by' => $actorId]);

        return true;
    }

    /** Replace a staff member's shop assignments (hard-replace; one primary). */
    private function writeShops(object $db, int $staffId, array $shopIds, int $primaryShop, ?int $actorId): void
    {
        $db->table('staff_shop_assignments')->where('vendor_staff_id', $staffId)->delete();
        foreach ($shopIds as $sid) {
            $db->table('staff_shop_assignments')->insert([
                'vendor_staff_id' => $staffId, 'shop_id' => $sid,
                'is_primary' => $sid === $primaryShop ? 1 : 0, 'assigned_at' => date('Y-m-d H:i:s'),
                'status' => 'active', 'created_by' => $actorId,
            ]);
        }
    }

    /** Replace a staff member's shop-scoped role rows (one user_roles row per shop). */
    private function writeRole(object $db, int $userId, string $roleCode, array $shopIds, ?int $actorId): void
    {
        // clear ALL of the user's shop-scoped staff-role rows first, so a role change drops the old role
        $staffRoleIds = array_map('intval', array_column(
            $db->table('roles')->select('id')->whereIn('code', array_values(self::ROLE_FOR_TYPE))->get()->getResultArray(),
            'id',
        ));
        if ($staffRoleIds !== []) {
            $db->table('user_roles')->where('user_id', $userId)->where('scope_type', 'shop')->whereIn('role_id', $staffRoleIds)->delete();
        }
        $roleId = (int) ($db->table('roles')->select('id')->where('code', $roleCode)->get()->getRowArray()['id'] ?? 0);
        if ($roleId === 0) {
            return;
        }
        foreach ($shopIds as $sid) {
            $db->table('user_roles')->insert([
                'user_id' => $userId, 'role_id' => $roleId, 'scope_type' => 'shop', 'scope_id' => $sid, 'created_by' => $actorId,
            ]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function riders(int $vendorId): array
    {
        return Database::connect()->table('delivery_boys db')
            ->select('db.id, db.vehicle_type, db.vehicle_no, db.availability, db.status, u.name, u.phone')
            ->join('users u', 'u.id = db.user_id', 'left')
            ->where('db.vendor_id', $vendorId)->where('db.deleted_at', null)
            ->orderBy('db.id', 'ASC')
            ->get()->getResultArray();
    }

    public function phoneExists(string $phone): bool
    {
        return (bool) Database::connect()->table('users')->where('phone', $phone)->where('deleted_at', null)->countAllResults();
    }

    /**
     * Create a delivery rider (user + delivery_boy) for the vendor.
     * @param array<string,mixed> $d @return int|null new delivery_boy id
     */
    public function addRider(int $vendorId, array $d, ?int $actorId = null): ?int
    {
        $db = Database::connect();
        $db->transBegin();
        try {
            $db->table('users')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'principal_type' => 'rider',
                'name' => mb_substr((string) $d['name'], 0, 191), 'phone' => $d['phone'],
                'email' => $d['email'] ?: null,
                'password_hash' => ! empty($d['password']) ? password_hash((string) $d['password'], PASSWORD_BCRYPT) : null,
                'status' => 'active', 'created_by' => $actorId,
            ]);
            $userId = (int) $db->insertID();

            $db->table('delivery_boys')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'user_id' => $userId, 'vendor_id' => $vendorId,
                'vehicle_type' => in_array($d['vehicle_type'] ?? '', ['bike', 'scooter', 'bicycle', 'car', 'foot'], true) ? $d['vehicle_type'] : 'bike',
                'vehicle_no' => $d['vehicle_no'] ?: null, 'availability' => 'offline',
                'max_active_orders' => (int) ($d['max_active_orders'] ?? 5) ?: 5, 'status' => 'active', 'created_by' => $actorId,
            ]);
            $riderId = (int) $db->insertID();
            $db->transComplete();

            return $db->transStatus() ? $riderId : null;
        } catch (Throwable) {
            $db->transRollback();

            return null;
        }
    }
}
