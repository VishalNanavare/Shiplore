<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Production regression (introduced by commit 78041ff, found live on shiplore.in).
 *
 * StoreShopRepository::nearby() bounding-box pre-filter gained a
 * `limit(max($limit * 5, 500))` safety valve with NO ORDER BY. MySQL is then free
 * to return an arbitrary slice — in practice lowest-id-first — so on an install
 * with ~10k active shops the box held far more rows than the cap and the slice
 * never contained the genuinely nearest shops. Result: nearby() returned [],
 * nearbyShopIds() returned [], StoreCatalogRepository filtered to
 * `shop_id IN (0)`, and the entire storefront rendered "No shops deliver to your
 * location yet" for a location with a deliverable shop 900 m away.
 *
 * The cap itself is fine; truncating an UNORDERED set is not.
 */
final class NearbyShopOrderingTest extends CIUnitTestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

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

    /** Any LIMIT on the bounding-box query must be preceded by a proximity ORDER BY. */
    public function testBoundingBoxLimitIsOrderedByProximity(): void
    {
        $body = $this->methodBody($this->read('Models/StoreShopRepository.php'), 'nearby');
        $this->assertNotSame('', $body, 'nearby() not found');

        $limitPos = strpos($body, 'limit(max(');
        if ($limitPos === false) {
            // No cap at all is also correct (the original pre-regression behaviour):
            // every row in the box reaches the Haversine loop.
            $this->assertStringNotContainsString('limit(max(', $body);

            return;
        }

        $this->assertStringContainsString(
            'orderBy($order',
            $body,
            'the capped bounding-box query must ORDER BY proximity, or truncation drops the nearest shops',
        );

        $orderPos = strpos($body, 'orderBy($order');
        $this->assertNotFalse($orderPos);
        $this->assertLessThan($limitPos, $orderPos, 'the ORDER BY must be applied before the LIMIT');
    }

    /** The ordering expression must actually sort by distance from the caller's point. */
    public function testOrderingExpressionUsesBothCoordinates(): void
    {
        $body = $this->methodBody($this->read('Models/StoreShopRepository.php'), 'nearby');

        $this->assertMatchesRegularExpression(
            '/POW\(s\.latitude[^)]*\)\s*\+\s*POW\(\(s\.longitude/',
            $body,
            'ordering must use squared distance on BOTH latitude and longitude',
        );
        // A degree of longitude shrinks with latitude; without the cos(lat) scale the
        // ordering skews east-west and can still drop a nearer shop.
        $this->assertStringContainsString(
            'cos(deg2rad($lat))',
            $body,
            'longitude must be scaled by cos(latitude) so the ordering is not skewed east-west',
        );
    }
}
