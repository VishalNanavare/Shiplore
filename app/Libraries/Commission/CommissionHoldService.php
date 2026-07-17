<?php

declare(strict_types=1);

namespace App\Libraries\Commission;

use DateTimeImmutable;
use Throwable;

/**
 * CommissionHoldService — Phase X2a (P0): platform commission is never
 * chargeable while the return window is open.
 *
 * On sub-order DELIVERY, line-level ledger rows are written `on_hold` with
 * `window_ends_at = delivered + return-window days` (return_policies,
 * most-specific wins; 0 days → immediate `accrued`). The scheduler task
 * `commission.promote_due` promotes elapsed holds to `accrued`; settlement
 * only ever consumes `accrued` rows. Returns route per state:
 * on_hold → cancelled · accrued → reversed · settled → clawback adjustment.
 *
 * Per-item proration is exact: the last line absorbs the rounding remainder,
 * so SUM(lines) always equals the sub-order commission to the paisa.
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §8
 */
final class CommissionHoldService
{
    public const DEFAULT_WINDOW_DAYS = 7;

    /** @var callable|null fn(?vendorId, ?categoryId): ?int */
    private $windowDays;

    /** @var callable|null fn(int $settlementId, string $amount, string $reason): void */
    private $clawback;

    /** @var callable|null fn(array $auditEntry): mixed */
    private $audit;

    public function __construct(
        private readonly CommissionLedgerStore $store,
        ?callable $windowDays = null,
        ?callable $clawback = null,
        ?callable $audit = null,
    ) {
        $this->windowDays = $windowDays;
        $this->clawback   = $clawback;
        $this->audit      = $audit;
    }

    /** Convenience for the delivered hook: loads the sub-order and holds. */
    public function holdForSubOrderId(int $subOrderId, ?DateTimeImmutable $deliveredAt = null): array
    {
        $data = $this->store->subOrderWithItems($subOrderId);
        if ($data === null) {
            return ['ok' => false, 'error' => 'sub_order_not_found'];
        }

        return $this->holdOnDelivery($data['sub'], $data['items'], $deliveredAt ?? new DateTimeImmutable('now'));
    }

    /**
     * Create hold rows for a delivered sub-order. Idempotent per sub-order.
     *
     * @param array<string,mixed>       $sub   id, vendor_id, grand_total, commission_amount
     * @param list<array<string,mixed>> $items id, line_total (may be empty → one sub-order-level row)
     */
    public function holdOnDelivery(array $sub, array $items, DateTimeImmutable $deliveredAt): array
    {
        $subId = (int) $sub['id'];
        if ($this->store->existsForSubOrder($subId)) {
            return ['ok' => true, 'skipped' => 'exists'];
        }
        $commission = round((float) ($sub['commission_amount'] ?? 0), 2);
        if ($commission <= 0) {
            return ['ok' => true, 'skipped' => 'no_commission'];
        }

        $days       = $this->resolveWindowDays($sub);
        $status     = $days > 0 ? 'on_hold' : 'accrued';
        $windowEnds = $days > 0 ? $deliveredAt->modify("+{$days} days")->format('Y-m-d H:i:s') : null;

        $rows = [];
        foreach (self::prorate($sub, $items, $commission) as $line) {
            $rows[] = $line + [
                'sub_order_id'   => $subId,
                'vendor_id'      => (int) $sub['vendor_id'],
                'status'         => $status,
                'window_ends_at' => $windowEnds,
                'held_reason'    => $days > 0 ? "return window {$days}d" : null,
            ];
        }
        $this->store->insertRows($rows);

        $this->emitAudit('commission.held', $subId, (int) $sub['vendor_id'], [
            'rows' => count($rows), 'status' => $status, 'window_ends_at' => $windowEnds,
            'commission' => self::n($commission),
        ]);

        return ['ok' => true, 'rows' => count($rows), 'status' => $status, 'window_ends_at' => $windowEnds];
    }

