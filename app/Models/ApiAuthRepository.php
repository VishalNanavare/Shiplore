<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * ApiAuthRepository — user lookup for the mobile API auth (OTP / password login).
 * Resolves a user by email OR phone.
 */
final class ApiAuthRepository
{
    /** @return array<string,mixed>|null */
    public function findByIdentifier(string $identifier): ?array
    {
        $row = Database::connect()->table('users')
            ->select('id, name, principal_type, email, phone, status, password_hash')
            ->groupStart()->where('email', $identifier)->orWhere('phone', $identifier)->groupEnd()
            ->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** Is this user still active (not suspended/soft-deleted)? Re-checked on every API request. */
    public function isActive(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        return (bool) Database::connect()->table('users')
            ->where('id', $id)->where('status', 'active')->where('deleted_at', null)
            ->countAllResults();
    }

    /** Resolve an active user by id (used to re-issue a JWT on refresh). @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('users')
            ->select('id, name, principal_type, email, phone, status, password_hash')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }
}
