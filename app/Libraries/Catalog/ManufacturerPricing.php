<?php

declare(strict_types=1);

namespace App\Libraries\Catalog;

use App\Libraries\Money;
use InvalidArgumentException;

/**
 * ManufacturerPricing — the making-price / selling-price invariant.
 *
 * A manufacturer product carries a MAKING price (what it costs them to produce) and a
 * SELLING price (what a vendor/shop pays on monline). Vendors instead carry MRP +
 * selling price; manufacturers have no MRP concept.
 *
 * The rule: 0 < making_price < selling_price. Equal is rejected too — selling at cost
 * is almost always a typo, and letting it through silently produces zero-margin B2B
 * orders that only surface at settlement.
 *
 * Pure and dependency-free so it can be applied on EVERY write path, not just the form.
 * That matters because the vendor autosave endpoint writes prices with no validation at
 * all (AdminProductRepository::autosave, 'pricing' branch) — the manufacturer side must
 * not repeat that. Comparison goes through Money, so it is exact integer-unit
 * arithmetic and never float.
 *
 * Returns '' on success or a human-readable message, matching the validateX() convention
 * already used by the admin and vendor product controllers.
 */
final class ManufacturerPricing
{
    /**
     * @param array<string,mixed> $in raw request input; keys may be absent or blank
     * @param bool $required when true both prices must be present (create/submit);
     *                       when false a blank pair is accepted (partial autosave)
     */
    public static function validate(array $in, bool $required = true): string
    {
        $makingRaw  = self::raw($in, 'making_price');
        $sellingRaw = self::raw($in, 'base_price', 'selling_price');

        if ($makingRaw === null && $sellingRaw === null) {
            return $required ? 'Making price and selling price are required.' : '';
        }
        if ($required && $makingRaw === null) {
            return 'Making price is required.';
        }
        if ($required && $sellingRaw === null) {
            return 'Selling price is required.';
        }

        // A partial autosave can carry only one side; there is nothing to compare, but a
        // malformed or non-positive value must still be rejected rather than stored.
        try {
            $making  = $makingRaw !== null ? Money::of($makingRaw) : null;
            $selling = $sellingRaw !== null ? Money::of($sellingRaw) : null;
        } catch (InvalidArgumentException) {
            return 'Prices must be plain numbers, e.g. 149.50.';
        }

        if ($making !== null && ! $making->isPositive()) {
            return 'Making price must be greater than zero.';
        }
        if ($selling !== null && ! $selling->isPositive()) {
            return 'Selling price must be greater than zero.';
        }
        if ($making !== null && $selling !== null && ! $making->lessThan($selling)) {
            return 'Making price must be less than the selling price.';
        }

        return '';
    }

    /**
     * True when the pair may be persisted. Convenience for call sites that only need a
     * boolean; anything user-facing should use validate() and show the message.
     */
    public static function isValid(array $in, bool $required = true): bool
    {
        return self::validate($in, $required) === '';
    }

    /**
     * First present, non-blank value among the given keys, as a string.
     *
     * Blank is treated as absent — an untouched form field posts '' and must not be
     * read as zero, which would trip the "greater than zero" check on a partial save.
     */
    private static function raw(array $in, string ...$keys): ?string
    {
        foreach ($keys as $k) {
            if (! array_key_exists($k, $in)) {
                continue;
            }
            $v = $in[$k];
            if ($v === null || (is_string($v) && trim($v) === '')) {
                continue;
            }

            return is_string($v) ? trim($v) : (string) $v;
        }

        return null;
    }
}
