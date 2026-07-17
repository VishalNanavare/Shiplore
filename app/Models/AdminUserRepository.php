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

        return $row ?: null;
    }

    public function emailExists(string $email): bool
    {
        return (bool) Database::connect()->table('users')->where('email', $email)->where('deleted_at', null)->countAllResults();
    }

    /** @return list<array<string,mixed>> platform roles for the assign select */
    public function platformRoles(): array
    {
        return Database::connect()->table('roles')
            ->select('id, name')->where('vendor_id', null)->where('deleted_at', null)
            ->where('code !=', 'super_admin')->orderBy('name')
            ->get()->getResultArray();
    }

    /** @param array<string,mixed> $d @return int|null new user id */
    public function create(array $d, ?int $actorId = null): ?int
    {
        $db = Database::connect();
        $db->transBegin();
        try {
            $db->table('users')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'principal_type' => 'platform',
                'name' => mb_substr((string) $d['name'], 0, 191), 'email' => $d['email'], 'phone' => $d['phone'] ?: null,
                'password_hash' => password_hash((string) $d['password'], PASSWORD_BCRYPT),
                'status' => 'active', 'created_by' => $actorId,
            ]);
            $uid = (int) $db->insertID();
            if ((int) ($d['role_id'] ?? 0) > 0) {
                $db->table('user_roles')->insert(['user_id' => $uid, 'role_id' => (int) $d['role_id'], 'scope_type' => 'platform', 'scope_id' => null]);
            }
            $db->transComplete();

            return $db->transStatus() ? $uid : null;
        } catch (Throwable) {
            $db->transRollback();

            return null;
        }
    }

    public function setStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('users')->where('id', $id)->where('principal_type', 'platform')
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }

    /** Update name and email for own profile. Returns false if email is taken by another user. */
    public function updateProfile(int $id, string $name, string $email): bool
    {
        $db = Database::connect();
        if ($email !== '' && $db->table('users')
                ->where('email', $email)->where('id !=', $id)->where('deleted_at', null)
                ->countAllResults() > 0) {
            return false;
        }
        $patch = ['name' => mb_substr($name, 0, 191), 'updated_by' => $id];
        if ($email !== '') {
            $patch['email'] = $email;
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
