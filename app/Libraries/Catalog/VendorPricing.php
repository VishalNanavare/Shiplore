<?php

declare(strict_types=1);

namespace App\Libraries\Catalog;

use App\Libraries\Money;
use InvalidArgumentException;

/**
 * VendorPricing — the MRP / selling-price invariant.
 *
 * A vendor product carries an MRP (the maximum retail price printed on the pack, set by
 * whoever made it) and a SELLING price (what the customer actually pays). Manufacturers
 * instead carry making price + selling price and have no MRP concept at all — see
 * ManufacturerPricing, which this deliberately mirrors.
 *
 * The rule: 0 < base_price <= mrp.
 *
 * Equality is ALLOWED here, unlike the manufacturer rule where making == selling is
 * rejected. Selling exactly at MRP is ordinary retail; a manufacturer selling exactly at
 * cost is a typo. The asymmetry is intentional, not an oversight.
 *
 * Why this exists at all: the vendor write path enforced nothing.
 * Vendor\ProductController defaults both fields to '0' and stores what it is given, so
 * two things were possible and neither was visible:
 *
 *   - selling ABOVE MRP, which the Legal Metrology Act prohibits — a compliance problem,
 *     not a tidiness one;
 *   - an MRP left blank, stored as 0, which makes store/product.php compute a 0% saving,
 *     hide the struck-through price and render no discount badge. The vendor believes
 *     they listed a bargain; the customer sees a plain price.
 *
 * Pure and dependency-free so it can be applied on EVERY write path including autosave,
 * which is where the manufacturer side found the equivalent hole. Comparison goes through
 * Money, so it is exact integer-unit arithmetic and never float — 149.50 vs 149.49 must
 * not hinge on binary rounding.
 *
 * Returns '' on success or a human-readable message, matching the validateX() convention
 * already used by the admin, vendor and manufacturer product controllers.
 *
 * @see ManufacturerPricing the making/selling counterpart
 */
final class VendorPricing
{
    /**
     * @param array<string,mixed> $in raw request input; keys may be absent or blank
     * @param bool $required when true both prices must be present (create/submit);
     *                       when false a blank pair is accepted (partial autosave)
     */
    public static function validate(array $in, bool $required = true): string
    {
        $mrpRaw     = self::raw($in, 'mrp');
        $sellingRaw = self::raw($in, 'base_price', 'selling_price');

        if ($mrpRaw === null && $sellingRaw === null) {
            return $required ? 'MRP and selling price are required.' : '';
        }
        if ($required && $mrpRaw === null) {
            return 'MRP is required.';
        }
        if ($required && $sellingRaw === null) {
            return 'Selling price is required.';
        }

        // A partial autosave can carry only one side; there is nothing to compare, but a
        // malformed or non-positive value must still be rejected rather than stored.
        try {
            $mrp     = $mrpRaw !== null ? Money::of($mrpRaw) : null;
            $selling = $sellingRaw !== null ? Money::of($sellingRaw) : null;
        } catch (InvalidArgumentException) {
            return 'Prices must be plain numbers, e.g. 149.50.';
        }

        if ($mrp !== null && ! $mrp->isPositive()) {
            return 'MRP must be greater than zero.';
        }
        if ($selling !== null && ! $selling->isPositive()) {
            return 'Selling price must be greater than zero.';
        }
        // Equality passes: only a selling price strictly ABOVE the MRP is refused.
        if ($mrp !== null && $selling !== null && $mrp->lessThan($selling)) {
            return 'Selling price cannot be more than the MRP.';
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
     * Blank is treated as absent — an untouched form field posts '' and must not be read
     * as zero, which would trip the "greater than zero" check on a partial save.
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
