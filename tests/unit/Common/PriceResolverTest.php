<?php

declare(strict_types=1);

use App\Libraries\Pricing\PriceResolver;
use CodeIgniter\Test\CIUnitTestCase as TestCase;

/** P4 — pure effective-price selection. */
final class PriceResolverTest extends TestCase
{
    private function ctx(array $over = []): array
    {
        return array_merge(['now' => '2026-06-11 10:00:00', 'channel' => 'online', 'customer_group_id' => null, 'shop_id' => null, 'qty' => 1.0], $over);
    }

    public function testBaseWhenNoCandidates(): void
    {
        $r = PriceResolver::resolve(500.0, [], $this->ctx());
        $this->assertSame(500.0, $r['price']);
        $this->assertSame('base', $r['source']);
    }

    public function testFlashOverlayWinsOverBase(): void
    {
        $cands = [['price' => 399.0, 'priority' => 10, 'kind' => 'flash', 'channel' => 'all', 'customer_group_id' => null, 'shop_id' => null, 'valid_from' => '2026-06-01', 'valid_to' => '2026-12-31', 'min_qty' => 1]];
        $r = PriceResolver::resolve(500.0, $cands, $this->ctx());
        $this->assertSame(399.0, $r['price']);
        $this->assertSame('flash', $r['source']);
    }

    public function testExpiredOverlayIgnored(): void
    {
        $cands = [['price' => 399.0, 'priority' => 10, 'kind' => 'flash', 'channel' => 'all', 'customer_group_id' => null, 'shop_id' => null, 'valid_from' => '2025-01-01', 'valid_to' => '2025-02-01', 'min_qty' => 1]];
        $r = PriceResolver::resolve(500.0, $cands, $this->ctx());
        $this->assertSame(500.0, $r['price']);
    }

    public function testChannelMismatchIgnored(): void
    {
        $cands = [['price' => 399.0, 'priority' => 10, 'kind' => 'offer', 'channel' => 'pos', 'customer_group_id' => null, 'shop_id' => null, 'valid_from' => null, 'valid_to' => null, 'min_qty' => 1]];
        $r = PriceResolver::resolve(500.0, $cands, $this->ctx(['channel' => 'online']));
        $this->assertSame(500.0, $r['price']);
    }

    public function testCustomerGroupPriceAppliesOnlyToThatGroup(): void
    {
        $cands = [['price' => 450.0, 'priority' => 5, 'kind' => 'group', 'channel' => 'all', 'customer_group_id' => 2, 'shop_id' => null, 'valid_from' => null, 'valid_to' => null, 'min_qty' => 1]];
        $this->assertSame(500.0, PriceResolver::resolve(500.0, $cands, $this->ctx(['customer_group_id' => 1]))['price']);
        $this->assertSame(450.0, PriceResolver::resolve(500.0, $cands, $this->ctx(['customer_group_id' => 2]))['price']);
    }

    public function testQtyTierRequiresMinQty(): void
    {
        $cands = [['price' => 380.0, 'priority' => 5, 'kind' => 'tier', 'channel' => 'all', 'customer_group_id' => null, 'shop_id' => null, 'valid_from' => null, 'valid_to' => null, 'min_qty' => 10]];
        $this->assertSame(500.0, PriceResolver::resolve(500.0, $cands, $this->ctx(['qty' => 5]))['price']);
        $this->assertSame(380.0, PriceResolver::resolve(500.0, $cands, $this->ctx(['qty' => 12]))['price']);
    }

    public function testHighestPriorityWinsThenLowestPrice(): void
    {
        $cands = [
            ['price' => 420.0, 'priority' => 5, 'kind' => 'offer', 'channel' => 'all', 'customer_group_id' => null, 'shop_id' => null, 'valid_from' => null, 'valid_to' => null, 'min_qty' => 1],
            ['price' => 399.0, 'priority' => 10, 'kind' => 'flash', 'channel' => 'all', 'customer_group_id' => null, 'shop_id' => null, 'valid_from' => null, 'valid_to' => null, 'min_qty' => 1],
            ['price' => 410.0, 'priority' => 10, 'kind' => 'deal', 'channel' => 'all', 'customer_group_id' => null, 'shop_id' => null, 'valid_from' => null, 'valid_to' => null, 'min_qty' => 1],
        ];
        $r = PriceResolver::resolve(500.0, $cands, $this->ctx());
        $this->assertSame(399.0, $r['price']);   // priority 10 beats 5; among 10s, 399 < 410
        $this->assertSame('flash', $r['source']);
    }
}
