<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * PasswordResetRepository — stores reset-token hashes in `auth_otp`
 * (purpose='reset'): only the SHA-256 hash + expiry are persisted; the raw
 * token is e-mailed. Single-use (consumed_at).
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §3.3
 */
final class PasswordResetRepository
{
    public function store(string $identifier, string $tokenHash, int $expiresAt): void
    {
        Database::connect()->table('auth_otp')->insert([
            'identifier' => mb_substr($identifier, 0, 191),
            'code_hash'  => $tokenHash,
            'purpose'    => 'reset',
            'attempts'   => 0,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
        ]);
    }

    /** Latest unconsumed reset row for an identifier. @return array<string,mixed>|null */
    public function findLatestReset(string $identifier): ?array
    {
        $row = Database::connect()->table('auth_otp')
            ->select('id, code_hash, expires_at')
            ->where('identifier', $identifier)
            ->where('purpose', 'reset')
            ->where('consumed_at', null)
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function consume(int $id): void
    {
        Database::connect()->table('auth_otp')
            ->where('id', $id)
            ->update(['consumed_at' => date('Y-m-d H:i:s')]);
    }
}
