<?php

declare(strict_types=1);

namespace App\Libraries\Commission;

/**
 * CommissionLedgerStore — persistence contract for the commission hold
 * engine, injected so CommissionHoldService stays unit-testable without DB.
 */
interface CommissionLedgerStore
{
    public function existsForSubOrder(int $subOrderId): bool;

    /** @param list<array<string,mixed>> $rows */
    public function insertRows(array $rows): void;

    /**
     * Ledger rows of a sub-order, optionally narrowed to specific order items.
     * @param list<int>|null $orderItemIds
     * @return list<array<string,mixed>>
     */
    public function rowsForSubOrder(int $subOrderId, ?array $orderItemIds = null): array;

    /** @param list<int> $ids */
    public function setStatus(array $ids, string $status, ?string $reason = null): void;

    /** on_hold rows whose window has elapsed → accrued. @return int promoted */
    public function promoteDue(string $nowSql): int;

    /** @return array{sub:array<string,mixed>,items:list<array<string,mixed>>}|null */
    public function subOrderWithItems(int $subOrderId): ?array;
}
