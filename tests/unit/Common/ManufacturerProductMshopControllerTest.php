<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * `Manufacturer\ProductController` wiring for the manufacturing-unit assignment fix
 * (see `ManufacturerProductMshopTest` for the repository-level behavioural coverage).
 *
 * `resolveMshopId()` mirrors `Vendor\ProductController::resolveShopIds()` on purpose:
 * unit-scoped staff (store keeper / unit manager) must be FORCED to their own
 * effective unit, never trusted to post one — a store keeper posting a different
 * unit's id must not be able to list a product there. An owner may pick any unit,
 * validated against `allowedMshopIds()` rather than trusted from the request.
 *
 * These are source assertions, not a full HTTP-level test: no FeatureTestTrait
 * harness exists yet for the manufacturer panel to extend, and the underlying
 * authorization primitives (`isOwner()`, `effectiveMshopId()`, `allowedMshopIds()`)
 * are pre-existing, already-relied-upon `BaseManufacturerController` methods — this
 * only verifies resolveMshopId() composes them the same way the proven vendor
 * pattern does. Comment-stripped so the fix's own explanatory comments can't
 * satisfy an assertion in place of the code.
 */
final class ManufacturerProductMshopControllerTest extends CIUnitTestCase
{
    private const REL = 'Controllers/Manufacturer/ProductController.php';

    private function code(): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . self::REL)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }

        return $out;
    }

    private function methodBody(string $method): string
    {
        $src = $this->code();
        if (! preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $brace = strpos($src, '{', (int) $m[0][1]);
        if ($brace === false) {
            return '';
        }
        $depth = 0;
        for ($i = $brace, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }

        return '';
    }

    /** Non-owner staff must never have their unit read from the request. */
    public function testNonOwnerIsForcedToTheirEffectiveUnitNeverFromRequest(): void
    {
        $body = $this->methodBody('resolveMshopId');
        $this->assertNotSame('', $body, 'resolveMshopId() not found');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$this->isOwner\(\)\s*\)\s*\{\s*\$effective\s*=\s*\$this->effectiveMshopId\(\);/',
            $body,
            'a non-owner must be forced to effectiveMshopId(), never allowed to post their own mshop_id',
        );

        // getPost('mshop_id') must sit strictly after the non-owner branch's return, i.e.
        // only reachable for an owner.
        $nonOwnerReturn = strpos($body, "return \$effective !== null && \$effective > 0 ? \$effective : null;");
        $postRead       = strpos($body, "getPost('mshop_id')");
        $this->assertNotFalse($nonOwnerReturn, 'the non-owner branch must return before falling through');
        $this->assertNotFalse($postRead, 'the owner branch must read the posted unit');
        $this->assertGreaterThan($nonOwnerReturn, $postRead, 'reading mshop_id from POST must be unreachable for a non-owner');
    }

    /** An owner-posted unit must be validated against allowedMshopIds(), not trusted outright. */
    public function testOwnerPostedUnitIsValidatedAgainstAllowedList(): void
    {
        $body = $this->methodBody('resolveMshopId');

        $this->assertMatchesRegularExpression(
            '/in_array\(\$posted,\s*\$this->allowedMshopIds\(\),\s*true\)/',
            $body,
            'a posted mshop_id must be checked against allowedMshopIds() with strict comparison',
        );
        $this->assertMatchesRegularExpression(
            '/\$posted\s*>\s*0\s*&&\s*in_array/',
            $body,
            'a non-positive posted id must be rejected before the allow-list check even runs',
        );
    }

    /** store() and update() must both hard-stop when no unit resolves — never fall back to "unassigned". */
    public function testStoreAndUpdateHardStopWhenNoUnitResolves(): void
    {
        foreach (['store', 'update'] as $method) {
            $body = $this->methodBody($method);
            $this->assertNotSame('', $body, "{$method}() not found");

            $this->assertMatchesRegularExpression(
                '/\$mshopId\s*=\s*\$this->resolveMshopId\(\);\s*if\s*\(\s*\$mshopId\s*===\s*null\s*\)\s*\{\s*return\s+redirect\(\)/',
                $body,
                "{$method}() must resolve the unit and return immediately when it is null",
            );

            $this->assertStringContainsString(
                '$mshopId,',
                $body,
                "{$method}() must actually pass the resolved unit through to the repository call",
            );
        }
    }
}
