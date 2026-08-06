<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * `api/v1/vendor/*` financial and staff endpoints must be owner-only, matching the
 * web panel where a shop-scoped staffer never sees a sibling branch's money or
 * roster (audit H3 part 1). `JwtAuthFilter` only proves a valid vendor session —
 * it does not distinguish an owner from branch staff — so each of these methods
 * must call the `isOwner()` predicate the class already used for
 * createStaff/updateStaff/updateBusinessProfile before this fix.
 *
 * Part 2 of H3 (wiring `perm:` onto the routes themselves) is a staged,
 * log-only-first rollout and is out of scope here — see the audit report.
 */
final class VendorApiOwnerGateTest extends CIUnitTestCase
{
    private const REL = 'Controllers/Api/V1/VendorApiController.php';

    /** Methods H3 identifies as vendor-wide money or staff data, reachable by any authenticated vendor session today. */
    private const OWNER_ONLY_METHODS = [
        'settlements',
        'settlement',
        'commissionLedger',
        'gstSummary',
        'staffList',
        'transfers',
        'createTransfer',
        'transferAction',
    ];

    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

    /** Crude brace-matching body extractor — same convention as AuthSessionHardeningTest. */
    private function methodBody(string $src, string $method): string
    {
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

    public function testEachOwnerOnlyEndpointCallsIsOwner(): void
    {
        $src = $this->read(self::REL);

        foreach (self::OWNER_ONLY_METHODS as $method) {
            $body = $this->methodBody($src, $method);
            $this->assertNotSame(
                '',
                $body,
                self::REL . "::{$method}() not found — has it been renamed or removed?",
            );
            $this->assertStringContainsString(
                'isOwner()',
                $body,
                self::REL . "::{$method}() must refuse non-owner vendor sessions, or any shop staffer's JWT reaches vendor-wide money/staff data",
            );

            // The gate must precede the method's first real data access (the
            // vendorId()/notVendor() pair at the top doesn't count — every method here
            // has that already and it isn't the ownership check).
            $ownerCheck  = strpos($body, 'isOwner()');
            $notVendor   = strpos($body, 'notVendor()');
            $this->assertNotFalse($ownerCheck);
            if ($notVendor !== false) {
                $this->assertGreaterThan($notVendor, $ownerCheck, self::REL . "::{$method}() must check isOwner() after resolving vendorId(), not before");
            }
        }
    }
}
