<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Commission/CommissionResolver.php';

use App\Libraries\Commission\CommissionResolver;

/**
 * Commission priority engine: Product > Subcategory > Category > BusinessType >
 * Global. @see docs/architecture/31-BUSINESS-TYPE-COMMISSION.md
 */
final class CommissionResolverTest extends TestCase
{
    public function testProductLevelWins(): void
    {
        $r = (new CommissionResolver())->resolve([
            'product' => 8.0, 'subcategory' => 9.0, 'category' => 10.0, 'business_type' => 12.0, 'global' => 15.0,
        ]);
        $this->assertSame('product', $r['level']);
        $this->assertSame(8.0, $r['rate']);
    }

    public function testFallsThroughToFirstAvailable(): void
    {
        $r = (new CommissionResolver())->resolve([
            'product' => null, 'subcategory' => null, 'category' => 10.0, 'business_type' => 12.0, 'global' => 15.0,
        ]);
        $this->assertSame('category', $r['level']);
        $this->assertSame(10.0, $r['rate']);
    }

    public function testGlobalDefaultLast(): void
    {
        $r = (new CommissionResolver())->resolve(['global' => 10.0]);
        $this->assertSame('global', $r['level']);
        $this->assertSame(10.0, $r['rate']);
    }

    public function testNoRatesYieldsZero(): void
    {
        $r = (new CommissionResolver())->resolve([]);
        $this->assertSame('none', $r['level']);
        $this->assertSame(0.0, $r['rate']);
    }

    public function testAmountComputation(): void
    {
        $this->assertSame('250.00', (new CommissionResolver())->amount(2500.0, 10.0));
        $this->assertSame('0.00', (new CommissionResolver())->amount(2500.0, 0.0));
    }
}