    /**
     * Pure: split a sub-order's commission across its items, exactly.
     * @return list<array{order_item_id:?int,base_amount:string,rate:float,commission_amount:string}>
     */
    public static function prorate(array $sub, array $items, float $commission): array
    {
        $grand = (float) ($sub['grand_total'] ?? 0);
        $rate  = $grand > 0 ? round($commission / $grand * 100, 2) : 0.0;

        if ($items === [] || $grand <= 0) {
            return [[
                'order_item_id'     => null,
                'base_amount'       => self::n($grand),
                'rate'              => $rate,
                'commission_amount' => self::n($commission),
            ]];
        }

        $out       = [];
        $allocated = 0.0;
        $last      = count($items) - 1;

        foreach ($items as $i => $item) {
            $base   = (float) ($item['line_total'] ?? 0);
            $amount = $i === $last
                ? round($commission - $allocated, 2)              // remainder → exact total
                : round($commission * ($base / $grand), 2);
            $allocated += $amount;

            $out[] = [
                'order_item_id'     => isset($item['id']) ? (int) $item['id'] : null,
                'base_amount'       => self::n($base),
                'rate'              => $rate,
                'commission_amount' => self::n($amount),
            ];
        }

        return $out;
    }

    /** Scheduler handler `commission.promote_due`: elapsed holds → accrued. */
    public function promoteDue(?DateTimeImmutable $now = null): int
    {
        $now      = $now ?? new DateTimeImmutable('now');
        $promoted = $this->store->promoteDue($now->format('Y-m-d H:i:s'));

        if ($promoted > 0) {
            $this->emitAudit('commission.window_closed', null, null, ['promoted' => $promoted]);
        }

        return $promoted;
    }

    /**
     * Returned goods never earn commission. Routes by current state:
     * on_hold → cancelled · accrued → reversed · settled → clawback (per
     * settlement, via the injected adjustment writer) then reversed.
     *
     * @param list<int>|null $orderItemIds null = the whole sub-order
     */
    public function cancelForReturn(int $subOrderId, ?array $orderItemIds, string $reason): array
    {
        $rows = $this->store->rowsForSubOrder($subOrderId, $orderItemIds);
        if ($rows === []) {
            return ['ok' => true, 'skipped' => 'no_rows'];
        }

        $cancelled = $reversed = $clawed = [];
        $claims    = []; // settlement_id => amount

        foreach ($rows as $r) {
            $id = (int) $r['id'];
            switch ($r['status']) {
                case 'on_hold':
                    $cancelled[] = $id;
                    break;
                case 'accrued':
                    $reversed[] = $id;
                    break;
                case 'settled':
                    $clawed[] = $id;
                    $sid          = (int) ($r['settled_in_id'] ?? 0);
                    $claims[$sid] = round(($claims[$sid] ?? 0) + (float) $r['commission_amount'], 2);
                    break;
                default: // cancelled / reversed already — idempotent
            }
        }

        if ($cancelled !== []) {
            $this->store->setStatus($cancelled, 'cancelled', $reason);
        }
        if ($reversed !== []) {
            $this->store->setStatus($reversed, 'reversed', $reason);
        }
        if ($clawed !== []) {
            foreach ($claims as $settlementId => $amount) {
                if ($this->clawback !== null && $settlementId > 0) {
                    try {
                        ($this->clawback)($settlementId, self::n($amount), $reason);
                    } catch (Throwable) {
                        // adjustment failure must not block the return itself
                    }
                }
            }
            $this->store->setStatus($clawed, 'reversed', $reason . ' (clawback)');
        }

        $this->emitAudit('commission.cancelled_for_return', $subOrderId, null, [
            'cancelled' => count($cancelled), 'reversed' => count($reversed),
            'clawed_back' => count($clawed), 'reason' => $reason,
        ]);

        return ['ok' => true, 'cancelled' => count($cancelled), 'reversed' => count($reversed), 'clawed_back' => count($clawed)];
    }

    // ------------------------------------------------------------------

    private function resolveWindowDays(array $sub): int
    {
        if ($this->windowDays !== null) {
            $days = ($this->windowDays)(
                isset($sub['vendor_id']) ? (int) $sub['vendor_id'] : null,
                isset($sub['category_id']) ? (int) $sub['category_id'] : null,
            );
            if ($days !== null) {
                return max(0, (int) $days);
            }
        }

        return self::DEFAULT_WINDOW_DAYS;
    }

    private function emitAudit(string $action, ?int $entityId, ?int $vendorId, array $meta): void
    {
        if ($this->audit === null) {
            return;
        }
        try {
            ($this->audit)([
                'action'         => $action,
                'entity_type'    => 'commission_ledger',
                'entity_id'      => $entityId,
                'principal_type' => 'system',
                'scope_type'     => $vendorId !== null ? 'vendor' : 'platform',
                'scope_id'       => $vendorId,
                'metadata'       => $meta,
            ]);
        } catch (Throwable) {
            // audit must never break money flow
        }
    }

    private static function n(float $v): string
    {
        return number_format(round($v, 2), 2, '.', '');
    }
}
