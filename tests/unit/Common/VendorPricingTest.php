<?php

declare(strict_types=1);

use App\Libraries\Catalog\VendorPricing;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The vendor MRP / selling-price invariant: 0 < base_price <= mrp.
 *
 * The manufacturer side has enforced its own rule (0 < making < selling) since phase A
 * via ManufacturerPricing, applied on every write path. The vendor side enforced
 * NOTHING — Vendor\ProductController defaults both fields to '0' and stores whatever
 * arrives. Two consequences, both live:
 *
 *  - a vendor could save mrp 100 with base_price 150, i.e. selling ABOVE the printed
 *    MRP, which is illegal under the Legal Metrology Act rather than merely untidy;
 *  - leaving MRP blank stores 0, so store/product.php computes a 0% discount, hides the
 *    struck-through price and renders no savings badge — the vendor believes they listed
 *    a bargain and the customer is shown a plain price.
 *
 * Note MRP EQUAL to the selling price is allowed here, unlike the manufacturer rule
 * where equality is rejected. Selling exactly at MRP is completely ordinary retail; a
 * manufacturer selling exactly at cost is a typo.
 */
final class VendorPricingTest extends CIUnitTestCase
{
    // ------------------------------------------------------------------ accepted

    public function testSellingBelowMrpIsAccepted(): void
    {
        $this->assertSame('', VendorPricing::validate(['mrp' => '199', 'base_price' => '149']));
    }

    /** Equality is legitimate retail — no discount, but a lawful listing. */
    public function testSellingExactlyAtMrpIsAccepted(): void
    {
        $this->assertSame('', VendorPricing::validate(['mrp' => '149', 'base_price' => '149']));
    }

    public function testDecimalsAreCompared_exactly(): void
    {
        $this->assertSame('', VendorPricing::validate(['mrp' => '149.50', 'base_price' => '149.50']));
        $this->assertNotSame('', VendorPricing::validate(['mrp' => '149.49', 'base_price' => '149.50']));
    }

    /** The alternate field name the variant screens post. */
    public function testSellingPriceAliasIsUnderstood(): void
    {
        $this->assertSame('', VendorPricing::validate(['mrp' => '199', 'selling_price' => '149']));
    }

    // ------------------------------------------------------------------ rejected

    public function testSellingAboveMrpIsRejected(): void
    {
        $msg = VendorPricing::validate(['mrp' => '100', 'base_price' => '150']);

        $this->assertNotSame('', $msg);
        $this->assertStringContainsString('MRP', $msg);
    }

    public function testZeroOrNegativePricesAreRejected(): void
    {
        $this->assertNotSame('', VendorPricing::validate(['mrp' => '0', 'base_price' => '0']));
        $this->assertNotSame('', VendorPricing::validate(['mrp' => '199', 'base_price' => '0']));
        $this->assertNotSame('', VendorPricing::validate(['mrp' => '0', 'base_price' => '149']));
    }

    public function testMalformedNumbersAreRejected(): void
    {
        $this->assertNotSame('', VendorPricing::validate(['mrp' => '1,99', 'base_price' => 'abc']));
    }

    public function testBothMissingIsRejectedWhenRequired(): void
    {
        $this->assertNotSame('', VendorPricing::validate([], true));
    }

    // ------------------------------------------------------------- partial saves

    /**
     * Autosave posts one field at a time. There is nothing to compare, so a lone valid
     * price must pass — but a lone INVALID one must still be caught, or the autosave
     * endpoint becomes the hole the whole rule is meant to close.
     */
    public function testPartialSaveAcceptsOneSideButStillValidatesIt(): void
    {
        $this->assertSame('', VendorPricing::validate(['base_price' => '149'], false));
        $this->assertSame('', VendorPricing::validate(['mrp' => '199'], false));
        $this->assertSame('', VendorPricing::validate([], false));

        $this->assertNotSame('', VendorPricing::validate(['base_price' => '0'], false));
        $this->assertNotSame('', VendorPricing::validate(['mrp' => 'oops'], false));
    }

    /** A blank field is untouched, not zero — otherwise every partial save would fail. */
    public function testBlankIsTreatedAsAbsentNotZero(): void
    {
        $this->assertSame('', VendorPricing::validate(['mrp' => '', 'base_price' => '149'], false));
    }

    public function testIsValidMirrorsValidate(): void
    {
        $this->assertTrue(VendorPricing::isValid(['mrp' => '199', 'base_price' => '149']));
        $this->assertFalse(VendorPricing::isValid(['mrp' => '100', 'base_price' => '150']));
    }

    // --------------------------------------------------------- rollout stage

    /**
     * Stage 1 is LOG-ONLY, and that must be provable rather than asserted in a comment.
     *
     * An unknown number of live listings already violate this rule, so blocking on day
     * one would reject saves the panel has always accepted. Per the project convention a
     * new blocking check ships log-only, gets a traffic day of review, and is promoted in
     * a separate commit.
     *
     * This pins BOTH halves: the rule is wired in (a violation is logged) and it does NOT
     * yet block (no redirect, no early return). Asserting only the first would let a
     * premature promotion to hard-fail slip through silently; asserting only the second
     * would pass even if the wiring were deleted.
     */
    public function testTheVendorRuleIsWiredInButStillLogOnly(): void
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents(APPPATH . 'Controllers/Vendor/ProductController.php')) as $t) {
            if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                continue; // the block above discusses the promotion — do not match on it
            }
            $code .= is_array($t) ? $t[1] : $t;
        }

        $this->assertStringContainsString(
            'VendorPricing::validate',
            $code,
            'the invariant must actually be evaluated on the vendor write path',
        );
        // Deliberately not [^)]* — the call nests parentheses, `validate((array) …)`,
        // so a negated-class match stops at the first ')' and never reaches log_message.
        $this->assertMatchesRegularExpression(
            '/VendorPricing::validate\(.*?!==\s*\'\'\)\s*\{\s*log_message\(/s',
            $code,
            'a violation must be logged',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$pricingErr[^;]*\n?\s*return redirect\(\)/s',
            $code,
            'stage 1 must NOT block — promote to a hard failure only after the traffic review',
        );
    }
}
