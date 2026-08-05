<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * monline.shiplore.in — the B2B marketplace. Browsing must be public; only the price
 * stays gated behind a resolved buyer session.
 */
final class MonlineTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function catalogMock(): void
    {
        Services::injectMock('monlineCatalogRepository', new class {
            public function products(array $o = [], bool $withPrices = false): array
            {
                $row = ['id' => 9, 'title' => 'Industrial Ball Bearings', 'slug' => 'industrial-ball-bearings', 'category' => 'Components', 'manufacturer_id' => 1, 'manufacturer' => 'Acme Forge', 'variant_id' => 9, 'sku' => 'BB-9', 'min_purchase_qty' => 100, 'qty_step' => 10];
                if ($withPrices) {
                    $row['base_price'] = '12.50';
                }

                return [$row];
            }

            public function countProducts(array $o = []): int
            {
                return 1;
            }

            public function manufacturers(): array
            {
                return [];
            }

            public function categories(): array
            {
                return [];
            }
        });
    }

    public function testAnonymousVisitorSeesCatalogueButNoPrice(): void
    {
        $this->catalogMock();

        $r = $this->get('monline');
        $r->assertStatus(200);

        $body = (string) $r->getBody();
        $this->assertStringContainsString('Industrial Ball Bearings', $body);
        $this->assertStringContainsString('Login to view price', $body);
        $this->assertStringNotContainsString('₹', $body);
    }
}
