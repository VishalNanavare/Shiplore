<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Phase 8 — customer storefront: home, catalog, product (SEO/schema), nearby
 * shops, location, cart, checkout guard, track, account guard. Repos mocked.
 */
final class StorefrontTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function catalogMock(): void
    {
        Services::injectMock('storeCatalogRepository', new class {
            public function categories(int $l = 12): array { return [['id' => 1, 'name' => 'Footwear', 'slug' => 'footwear', 'parent_id' => null, 'level' => 0]]; }
            public function products(array $o = []): array { return [['id' => 3, 'title' => 'Running Sneakers', 'slug' => 'running-sneakers', 'category' => 'Footwear', 'category_slug' => 'footwear', 'vendor' => 'Sole Mate', 'base_price' => '2499', 'mrp' => '3999', 'variant_id' => 3]]; }
            public function findBySlug(string $s): ?array { return $s === 'running-sneakers' ? ['id' => 3, 'title' => 'Running Sneakers', 'slug' => 'running-sneakers', 'description' => 'Lightweight shoes', 'category' => 'Footwear', 'category_slug' => 'footwear', 'vendor_id' => 1, 'vendor' => 'Sole Mate', 'variant_id' => 3, 'sku' => 'SK1', 'base_price' => '2499', 'mrp' => '3999', 'tax_code' => 'GST_12'] : null; }
            public function reviews(int $id, int $l = 10): array { return [['rating' => 5, 'title' => 'Great', 'body' => 'Comfy', 'created_at' => '2026-06-01', 'author' => 'Aarav']]; }
            public function relatedProducts(int $id, array $t = [], int $l = 8): array { return []; }
            public function labels(int $id): array { return [['code' => 'featured', 'name' => 'Featured', 'color' => 'primary']]; }
            public function highlights(int $id): array { return ['Breathable mesh', 'Lightweight']; }
            public function faqs(int $id): array { return [['question' => 'Washable?', 'answer' => 'Yes']]; }
            public function purchaseRules(int $id): array { return ['min_purchase_qty' => null, 'max_purchase_qty' => null, 'qty_step' => 1]; }
            public function purchaseRulesForVariant(int $v): array { return ['payment_restriction' => 'both', 'qty_step' => 1]; }
        });
    }

    private function shopMock(): void
    {
        Services::injectMock('storeShopRepository', new class {
            public function nearby(?float $lat, ?float $lng, int $l = 30): array { return [['id' => 1, 'name' => 'Sole Mate — Andheri', 'pincode' => '400058', 'vendor' => 'Sole Mate', 'business_type' => 'Footwear', 'delivery_radius_km' => '10.0', 'prep_time_min' => 30, 'distance_km' => 4.2]]; }
            public function find(int $id): ?array { return $id === 1 ? ['id' => 1, 'name' => 'Sole Mate — Andheri', 'vendor' => 'Sole Mate', 'business_type' => 'Footwear', 'pincode' => '400058', 'state_code' => '27', 'delivery_radius_km' => '10.0', 'prep_time_min' => 30, 'pickup_enabled' => 1] : null; }
        });
    }

    public function testHomeRenders(): void
    {
        $this->catalogMock();
        $this->shopMock();
        $r = $this->get('store');
        $r->assertStatus(200);
        $this->assertStringContainsString('Shop your neighbourhood', (string) $r->getBody());
    }

    public function testRootServesStorefront(): void
    {
        $this->catalogMock();
        $this->shopMock();
        $r = $this->get('/'); // root is now the ecommerce homepage, not the staff login
        $r->assertStatus(200);
        $this->assertStringContainsString('Shop your neighbourhood', (string) $r->getBody());
    }

    public function testProductsBrowseRenders(): void
    {
        $this->catalogMock();
        $r = $this->get('store/products');
        $r->assertStatus(200);
        $this->assertStringContainsString('Running Sneakers', (string) $r->getBody());
    }

    public function testProductDetailHasSchemaJsonLd(): void
    {
        $this->catalogMock();
        Services::injectMock('mediaRepository', new class {
            public function forProduct(int $id): array { return []; }
        });
        $r = $this->get('store/product/running-sneakers');
        $r->assertStatus(200);
        $body = (string) $r->getBody();
        $this->assertStringContainsString('application/ld+json', $body);
        $this->assertStringContainsString('"@type":"Product"', $body);
        $this->assertStringContainsString('Running Sneakers', $body);
    }

    public function testNearbyShopsRender(): void
    {
        $this->shopMock();
        $r = $this->get('store/shops');
        $r->assertStatus(200);
        $this->assertStringContainsString('Sole Mate', (string) $r->getBody());
    }

    public function testSetLocationRedirectsToShops(): void
    {
        $data = [csrf_token() => csrf_hash(), 'lat' => '19.11', 'lng' => '72.85', 'label' => 'Home', 'pincode' => '400058'];
        $r = $this->withSession(service('session')->get())->post('store/location', $data);
        $r->assertRedirect();
        $this->assertStringContainsString('store/shops', $r->getRedirectUrl());
    }

    public function testCartAddRedirects(): void
    {
        // addToCart now checks the product's purchase rules before adding
        Services::injectMock('storeCatalogRepository', new class {
            public function purchaseRulesForVariant(int $v): array { return ['qty_step' => 1, 'payment_restriction' => 'both']; }
        });
        Services::injectMock('cartService', new class {
            public function add(int $v, int $q): void {}
        });
        $data = [csrf_token() => csrf_hash(), 'variant_id' => '3', 'qty' => '2'];
        $r = $this->withSession(service('session')->get())->post('store/cart/add', $data);
        $r->assertRedirect();
        $this->assertStringContainsString('store/cart', $r->getRedirectUrl());
    }

    public function testEmptyCartRenders(): void
    {
        Services::injectMock('cartService', new class {
            public function items(): array { return []; }
            public function count(): int { return 0; }
            public function totals(array $i, float $p = 0.0, $g = null): array { return ['subtotal' => '0.00', 'discount' => '0.00', 'taxable' => '0.00', 'tax' => '0.00', 'delivery' => '0.00', 'grand' => '0.00', 'count' => 0]; }
        });
        $r = $this->get('store/cart');
        $r->assertStatus(200);
        $this->assertStringContainsString('Your cart is empty', (string) $r->getBody());
    }

    public function testCheckoutEmptyRedirectsToCart(): void
    {
        Services::injectMock('cartService', new class {
            public function items(): array { return []; }
            public function count(): int { return 0; }
        });
        $r = $this->get('store/checkout');
        $r->assertRedirect();
        $this->assertStringContainsString('store/cart', $r->getRedirectUrl());
    }

    public function testTrackFormRenders(): void
    {
        $this->get('store/track')->assertStatus(200);
    }

    public function testLoginFormRenders(): void
    {
        $this->get('store/login')->assertStatus(200);
    }

    public function testAccountRequiresLogin(): void
    {
        $r = $this->get('store/account');
        $r->assertRedirect();
        $this->assertStringContainsString('store/login', $r->getRedirectUrl());
    }
}
