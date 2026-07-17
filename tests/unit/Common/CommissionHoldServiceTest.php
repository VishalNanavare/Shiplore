<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Commission/CommissionLedgerStore.php';
require_once __DIR__ . '/../../../app/Libraries/Commission/CommissionHoldService.php';

use App\Libraries\Commission\CommissionHoldService;
use App\Libraries\Commission\CommissionLedgerStore;

/** X2a — commission hold window (doc 44 §8 C1–C7): the P0 money rule. */
final class CommissionHoldServiceTest extends TestCase
{
    private object $store;
    private array $clawbacks = [];

    protected function setUp(): void
    {
        $this->store = new class () implements CommissionLedgerStore {
            public array $rows  = [];
            public array $byId  = [];
            private int $nextId = 1;

            public function existsForSubOrder(int $subOrderId): bool
            {
                foreach ($this->byId as $r) {
                    if ($r['sub_order_id'] === $subOrderId) {
                        return true;
                    }
                }

                return false;
            }

            public function insertRows(array $rows): void
            {
                foreach ($rows as $r) {
                    $r['id']                 = $this->nextId++;
                    $this->byId[$r['id']]    = $r;
                    $this->rows[]            = $r;
                }
            }

            public function rowsForSubOrder(int $subOrderId, ?array $orderItemIds = null): array
            {
                return array_values(array_filter($this->byId, static fn (array $r): bool => $r['sub_order_id'] === $subOrderId
                    && ($orderItemIds === null || in_array($r['order_item_id'], $orderItemIds, true))));
            }

            public function setStatus(array $ids, string $status, ?string $reason = null): void
            {
                foreach ($ids as $id) {
                    $this->byId[$id]['status'] = $status;
                }
            }

            public function promoteDue(string $nowSql): int
            {
                $n = 0;
                foreach ($this->byId as $id => $r) {
                    if ($r['status'] === 'on_hold' && ($r['window_ends_at'] ?? null) !== null && $r['window_ends_at'] <= $nowSql) {
                        $this->byId[$id]['status'] = 'accrued';
                        $n++;
                    }
                }

                return $n;
            }

            public function subOrderWithItems(int $subOrderId): ?array
            {
                return null;
            }
        };
        $this->clawbacks = [];
    }

    private function svc(?int $windowDays = 7): CommissionHoldService
    {
        return new CommissionHoldService(
            $this->store,
            static fn (?int $v, ?int $c) => $windowDays,
            function (int $settlementId, string $amount, string $reason): void {
                $this->clawbacks[] = [$settlementId, $amount, $reason];
            },
        );
    }

    private const SUB = ['id' => 9, 'vendor_id' => 7, 'grand_total' => '1000.00', 'commission_amount' => '100.00'];

    private const ITEMS = [
        ['id' => 1, 'line_total' => '333.33'],
        ['id' => 2, 'line_total' => '333.33'],
        ['id' => 3, 'line_total' => '333.34'],
    ];

    public function testProrationSumsExactlyToCommission(): void
    {
        $lines = CommissionHoldService::prorate(self::SUB, self::ITEMS, 100.00);

        $this->assertCount(3, $lines);
        $sum = array_sum(array_map(static fn (array $l): float => (float) $l['commission_amount'], $lines));
        $this->assertSame(100.00, round($sum, 2)); // last line absorbs the rounding remainder
        $this->assertSame('33.34', $lines[2]['commission_amount']);
        $this->assertSame(10.0, $lines[0]['rate']);
    }

    public function testHoldCreatesOnHoldRowsWithWindow(): void
    {
        $r = $this->svc(7)->holdOnDelivery(self::SUB, self::ITEMS, new DateTimeImmutable('2026-06-12 14:00:00'));

        $this->assertSame('on_hold', $r['status']);
        $this->assertSame('2026-06-19 14:00:00', $r['window_ends_at']);
        $this->assertCount(3, $this->store->rows);
        $this->assertSame('on_hold', $this->store->rows[0]['status']);
        $this->assertSame(7, $this->store->rows[0]['vendor_id']);
    }

    public function testZeroDayWindowAccruesImmediately(): void
    {
        $r = $this->svc(0)->holdOnDelivery(self::SUB, self::ITEMS, new DateTimeImmutable('2026-06-12'));

        $this->assertSame('accrued', $r['status']);
        $this->assertNull($r['window_ends_at']);
    }

