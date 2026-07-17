<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * UserRepository — user lookups for web session login. Finds an active-or-any
 * user by email OR phone (the single login field), excluding soft-deleted rows.
 */
final class UserRepository
{
    /** @return array{id:int,name:string,email:?string,phone:?string,password_hash:?string,status:string,principal_type:string}|null */
    public function findByLogin(string $login): ?array
    {
        $db = Database::connect();

        $row = $db->table('users')
            ->select('id, name, email, phone, password_hash, status, principal_type')
            ->groupStart()
                ->where('email', $login)
                ->orWhere('phone', $login)
            ->groupEnd()
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * Find a login user by phone, tolerant of formatting. Firebase returns the
     * number in E.164 (+91XXXXXXXXXX) but the stored value may omit the country
     * code or prefix; we match a small set of equivalent forms (index-friendly).
     * Returns null when nothing matches OR when more than one row matches
     * (ambiguous — refuse rather than guess which account to sign in).
     *
     * @return array<string,mixed>|null
     */
    public function findByPhone(string $phone): ?array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        $last10 = strlen($digits) >= 10 ? substr($digits, -10) : $digits;

        $candidates = array_values(array_unique(array_filter([
            $phone, $digits, '+' . $digits, $last10, '0' . $last10, '91' . $last10, '+91' . $last10,
        ])));

        $rows = Database::connect()->table('users')
            ->select('id, name, email, phone, status, principal_type')
            ->whereIn('phone', $candidates)
            ->where('deleted_at', null)
            ->limit(2)
            ->get()->getResultArray();

        return count($rows) === 1 ? $rows[0] : null;
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $row = Database::connect()->table('users')
            ->select('id, name, email, status')
            ->where('email', $email)
            ->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function updatePassword(int $userId, string $passwordHash): bool
    {
        return Database::connect()->table('users')
            ->where('id', $userId)
            ->update(['password_hash' => $passwordHash]);
    }
}
