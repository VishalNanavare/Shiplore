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

    /**
     * A zero MRP must be caught BY THE ZERO CHECK, not incidentally by the comparison.
     *
     * With both fields present, mrp 0 vs base 149 also trips "selling above MRP", so
     * deleting the isPositive() guard leaves the pair still rejected and every assertion
     * above still green — a mutation run proved exactly that. A lone zero has nothing to
     * compare against, so only the dedicated guard can refuse it. This is also the real
     * autosave shape: one field at a time.
     */
    public function testALoneZeroMrpIsRejectedOnItsOwn(): void
    {
        $msg = VendorPricing::validate(['mrp' => '0'], false);

        $this->assertNotSame('', $msg);
        $this->assertStringContainsString('greater than zero', $msg);
    }

    /** Same reasoning for the selling price. */
    public function testALoneZeroSellingPriceIsRejectedOnItsOwn(): void
    {
        $this->assertStringContainsString(
            'greater than zero',
            VendorPricing::validate(['base_price' => '0'], false),
        );
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
     * PROMOTED to enforcing. This assertion previously required the rule to be log-only;
     * rewritten to the new intent rather than deleted, so the promotion is explicit in
     * the diff.
     *
     * Log-only existed for exactly one commit, because nobody knew how much legacy data
     * would trip the rule. Production answered it: of 1,801,163 live variants, ZERO sell
     * above MRP, and all 1,140 rows with mrp = 0 belong to manufacturers, where that is
     * correct. No vendor data to break, so no reason to keep warning instead of refusing.
     *
     * Asserted through validateRow(), the method both store() and update() already gate
     * on — placing it there is what makes it block, and a rule sitting in productInput()
     * (where it started) could only ever log.
     */
    public function testTheVendorRuleIsEnforcedOnTheWritePath(): void
    {
        $body = $this->methodBody('validateRow');

        $this->assertNotSame('', $body, 'validateRow() not found');
        $this->assertMatchesRegularExpression(
            '/VendorPricing::validate\(.*?!==\s*\'\'\)\s*\{\s*return\s+\$\w+;/s',
            $body,
            'a violation must abort the save, not merely be logged',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/log_message\([^)]*log-only/i',
            $body,
            'the log-only stage is over',
        );
    }

    /**
     * Manufacturers must NOT be subjected to the vendor rule.
     *
     * Their invariant is different — 0 < making < selling, with equality REJECTED — and
     * their products legitimately carry mrp = 0, which is all 1,140 of the zero-MRP rows
     * in production. Running VendorPricing over them would reject every single one.
     *
     * The separation is structural: each panel has its own controller and its own rule.
     * This pins it, because the cheap "fix" for a future bug report would be to reach for
     * the other panel's validator.
     */
    public function testManufacturerProductsAreNotSubjectToTheVendorRule(): void
    {
        $mfg = (string) file_get_contents(APPPATH . 'Controllers/Manufacturer/ProductController.php');

        $this->assertStringNotContainsString('VendorPricing', $mfg, 'the manufacturer panel must not use the vendor rule');
        $this->assertStringContainsString('ManufacturerPricing', $mfg, 'it must use its own');

        // And the vendor rule would indeed reject a manufacturer's legitimate row.
        $this->assertNotSame(
            '',
            VendorPricing::validate(['mrp' => '0', 'base_price' => '118'], false),
            'proof the separation matters: a manufacturer row fails the vendor rule',
        );
    }

    /** Source of Vendor\ProductController with comments stripped, one method's body. */
    private function methodBody(string $method): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents(APPPATH . 'Controllers/Vendor/ProductController.php')) as $t) {
            if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                continue; // the explanatory block names the very strings being matched
            }
            $code .= is_array($t) ? $t[1] : $t;
        }

        if (! preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $brace = strpos($code, '{', (int) $m[0][1]);
        $depth = 0;

        for ($i = (int) $brace, $len = strlen($code); $i < $len; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, (int) $brace, $i - (int) $brace + 1);
                }
            }
        }

        return '';
    }
}
