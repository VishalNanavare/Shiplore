<?php

declare(strict_types=1);

namespace App\Libraries\Sync;

/**
 * ConflictResolver — optimistic-concurrency decision for an inbound sync item.
 * The client sends the row_version it based its change on; we compare to the
 * current server version. Equal/ahead → apply (bump version). Stale → resolve by
 * strategy (server_wins = flag a conflict and keep server; client_wins =
 * overwrite). Pure.
 *
 * @see docs/architecture/35-SYNC-ENGINE.md
 */
final class ConflictResolver
{
    /**
     * @return array{action:'apply'|'conflict',resolution:string}
     */
    public function decide(int $clientVersion, ?int $serverVersion, string $strategy = 'server_wins'): array
    {
        if ($serverVersion === null) {
            return ['action' => 'apply', 'resolution' => 'new'];
        }
        if ($clientVersion >= $serverVersion) {
            return ['action' => 'apply', 'resolution' => 'in_sync'];
        }

        // client is stale — the server moved on since the client's base version
        return match ($strategy) {
            'client_wins' => ['action' => 'apply', 'resolution' => 'client_wins'],
            'last_write_wins' => ['action' => 'apply', 'resolution' => 'client_wins'],
            default => ['action' => 'conflict', 'resolution' => 'server_wins'],
        };
    }
}
