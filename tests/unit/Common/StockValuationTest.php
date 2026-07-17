<?php

declare(strict_types=1);

use App\Libraries\Inventory\StockValuation;
use CodeIgniter\Test\CIUnitTestCase as TestCase;

/** P3 — pure inventory costing (FIFO/LIFO/weighted-average). */
final class StockValuationTest extends TestCase
{
    /** @var list<array{qty:float,cost:float}> */
    private array $layers;

    protected function setUp(): void
    {
        parent::setUp();
        // 10 @ ₹100 (older), then 10 @ ₹120 (newer)
        $this->layers = [['qty' => 10.0, 'cost' => 100.0], ['qty' => 10.0, 'cost' => 120.0]];
    }

    public function testOnHandValueAndQty(): void
    {
        $this->assertSame(2200.0, StockValuation::onHandValue($this->layers));
        $this->assertSame(20.0, StockValuation::onHandQty($this->layers));
    }

    public function testWeightedAverageCost(): void
    {
        $this->assertSame(110.0, StockValuation::weightedAverageCost($this->layers));
        $this->assertSame(0.0, StockValuation::weightedAverageCost([]));
    }

    public function testFifoConsumesOldestFirst(): void
    {
        // sell 15 → 10@100 + 5@120 = 1600 COGS; 5@120 remain
        $r = StockValuation::consume($this->layers, 15, 'fifo');
        $this->assertSame(1600.0, $r['cogs']);
        $this->assertSame(15.0, $r['consumed']);
        $this->assertSame(0.0, $r['short']);
        $this->assertSame([['qty' => 5.0, 'cost' => 120.0]], $r['remaining']);
    }

    public function testLifoConsumesNewestFirst(): void
    {
        // sell 15 → 10@120 + 5@100 = 1700 COGS; 5@100 remain
        $r = StockValuation::consume($this->layers, 15, 'lifo');
        $this->assertSame(1700.0, $r['cogs']);
        $this->assertSame([['qty' => 5.0, 'cost' => 100.0]], $r['remaining']);
    }

    public function testWavgConsumesAtAverage(): void
    {
        $r = StockValuation::consume($this->layers, 15, 'wavg');
        $this->assertSame(1650.0, $r['cogs']); // 15 × 110
    }

    public function testShortageIsReportedNotInvented(): void
    {
        $r = StockValuation::consume($this->layers, 25, 'fifo');
        $this->assertSame(20.0, $r['consumed']);
        $this->assertSame(5.0, $r['short']);
        $this->assertSame(2200.0, $r['cogs']);
        $this->assertSame([], $r['remaining']);
    }
}
