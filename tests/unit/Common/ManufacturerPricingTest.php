<?php

declare(strict_types=1);

use App\Libraries\Catalog\ManufacturerPricing;
use App\Libraries\Money;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Money.php';
require_once __DIR__ . '/../../../app/Libraries/Catalog/ManufacturerPricing.php';

/**
 * The manufacturer price invariant: 0 < making_price < selling_price.
 *
 * Manufacturers price with a making (production) price and a selling price, instead of
 * the vendor's MRP + selling price. Making price is internal — it must never reach a
 * buyer — and it must always be below the selling price, or the B2B order settles at
 * zero or negative margin.
 */
final class ManufacturerPricingTest extends TestCase
{
    /** @dataProvider validPairs */
    public function testValidPairsAreAccepted(string $making, string $selling): void
    {
        $this->assertSame('', ManufacturerPricing::validate([
            'making_price' => $making,
            'base_price'   => $selling,
        ]));
    }

    public static function validPairs(): array
    {
        return [
            'plain'            => ['100', '150'],
            'decimals'         => ['99.99', '100.00'],
            'four dp'          => ['10.0001', '10.0002'],
            'wide margin'      => ['1', '999999'],
            'sub-rupee'        => ['0.0001', '0.0002'],
        ];
    }

    /** The headline rule. @dataProvider invalidPairs */
    public function testInvalidPairsAreRejected(string $making, string $selling, string $expect): void
    {
        $msg = ManufacturerPricing::validate([
            'making_price' => $making,
            'base_price'   => $selling,
        ]);

        $this->assertNotSame('', $msg, 'should have been rejected');
        $this->assertStringContainsString($expect, $msg);
    }

    public static function invalidPairs(): array
    {
        return [
            'making above selling'  => ['200', '150', 'less than the selling price'],
            'equal is rejected'     => ['150', '150', 'less than the selling price'],
            'equal with decimals'   => ['150.0000', '150', 'less than the selling price'],
            'off by one unit'       => ['10.0002', '10.0001', 'less than the selling price'],
            'zero making'           => ['0', '150', 'greater than zero'],
            'zero selling'          => ['100', '0', 'greater than zero'],
            'negative making'       => ['-5', '150', 'greater than zero'],
            'negative selling'      => ['100', '-150', 'greater than zero'],
        ];
    }

    /** Attacker-controlled input must be rejected, never coerced to a number. */
    public function testMalformedInputIsRejected(): void
    {
        foreach (['abc', '1.0 OR 1=1', '1e5', '10,000', '<script>', '0x10', ' '] as $bad) {
            $msg = ManufacturerPricing::validate(['making_price' => $bad, 'base_price' => '999']);
            $this->assertNotSame('', $msg, 'accepted malformed making price: ' . var_export($bad, true));
        }
    }

    /** Both prices are mandatory on create/submit. */
    public function testBothPricesRequiredWhenRequiredIsTrue(): void
    {
        $this->assertStringContainsString('required', ManufacturerPricing::validate([]));
        $this->assertStringContainsString('Making price is required', ManufacturerPricing::validate(['base_price' => '10']));
        $this->assertStringContainsString('Selling price is required', ManufacturerPricing::validate(['making_price' => '5']));
    }

    /**
     * A partial autosave carries one section at a time, so an absent pair is fine —
     * but a present-and-invalid value must STILL be rejected. This is the case the
     * vendor autosave path gets wrong (it writes prices with no validation at all).
     */
    public function testPartialAutosaveAllowsAbsentButNotInvalid(): void
    {
        $this->assertSame('', ManufacturerPricing::validate([], false), 'absent pair is fine on autosave');
        $this->assertSame('', ManufacturerPricing::validate(['title' => 'x'], false));

        // Present but broken -> still rejected.
        $this->assertNotSame('', ManufacturerPricing::validate(['making_price' => '-1'], false));
        $this->assertNotSame('', ManufacturerPricing::validate(['base_price' => '0'], false));
        $this->assertNotSame('', ManufacturerPricing::validate(['making_price' => 'abc'], false));

        // And the relation is still enforced when both are present.
        $this->assertNotSame('', ManufacturerPricing::validate(['making_price' => '9', 'base_price' => '5'], false));
    }

    /** An untouched form field posts '' — that is "absent", not zero. */
    public function testBlankStringsAreTreatedAsAbsentNotZero(): void
    {
        $this->assertSame('', ManufacturerPricing::validate(['making_price' => '', 'base_price' => ''], false));
        $this->assertStringContainsString('required', ManufacturerPricing::validate(['making_price' => '', 'base_price' => '']));
    }

    /** `selling_price` is accepted as an alias — the DB column is base_price. */
    public function testSellingPriceAliasIsAccepted(): void
    {
        $this->assertSame('', ManufacturerPricing::validate([
            'making_price'  => '10',
            'selling_price' => '20',
        ]));
        $this->assertNotSame('', ManufacturerPricing::validate([
            'making_price'  => '30',
            'selling_price' => '20',
        ]));
    }

    public function testIsValidMirrorsValidate(): void
    {
        $this->assertTrue(ManufacturerPricing::isValid(['making_price' => '1', 'base_price' => '2']));
        $this->assertFalse(ManufacturerPricing::isValid(['making_price' => '2', 'base_price' => '1']));
    }

    /**
     * The comparison must be exact integer units. Floats would make these pass
     * incorrectly — 0.1 + 0.2 is not 0.3 in binary floating point.
     */
    public function testComparisonIsExactNotFloating(): void
    {
        $this->assertTrue(Money::of('0.1')->add(Money::of('0.2'))->equals(Money::of('0.3')));
        $this->assertTrue(Money::of('10.0001')->lessThan(Money::of('10.0002')));
        $this->assertFalse(Money::of('10.0002')->lessThan(Money::of('10.0001')));
        $this->assertFalse(Money::of('10.0000')->lessThan(Money::of('10')));
        $this->assertTrue(Money::of('10.0002')->greaterThan(Money::of('10.0001')));
        $this->assertTrue(Money::of('1')->isPositive());
        $this->assertFalse(Money::of('0')->isPositive());
        $this->assertFalse(Money::of('-1')->isPositive());
    }
}