    public function testIdempotentPerSubOrder(): void
    {
        $svc = $this->svc();
        $svc->holdOnDelivery(self::SUB, self::ITEMS, new DateTimeImmutable('2026-06-12'));
        $again = $svc->holdOnDelivery(self::SUB, self::ITEMS, new DateTimeImmutable('2026-06-12'));

        $this->assertSame('exists', $again['skipped']);
        $this->assertCount(3, $this->store->rows);
    }

    public function testNoCommissionSkips(): void
    {
        $r = $this->svc()->holdOnDelivery(['id' => 1, 'vendor_id' => 7, 'grand_total' => '500', 'commission_amount' => '0'], [], new DateTimeImmutable());

        $this->assertSame('no_commission', $r['skipped']);
        $this->assertCount(0, $this->store->rows);
    }

    public function testEmptyItemsWritesSingleSubOrderRow(): void
    {
        $this->svc()->holdOnDelivery(self::SUB, [], new DateTimeImmutable('2026-06-12'));

        $this->assertCount(1, $this->store->rows);
        $this->assertNull($this->store->rows[0]['order_item_id']);
        $this->assertSame('100.00', $this->store->rows[0]['commission_amount']);
    }

    public function testPromoteDueRespectsBoundary(): void
    {
        $svc = $this->svc(7);
        $svc->holdOnDelivery(self::SUB, self::ITEMS, new DateTimeImmutable('2026-06-12 14:00:00'));

        $this->assertSame(0, $svc->promoteDue(new DateTimeImmutable('2026-06-19 13:59:00')));
        $this->assertSame(3, $svc->promoteDue(new DateTimeImmutable('2026-06-19 14:00:00')));
    }

    public function testCancelRoutesByState(): void
    {
        $svc = $this->svc(7);
        $svc->holdOnDelivery(self::SUB, self::ITEMS, new DateTimeImmutable('2026-06-12 14:00:00'));

        // simulate lifecycle: item1 stays on_hold, item2 accrued, item3 settled in settlement 55
        $this->store->byId[2]['status'] = 'accrued';
        $this->store->byId[3]['status'] = 'settled';
        $this->store->byId[3]['settled_in_id'] = 55;

        $r = $svc->cancelForReturn(9, null, 'return RMA-1');

        $this->assertSame(1, $r['cancelled']);
        $this->assertSame(1, $r['reversed']);
        $this->assertSame(1, $r['clawed_back']);
        $this->assertSame('cancelled', $this->store->byId[1]['status']);
        $this->assertSame('reversed', $this->store->byId[2]['status']);
        $this->assertSame('reversed', $this->store->byId[3]['status']);
        $this->assertSame([[55, '33.34', 'return RMA-1']], $this->clawbacks);
    }

    public function testPartialReturnCancelsOnlyThoseItems(): void
    {
        $svc = $this->svc(7);
        $svc->holdOnDelivery(self::SUB, self::ITEMS, new DateTimeImmutable('2026-06-12'));

        $r = $svc->cancelForReturn(9, [2], 'partial return');

        $this->assertSame(1, $r['cancelled']);
        $this->assertSame('on_hold', $this->store->byId[1]['status']); // untouched
        $this->assertSame('cancelled', $this->store->byId[2]['status']);
        $this->assertSame('on_hold', $this->store->byId[3]['status']); // untouched
    }

    public function testCancelIsIdempotent(): void
    {
        $svc = $this->svc(7);
        $svc->holdOnDelivery(self::SUB, self::ITEMS, new DateTimeImmutable('2026-06-12'));
        $svc->cancelForReturn(9, null, 'rma');
        $r = $svc->cancelForReturn(9, null, 'rma again');

        $this->assertSame(0, $r['cancelled'] + $r['reversed'] + $r['clawed_back']);
        $this->assertSame([], $this->clawbacks);
    }

    public function testDefaultWindowWhenNoPolicy(): void
    {
        $svc = new CommissionHoldService($this->store, static fn () => null);
        $r   = $svc->holdOnDelivery(self::SUB, [], new DateTimeImmutable('2026-06-12 00:00:00'));

        $this->assertSame('2026-06-19 00:00:00', $r['window_ends_at']); // DEFAULT_WINDOW_DAYS = 7
    }
}
