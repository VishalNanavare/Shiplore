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

    /** Everything empty — the state a pre-launch visitor actually lands on. */
    private function emptyCatalogMock(): void
    {
        Services::injectMock('monlineCatalogRepository', new class {
            public function products(array $o = [], bool $withPrices = false): array { return []; }

            public function countProducts(array $o = []): int { return 0; }

            public function manufacturers(): array { return []; }

            public function categories(): array { return []; }
        });
    }

    /**
     * A zero-data homepage must still look designed. Three stacked grey empty-state
     * icons and a "0 products available" heading is what made the operator call the
     * page broken — sections with nothing in them must not render at all, leaving one
     * deliberate panel with a way forward.
     */
    public function testEmptyHomepageRendersOneDesignedStateNotThreeEmptyShells(): void
    {
        $this->emptyCatalogMock();

        $body = (string) $this->get('monline')->getBody();

        // Assert on the section HEADINGS, not on the old empty-state copy: that copy was
        // deleted outright, so asserting its absence would pass even if the guard were
        // removed. A heading with nothing under it IS the empty shell.
        $this->assertStringNotContainsString('Shop by category', $body, 'an empty category strip must not render its heading');
        $this->assertStringNotContainsString('Featured manufacturers', $body, 'an empty manufacturer grid must not render its heading');
        $this->assertStringNotContainsString('Manufacturers now onboarding', $body);
        $this->assertStringNotContainsString('0 products available', $body, 'a zero count must not be the section heading');

        // The one designed empty state, with a real next action.
        $this->assertStringContainsString('mo-empty-card', $body);
        $this->assertStringContainsString('Become a manufacturer', $body);
    }

    /** Evergreen content must render regardless of catalogue state, or the page is bare pre-launch. */
    public function testEmptyHomepageStillExplainsTheProposition(): void
    {
        $this->emptyCatalogMock();

        $body = (string) $this->get('monline')->getBody();

        $this->assertStringContainsString('How wholesale on', $body);
        $this->assertStringContainsString('Raise a purchase order', $body);
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
