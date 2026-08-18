<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * AdminUserRepository — platform staff users + role assignment. user.* perms.
 */
final class AdminUserRepository
{
    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return Database::connect()->table('users u')
            ->select('u.id, u.name, u.email, u.phone, u.status, u.last_login_at')
            ->select('(SELECT GROUP_CONCAT(r.name SEPARATOR ", ") FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND ur.deleted_at IS NULL) AS roles', false)
            ->where('u.principal_type', 'platform')->where('u.deleted_at', null)
            ->orderBy('u.id', 'DESC')->limit(200)
            ->get()->getResultArray();
    }

    public function find(int $id): ?array
    {
        $row = Database::connect()->table('users')->where('id', $id)->where('principal_type', 'platform')->where('deleted_at', null)->get()->getRowArray();
        if (! $row) {
            return null;
        }

        // The edit form needs the current platform role to preselect it; without this
        // the dropdown always opened on "choose a role" and a plain profile edit would
        // have silently reassigned the user.
        $role = Database::connect()->table('user_roles')
            ->select('role_id')->where('user_id', $id)->where('scope_type', 'platform')
            ->where('deleted_at', null)->get()->getRowArray();
        $row['role_id'] = $role['role_id'] ?? null;

        return $row;
    }

    public function emailExists(string $email): bool
    {
        return (bool) Database::connect()->table('users')->where('email', $email)->where('deleted_at', null)->countAllResults();
    }

    /**
     * uq_users_phone spans every principal_type, so a customer or rider already holding
     * this number blocks a staff insert. Checked up front so the operator sees a message
     * instead of a swallowed constraint violation.
     */
    public function phoneExists(?string $e164): bool
    {
        if ($e164 === null || $e164 === '') {
            return false;
        }

        return (bool) Database::connect()->table('users')
            ->where('phone', $e164)->where('deleted_at', null)->countAllResults();
    }

    /**
     * Roles assignable to a PLATFORM staff user.
     *
     * `vendor_id IS NULL` alone was not enough: it also matches the vendor-, shop- and
     * self-scoped role templates (vendor_manager, vendor_packer, customer, rider, ...),
     * so the "staff" dropdown offered Customer and Delivery Rider as platform roles.
     * Scope is the real filter.
     *
     * super_admin is excluded unless the caller can grant it — hiding it unconditionally
     * meant a second Super Admin could never be created through the UI at all.
     *
     * @return list<array<string,mixed>>
     */
    public function platformRoles(bool $includeSuperAdmin = false): array
    {
        $q = Database::connect()->table('roles')
            ->select('id, name, code')
            ->where('vendor_id', null)
            ->where('deleted_at', null)
            ->where('scope_class', 'platform')
            ->where('status', 'active');

        if (! $includeSuperAdmin) {
            $q->where('code !=', 'super_admin');
        }

        return $q->orderBy('name')->get()->getResultArray();
    }

    /** True when the given role id is the super_admin role. */
    public function isSuperAdminRole(int $roleId): bool
    {
        $row = Database::connect()->table('roles')
            ->select('id')->where('id', $roleId)->where('code', 'super_admin')
            ->get()->getRowArray();

        return $row !== null;
    }

    /** How many ACTIVE users still hold super_admin — guards removing the last one. */
    public function activeSuperAdminCount(): int
    {
        return (int) Database::connect()->table('user_roles ur')
            ->join('roles r', 'r.id = ur.role_id')
            ->join('users u', 'u.id = ur.user_id')
            ->where('r.code', 'super_admin')
            ->where('u.status', 'active')
            ->where('u.deleted_at', null)
            ->where('ur.deleted_at', null)
            ->countAllResults();
    }

    /** True when this user holds super_admin. */
    public function hasSuperAdmin(int $userId): bool
    {
        $row = Database::connect()->table('user_roles ur')
            ->select('ur.id')->join('roles r', 'r.id = ur.role_id')
            ->where('ur.user_id', $userId)->where('r.code', 'super_admin')
            ->where('ur.deleted_at', null)
            ->get()->getRowArray();

        return $row !== null;
    }

    /** Replace a staff user's platform role. Passing 0 removes it. */
    public function setRole(int $userId, int $roleId, ?int $actorId = null): bool
    {
        $db = Database::connect();
        $db->transBegin();
        try {
            $db->table('user_roles')->where('user_id', $userId)->where('scope_type', 'platform')->delete();
            if ($roleId > 0) {
                $db->table('user_roles')->insert([
                    'user_id'    => $userId,
                    'role_id'    => $roleId,
                    'scope_type' => 'platform',
                    'scope_id'   => null,
                    'created_by' => $actorId,
                ]);
            }
            $db->transComplete();

            return $db->transStatus();
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'AdminUserRepository::setRole failed for user ' . $userId . ': ' . $e->getMessage());

            return false;
        }
    }

    /** @param array<string,mixed> $d @return int|null new user id */
    public function create(array $d, ?int $actorId = null): ?int
    {
        $db = Database::connect();
        $db->transBegin();
        try {
            $db->table('users')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'principal_type' => 'platform',
                // Normalise to E.164 like every other flow. Storing the raw input meant a
                // staff row could hold "9800000000" while a customer row held
                // "+919800000000" for the same human — findByPhone() then matches two rows
                // and returns null, silently breaking phone login for BOTH.
                'name' => mb_substr((string) $d['name'], 0, 191), 'email' => $d['email'],
                'phone' => StoreCustomerRepository::normalizePhone((string) ($d['phone'] ?? '')),
                'password_hash' => password_hash((string) $d['password'], PASSWORD_BCRYPT),
                'status' => 'active', 'created_by' => $actorId,
            ]);
            $uid = (int) $db->insertID();
            if ((int) ($d['role_id'] ?? 0) > 0) {
                $db->table('user_roles')->insert(['user_id' => $uid, 'role_id' => (int) $d['role_id'], 'scope_type' => 'platform', 'scope_id' => null]);
            }
            $db->transComplete();

            if (! $db->transStatus()) {
                log_message('error', 'AdminUserRepository::create rolled back for ' . (string) $d['email'] . ' (transaction failed).');

                return null;
            }

            return $uid;
        } catch (Throwable $e) {
            $db->transRollback();
            // Swallowing this silently made a failed creation look identical to a
            // successful one: the controller discarded the null and reported success.
            log_message('error', 'AdminUserRepository::create failed for ' . (string) $d['email'] . ': ' . $e->getMessage());

            return null;
        }
    }

    public function setStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('users')->where('id', $id)->where('principal_type', 'platform')
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }

    /** Update name and email for own profile. Returns false if email is taken by another user. */
    /**
     * Update a staff user's profile.
     *
     * `$phone` is E.164-normalised before it is stored and compared, because every other
     * flow (registration, rider, store login) stores +91XXXXXXXXXX. Two spellings of one
     * human number both landing in `users` makes UserRepository::findByPhone() match two
     * rows, and it only returns a user when EXACTLY one matched — so both people silently
     * lose phone login. Pass null for $phone to leave it untouched.
     *
     * Changing the number clears phone_verified_at: a staff phone is a login credential
     * (LoginController::otpLogin resolves it to a session), so carrying the old verified
     * flag onto a new, unproven number would be a verification bypass.
     *
     * @return true on success, false when the email or phone belongs to another user
     */
    public function updateProfile(int $id, string $name, string $email, ?string $phone = null, ?int $actorId = null): bool
    {
        $db = Database::connect();

        if ($email !== '' && $db->table('users')
                ->where('email', $email)->where('id !=', $id)->where('deleted_at', null)
                ->countAllResults() > 0) {
            return false;
        }

        // updated_by recorded the EDITED user's id, so the audit trail claimed every
        // change was self-made. Record the acting admin; fall back to $id only if unknown.
        $patch = ['name' => mb_substr($name, 0, 191), 'updated_by' => $actorId ?? $id];
        if ($email !== '') {
            $patch['email'] = $email;
        }

        if ($phone !== null) {
            $clean = trim($phone);
            if ($clean === '') {
                // Clearing the number must clear its verification too.
                $patch['phone']             = null;
                $patch['phone_verified_at'] = null;
            } else {
                $e164 = StoreCustomerRepository::normalizePhone($clean);
                if ($e164 === null) {
                    return false;
                }
                // uq_users_phone is global across principal_type, so a customer or vendor
                // already holding this number would make the UPDATE throw. Check first and
                // report it, rather than surfacing a raw DB error.
                if ($db->table('users')->where('phone', $e164)->where('id !=', $id)
                        ->where('deleted_at', null)->countAllResults() > 0) {
                    return false;
                }

                $current = $db->table('users')->select('phone')->where('id', $id)->get()->getRowArray();
                if (($current['phone'] ?? null) !== $e164) {
                    $patch['phone']             = $e164;
                    $patch['phone_verified_at'] = null;
                }
            }
        }

        $db->table('users')->where('id', $id)->update($patch);

        return true;
    }

    /** Replace the stored password hash. */
    public function updatePassword(int $id, string $newHash): void
    {
        Database::connect()->table('users')->where('id', $id)
            ->update(['password_hash' => $newHash, 'updated_by' => $id]);
    }
}
