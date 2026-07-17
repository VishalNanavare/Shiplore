<?php

declare(strict_types=1);

namespace App\Libraries\Governance;

/**
 * ChangeRequestStore — persistence contract for the change-request engine,
 * injected so the engine's state machine stays DB-free and unit-testable.
 * Payload/level fields cross this boundary as PHP arrays; the DB
 * implementation owns JSON encoding.
 */
interface ChangeRequestStore
{
    /** @param array<string,mixed> $row  @return int new id */
    public function create(array $row): int;

    /** @return array<string,mixed>|null decoded row */
    public function find(int $id): ?array;

    /** @param array<string,mixed> $fields */
    public function update(int $id, array $fields): void;

    /** @param array<string,mixed> $row level decision */
    public function addDecision(array $row): void;

    /** An open request already exists for this entity + field group? */
    public function hasOpen(string $entityType, ?int $entityId, string $fieldGroup, ?int $excludeId = null): bool;

    /**
     * Mark every pending request whose sla_due_at < $nowSql as expired.
     * @return list<array<string,mixed>> the rows that were expired (decoded)
     */
    public function expireOverdue(string $nowSql): array;
}
