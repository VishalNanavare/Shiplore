<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * ManufacturerStaffRepository — a manufacturer's own staff, scoped by vendor_id.
 *
 * Forked from VendorStaffRepository rather than widening it, following the pattern
 * already set by ManufacturerAccountRepository / ManufacturerUnitRepository /
 * ManufacturerProductRepository. Widening the vendor repository would drop the
 * party_type gate that lives inside it, and the two differ in three concrete places
 * anyway:
 *
 *   - assignments go to `mfg_staff_assignments` (mshop_id), not
 *     `staff_shop_assignments` (shop_id);
 *   - roles are granted with scope_type 'mshop'/'manufacturer', never 'vendor' or
 *     'shop' — 11_seed.sql bulk-grants those two classes to vendor_owner;
 *   - users get principal_type 'manufacturer', which is what LoginController's
 *     landingFor() reads to send them to the right panel.
 *
 * What IS shared, deliberately: `vendor_staff` and `users`. A manufacturer is a
 * `vendors` row, so its staff are ordinary vendor_staff rows keyed on that id. Only
 * the unit assignment differs.
 *
 * This is the first code anywhere that writes `mfg_staff_assignments` — the table
 * shipped in 70_manufacturer.sql and no UI ever populated it, which left the whole
 * unit-isolation model in BaseManufacturerController unreachable in production.
 *
 * @see \App\Models\VendorStaffRepository — the vendor counterpart
 */
final class ManufacturerStaffRepository
{
    /**
     * staff_type -> the role granted on login, and the scope that role is granted at.
     *
     * The staff_type values are enum members added by 76_manufacturer_parity.sql;
     * `vendor_staff.staff_type` previously held only vendor shop roles, so a factory
     * store keeper had no honest value to store.
     *
     * @var array<string,array{0:string,1:string}> type => [role code, scope type]
     */
    private const ROLE_FOR_TYPE = [
        'unit_manager'   => ['manufacturer_unit_manager', 'mshop'],
        'store_keeper'   => ['manufacturer_store_keeper', 'mshop'],
        'finance_viewer' => ['manufacturer_finance_viewer', 'manufacturer'],
        'manager'        => ['manufacturer_manager', 'manufacturer'],
    ];

    /** Staff types this panel offers, for form validation. @return list<string> */
    public static function types(): array
    {
        return array_keys(self::ROLE_FOR_TYPE);
    }

