<?php

declare(strict_types=1);

use App\Libraries\Catalog\VariantCombinator;
use CodeIgniter\Test\CIUnitTestCase as TestCase;

/** P2 — pure variant Cartesian-product engine. */
final class VariantCombinatorTest extends TestCase
{
    public function testCartesianProductSizeByColor(): void
    {
        // Size{1=S,2=M} × Color{5=Red,6=Black} → 4 combinations
        $combos = VariantCombinator::combine([1 => [1, 2], 2 => [5, 6]]);
        $this->assertCount(4, $combos);
        $sigs = array_map([VariantCombinator::class, 'signature'], $combos);
        sort($sigs);
        $this->assertSame(['1:1|2:5', '1:1|2:6', '1:2|2:5', '1:2|2:6'], $sigs);
    }

    public function testSingleAttributeYieldsOnePerValue(): void
    {
        $this->assertCount(3, VariantCombinator::combine([7 => [10, 11, 12]]));
    }

    public function testEmptyOrValuelessSelectionsYieldNothing(): void
    {
        $this->assertSame([], VariantCombinator::combine([]));
        $this->assertSame([], VariantCombinator::combine([1 => [], 2 => []]));
    }

    public function testValuelessAttributeIsIgnoredNotMultipliedToZero(): void
    {
        // Color has values, Size is empty → result is just the Color values
        $combos = VariantCombinator::combine([1 => [], 2 => [5, 6]]);
        $this->assertCount(2, $combos);
    }

    public function testDuplicateValuesAreDeduped(): void
    {
        $this->assertCount(2, VariantCombinator::combine([1 => [5, 5, 6]]));
    }

    public function testSignatureIsOrderIndependent(): void
    {
        $this->assertSame(VariantCombinator::signature([2 => 6, 1 => 5]), VariantCombinator::signature([1 => 5, 2 => 6]));
    }

    public function testSkuSuffixSanitizes(): void
    {
        $this->assertSame('RED-M', VariantCombinator::skuSuffix(['Red', 'M']));
        $this->assertSame('64GB-BLACK', VariantCombinator::skuSuffix(['64 GB', 'Black!']));
    }
}
