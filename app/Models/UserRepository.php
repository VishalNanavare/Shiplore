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
        // SECURITY: this used to strip the country code and fan out on the last 10
        // digits, synthesising '+91'.$last10 as a candidate. That made any foreign
        // number whose trailing 10 digits matched an Indian one resolve to that Indian
        // account: an attacker holding +1 917 818 1958 could complete Firebase Phone
        // Auth on their OWN number, and LoginController::otpLogin() would resolve it to
        // +91 9178 181 958 and start that user's session. Proving you own one number
        // must never authenticate you as the owner of a different one.
        //
        // Canonicalise first and refuse anything that is not a valid Indian mobile.
        $e164 = StoreCustomerRepository::normalizePhone($phone);
        if ($e164 === null) {
            return null;
        }

        // Legacy spellings of THIS SAME number only — historic rows predate
        // normalisation, so 9812345678 / 09812345678 / 919812345678 must still resolve.
        // Every candidate below denotes the same +91 subscriber; none crosses countries.
        $last10     = substr($e164, -10);
        $candidates = [$e164, $last10, '0' . $last10, '91' . $last10, '+91 ' . $last10];

        $rows = Database::connect()->table('users')
            // password_hash: needed by VendorPosController's token mint sites to stamp
            // the JWT 'pwd' claim (audit M6) — unused by other callers, but harmless
            // to select alongside the columns they already read.
            ->select('id, name, email, phone, status, principal_type, password_hash')
            ->whereIn('phone', $candidates)
            ->where('deleted_at', null)
            ->limit(2)
            ->get()->getResultArray();

        // Two rows means two accounts spell the same number differently — refuse rather
        // than guess which one to sign in.
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