    /** Staff with their assigned unit names, for the management table. @return list<array<string,mixed>> */
    public function staffWithUnits(int $manufacturerId): array
    {
        return Database::connect()->table('vendor_staff vs')
            ->select('vs.id, vs.user_id, vs.staff_type, vs.employee_code, vs.designation, vs.status, u.name, u.email, u.phone, GROUP_CONCAT(DISTINCT m.name ORDER BY m.name SEPARATOR ", ") AS units')
            ->join('users u', 'u.id = vs.user_id', 'left')
            ->join('mfg_staff_assignments msa', "msa.vendor_staff_id = vs.id AND msa.status = 'active' AND msa.deleted_at IS NULL", 'left')
            ->join('mshops m', 'm.id = msa.mshop_id', 'left')
            ->where('vs.vendor_id', $manufacturerId)->where('vs.deleted_at', null)
            ->groupBy('vs.id')->orderBy('vs.id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null one staff member, tenant-scoped */
    public function findStaff(int $staffId, int $manufacturerId): ?array
    {
        $row = Database::connect()->table('vendor_staff vs')
            ->select('vs.id, vs.user_id, vs.staff_type, vs.employee_code, vs.designation, vs.status, u.name, u.email, u.phone')
            ->join('users u', 'u.id = vs.user_id', 'left')
            ->where('vs.id', $staffId)->where('vs.vendor_id', $manufacturerId)->where('vs.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @return list<int> unit ids the staff member is actively assigned to */
    public function staffUnits(int $staffId): array
    {
        return array_map('intval', array_column(
            Database::connect()->table('mfg_staff_assignments')->select('mshop_id')
                ->where('vendor_staff_id', $staffId)->where('status', 'active')->where('deleted_at', null)
                ->get()->getResultArray(),
            'mshop_id',
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
     * Create a staff member with a panel login, unit assignment(s) and a scoped role,
     * in one transaction.
     *
     * @param array<string,mixed> $d name, email, password, phone, staff_type,
     *        mshop_ids (int[]), primary_unit (int), employee_code, designation
     * @return int|null new vendor_staff id
     */
    public function createStaff(int $manufacturerId, array $d, ?int $actorId = null): ?int
    {
        $db = Database::connect();
        $db->transBegin();

        try {
            $db->table('users')->insert([
                'uuid'          => bin2hex(random_bytes(18)),
                // 'manufacturer', not 'vendor': LoginController::landingFor() reads this
                // to pick the panel, and WebAuthFilter's principal pin checks it.
                'principal_type' => 'manufacturer',
                'name'          => mb_substr((string) $d['name'], 0, 191),
                'email'         => ($d['email'] ?? '') ?: null,
                'phone'         => ($d['phone'] ?? '') ?: null,
                'password_hash' => ! empty($d['password']) ? password_hash((string) $d['password'], PASSWORD_BCRYPT) : null,
                'status'        => 'active',
                'created_by'    => $actorId,
            ]);
            $userId = (int) $db->insertID();

            $type = array_key_exists((string) ($d['staff_type'] ?? ''), self::ROLE_FOR_TYPE) ? (string) $d['staff_type'] : 'store_keeper';
            $db->table('vendor_staff')->insert([
                'uuid'          => bin2hex(random_bytes(18)),
                'user_id'       => $userId,
                'vendor_id'     => $manufacturerId,
                'staff_type'    => $type,
                'employee_code' => ($d['employee_code'] ?? '') ?: null,
                'designation'   => ($d['designation'] ?? '') ?: null,
                'joined_at'     => date('Y-m-d'),
                'status'        => 'active',
                'created_by'    => $actorId,
            ]);
            $staffId = (int) $db->insertID();

            $unitIds = array_values(array_unique(array_map('intval', (array) ($d['mshop_ids'] ?? []))));
            $this->writeUnits($db, $staffId, $unitIds, (int) ($d['primary_unit'] ?? 0), $actorId);
            $this->writeRole($db, $userId, $manufacturerId, $type, $unitIds, $actorId);

            $db->transComplete();

            return $db->transStatus() ? $staffId : null;
        } catch (Throwable) {
            $db->transRollback();

            return null;
        }
    }

    /** Update profile, unit assignments and role in one transaction. */
    public function updateStaff(int $staffId, int $manufacturerId, array $d, ?int $actorId = null): bool
    {
        $staff = $this->findStaff($staffId, $manufacturerId);
        if ($staff === null) {
            return false;
        }

        $userId = (int) $staff['user_id'];
        $db     = Database::connect();
        $db->transBegin();

        try {
            $userPatch = [
                'name'       => mb_substr((string) $d['name'], 0, 191),
                'email'      => ($d['email'] ?? '') ?: null,
                'phone'      => ($d['phone'] ?? '') ?: null,
                'updated_by' => $actorId,
            ];
            if (! empty($d['password'])) {
                $userPatch['password_hash'] = password_hash((string) $d['password'], PASSWORD_BCRYPT);
            }
            $db->table('users')->where('id', $userId)->update($userPatch);

            $type = array_key_exists((string) ($d['staff_type'] ?? ''), self::ROLE_FOR_TYPE)
                ? (string) $d['staff_type']
                : (string) $staff['staff_type'];
            $db->table('vendor_staff')->where('id', $staffId)->update([
                'staff_type'    => $type,
                'employee_code' => ($d['employee_code'] ?? '') ?: null,
                'designation'   => ($d['designation'] ?? '') ?: null,
                'updated_by'    => $actorId,
            ]);

            $unitIds = array_values(array_unique(array_map('intval', (array) ($d['mshop_ids'] ?? []))));
            $this->writeUnits($db, $staffId, $unitIds, (int) ($d['primary_unit'] ?? 0), $actorId);
            $this->writeRole($db, $userId, $manufacturerId, $type, $unitIds, $actorId);

            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }

    /** Suspend / reactivate a staff member and their login. */
    public function setStatus(int $staffId, int $manufacturerId, string $status, ?int $actorId = null): bool
    {
        $staff = $this->findStaff($staffId, $manufacturerId);
        if ($staff === null || ! in_array($status, ['active', 'suspended'], true)) {
            return false;
        }

        $db = Database::connect();
        $db->table('vendor_staff')->where('id', $staffId)->update(['status' => $status, 'updated_by' => $actorId]);
        // users.status is what WebAuthFilter re-checks on every request, so this is
        // what actually ends a suspended staff member's live session.
        $db->table('users')->where('id', (int) $staff['user_id'])
            ->update(['status' => $status === 'active' ? 'active' : 'suspended', 'updated_by' => $actorId]);

        return true;
    }

    /** Replace unit assignments (hard-replace; at most one primary). */
    private function writeUnits(object $db, int $staffId, array $unitIds, int $primaryUnit, ?int $actorId): void
    {
        $db->table('mfg_staff_assignments')->where('vendor_staff_id', $staffId)->delete();

        foreach ($unitIds as $mshopId) {
            $db->table('mfg_staff_assignments')->insert([
                'vendor_staff_id' => $staffId,
                'mshop_id'        => $mshopId,
                'is_primary'      => $mshopId === $primaryUnit ? 1 : 0,
                'assigned_at'     => date('Y-m-d H:i:s'),
                'status'          => 'active',
                'created_by'      => $actorId,
            ]);
        }
    }

    /**
     * Replace the staff member's manufacturer role rows.
     *
     * Every manufacturer staff role is cleared first, so changing someone's type
     * actually drops the old grant instead of accumulating both. Unit-scoped roles
     * get one row per assigned unit; tenant-scoped roles get a single row against the
     * manufacturer. scope_type is never 'vendor'/'shop' here — 11_seed.sql:234
     * bulk-grants those classes to vendor_owner.
     */
    private function writeRole(object $db, int $userId, int $manufacturerId, string $type, array $unitIds, ?int $actorId): void
    {
        $allRoleCodes = array_map(static fn (array $r): string => $r[0], array_values(self::ROLE_FOR_TYPE));
        $staffRoleIds = array_map('intval', array_column(
            $db->table('roles')->select('id')->whereIn('code', $allRoleCodes)->get()->getResultArray(),
            'id',
        ));
        if ($staffRoleIds !== []) {
            $db->table('user_roles')->where('user_id', $userId)
                ->whereIn('scope_type', ['manufacturer', 'mshop'])
                ->whereIn('role_id', $staffRoleIds)->delete();
        }

        [$roleCode, $scopeType] = self::ROLE_FOR_TYPE[$type] ?? self::ROLE_FOR_TYPE['store_keeper'];
        $roleId                 = (int) ($db->table('roles')->select('id')->where('code', $roleCode)->get()->getRowArray()['id'] ?? 0);
        if ($roleId === 0) {
            return;
        }

        $scopeIds = $scopeType === 'mshop' ? $unitIds : [$manufacturerId];
        foreach ($scopeIds as $scopeId) {
            $db->table('user_roles')->insert([
                'user_id'    => $userId,
                'role_id'    => $roleId,
                'scope_type' => $scopeType,
                'scope_id'   => $scopeId,
                'created_by' => $actorId,
            ]);
        }
    }
}
