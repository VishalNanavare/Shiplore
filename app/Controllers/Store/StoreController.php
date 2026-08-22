<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * StoreController — storefront browse: home, location, nearby shops (delivery-
 * radius filtered), category/product browse with filter+sort+search, SEO +
 * schema.org product detail, and the session wishlist.
 */
final class StoreController extends BaseStoreController
{
    public function home(): string
    {
        $loc  = service('locationService')->get();
        $repo = service('storeCatalogRepository');
        $cats = $repo->rootCategoryFacets($this->scoped($loc, []), 12);

        // Curated rails (Blinkit/Amazon-style) instead of one flat product grid.
        $rails = [];
        $deals = $repo->products($this->scoped($loc, ['on_offer' => 1, 'sort' => 'none', 'limit' => 12]));
        if ($deals !== []) {
            $rails[] = ['title' => 'Top deals', 'icon' => 'tag-fill', 'link' => site_url('store/products?on_offer=1'), 'products' => $deals];
        }
        // A rail for each of the most-stocked nearby top-level categories (subtree).
        foreach (array_slice($cats, 0, 5) as $cat) {
            $ps = $repo->products($this->scoped($loc, ['category' => $cat['slug'], 'sort' => 'none', 'limit' => 12]));
            if (count($ps) >= 3) {
                $rails[] = ['title' => $cat['name'], 'icon' => 'grid', 'link' => site_url('store/category/' . $cat['slug']), 'products' => $ps];
            }
        }

        return view('store/home', [
            'title'      => 'Home',
            'metaDesc'   => 'Shop from nearby stores on ' . service('settingsRepository')->brandName() . ' — your local multi-vendor marketplace with fast delivery.',
            'categories' => $cats,
            'shops'      => service('storeShopRepository')->nearby($loc['lat'] ?? null, $loc['lng'] ?? null, 6),
            'rails'      => $rails,
            'products'   => $repo->products($this->scoped($loc, ['limit' => 18])),
            'location'   => $loc,
        ]);
    }

    /**
     * Add nearby-shop scoping to a products() option set when the customer has a
     * location (delivery-radius visibility). No location → browse the full catalog.
     *
     * @param array<string,mixed>|null $loc
     * @param array<string,mixed>      $opts
     * @return array<string,mixed>
     */
    private function scoped(?array $loc, array $opts): array
    {
        if ($loc !== null) {
            // Exact shop set drives the product LIST (must be exact); the coarse GPS
            // bucket keys the shared, cached COUNTS (FacetCache) for everyone nearby.
            $opts['shop_ids'] = service('storeShopRepository')->nearbyShopIds($loc['lat'] ?? null, $loc['lng'] ?? null);
            $opts['bucket']   = \App\Libraries\Store\GeoBucket::keyFor($loc['lat'] ?? null, $loc['lng'] ?? null);
        }

        return $opts;
    }

    public function location(): string
    {
        return view('store/location', [
            'title'    => 'Set your location',
            'location' => service('locationService')->get(),
            'presets'  => [
                ['Mumbai (Andheri)', 19.1197, 72.8468, '400058'],
                ['Mumbai (Powai)', 19.1170, 72.9050, '400076'],
                ['Thane', 19.2183, 72.9781, '400601'],
                ['Bengaluru (Koramangala)', 12.9352, 77.6245, '560034'],
            ],
        ]);
    }

    public function setLocation(): RedirectResponse
    {
        $lat    = (float) $this->request->getPost('lat');
        $lng    = (float) $this->request->getPost('lng');
        $label  = trim((string) $this->request->getPost('label')) ?: 'Selected location';
        $pin    = trim((string) $this->request->getPost('pincode')) ?: null;
        $return = (string) $this->request->getPost('return');
        // Reverse-geocoded address components (auto-fill checkout): city / GST state
        // code / street line / full formatted address.
        $extra  = [
            'city'       => trim((string) $this->request->getPost('city')),
            'state_code' => trim((string) $this->request->getPost('state_code')),
            'line1'      => trim((string) $this->request->getPost('line1')),
            'formatted'  => trim((string) $this->request->getPost('formatted')),
        ];

        if ($lat === 0.0 && $lng === 0.0) {
            return redirect()->back()->with('error', 'Choose a location or enter coordinates.');
        }
        service('locationService')->set($lat, $lng, $label, $pin, $extra);
        // A browse-location change invalidates any address chosen at checkout.
        session()->remove('checkout_addr_ready');

        // Changing the delivery location can leave cart items that no shop can
        // deliver to the new spot — drop ONLY those (HR1: deliverable items from
        // other shops stay). Group the removals by reason for a clear message.
        $removed = service('cartService')->removeUndeliverable($lat, $lng);
        $msg     = 'Delivering to ' . $label . '.';
        if ($removed !== []) {
            $msg .= ' ' . \App\Libraries\Store\DeliveryMessages::removedSummary($removed);
        }

        // Return to where the picker was opened (modal lives on every page), else home.
        $dest = (str_starts_with($return, '/') && ! str_starts_with($return, '//')) ? $return : 'store';

        return redirect()->to($dest)->with('success', $msg);
    }

    public function shops(): string
    {
        $loc = service('locationService')->get();

        return view('store/shops', [
            'title'    => 'Nearby Shops',
            'metaDesc' => 'Find shops near you that deliver to your location.',
            'location' => $loc,
            'shops'    => service('storeShopRepository')->nearby($loc['lat'] ?? null, $loc['lng'] ?? null, 40),
        ]);
    }

    public function shop(int $id): string
    {
        $shop = service('storeShopRepository')->find($id);
        if ($shop === null) {
            throw PageNotFoundException::forPageNotFound('Shop not found');
        }

        // Every shop renders as the standard filtered product browse, scoped to it.
        $opts             = $this->browseOpts(null);
        $opts['shop_ids'] = [$id];

        $data           = $this->browseView($shop['name'], 'Shop ' . $shop['name'] . ' on ' . service('settingsRepository')->brandName() . '.', $opts, service('locationService')->get());
        $data['shop']   = $shop;
        $data['shopId'] = $id;
        $data['action'] = site_url('store/shop/' . $id);

        return view('store/shop', $data);
    }

    public function category(string $slug): string
    {
        $loc  = service('locationService')->get();
        $opts = $this->browseOpts($loc, $slug);
        $data = $this->browseView('Category', 'Browse products by category on ' . service('settingsRepository')->brandName() . '.', $opts, $loc);
        $data['action'] = site_url('store/products');

        return view('store/products', $data);
    }

    public function products(): string
    {
        $loc   = service('locationService')->get();
        $opts  = $this->browseOpts($loc);
        $title = ($opts['q'] ?? '') !== '' ? 'Search: ' . $opts['q'] : 'All Products';
        $data  = $this->browseView($title, 'Browse and search products on ' . service('settingsRepository')->brandName() . '.', $opts, $loc);
        $data['action'] = site_url('store/products');

        return view('store/products', $data);
    }

    /**
     * Gather browse filters (search, category, brand, type, sort, price range,
     * in-stock, on-offer, page) from the request and add nearby-shop scoping.
     * per-page is fixed at 24.
     * @param array<string,mixed>|null $loc
     * @return array<string,mixed>
     */
    private function browseOpts(?array $loc, ?string $forceCategory = null): array
    {
        $r    = $this->request;
        $minP = $r->getGet('min_price');
        $maxP = $r->getGet('max_price');

        return $this->scoped($loc, [
            'q'            => $forceCategory !== null ? '' : trim((string) $r->getGet('q')),
            'category'     => $forceCategory ?? (string) $r->getGet('category'),
            'brand'        => is_numeric($r->getGet('brand')) ? (int) $r->getGet('brand') : '',
            'product_type' => trim((string) $r->getGet('type')),
            'sort'         => (string) $r->getGet('sort'),
            'min_price'    => $minP !== null && $minP !== '' ? (string) $minP : '',
            'max_price'    => $maxP !== null && $maxP !== '' ? (string) $maxP : '',
            'in_stock'     => $r->getGet('in_stock') ? 1 : 0,
            'on_offer'     => $r->getGet('on_offer') ? 1 : 0,
            'page'         => max(1, (int) $r->getGet('page')),
            'limit'        => 24,
        ]);
    }

    /** @param array<string,mixed> $opts @param array<string,mixed>|null $loc @return array<string,mixed> */
    private function browseView(string $title, string $metaDesc, array $opts, ?array $loc): array
    {
        $repo  = service('storeCatalogRepository');
        $total = $repo->countProducts($opts);

        return [
            'title'       => $title,
            'metaDesc'    => $metaDesc,
            'products'    => $repo->products($opts),
            'catFacets'   => $repo->categoryFacets($opts),
            'brandFacets' => $repo->brandFacets($opts),
            'typeFacets'  => $repo->typeFacets($opts),
            'priceBounds' => $repo->priceBounds($opts),
            'activeCat'   => $opts['category'] ?? '',
            'activeBrand' => $opts['brand'] ?? '',
            'activeType'  => $opts['product_type'] ?? '',
            'inStock'     => ! empty($opts['in_stock']),
            'onOffer'     => ! empty($opts['on_offer']),
            'q'           => $opts['q'] ?? '',
            'location'    => $loc,
            'total'       => $total,
            'page'        => (int) $opts['page'],
            'perPage'     => (int) $opts['limit'],
            'minPrice'    => $opts['min_price'] ?? '',
            'maxPrice'    => $opts['max_price'] ?? '',
            'sort'        => $opts['sort'] ?? '',
            'action'      => site_url('store/products'),
            'shopId'      => null,
        ];
    }

    public function product(string $slug): string
    {
        $repo    = service('storeCatalogRepository');
        $product = $repo->findBySlug($slug);
        if ($product === null) {
            throw PageNotFoundException::forPageNotFound('Product not found');
        }

        $pid = (int) $product['id'];
        $loc = service('locationService')->get();
        // Related = same-category products that ALSO deliver to the customer's
        // location (nearby only). Falls back to curated relations first.
        $related = $repo->relatedProducts($pid);
        if ($related === []) {
            $related = $repo->products($this->scoped($loc, ['category' => $product['category_slug'], 'limit' => 12]));
        }
        $related = array_values(array_filter($related, static fn ($r) => ($r['slug'] ?? '') !== $slug));
        $rules   = $repo->purchaseRules($pid);

        return view('store/product', [
            'title'     => $product['title'],
            'metaTitle' => $product['title'] . ' · ' . service('settingsRepository')->brandName(),
            'metaDesc'  => mb_substr(strip_tags((string) ($product['description'] ?? $product['title'])), 0, 155),
            'product'   => $product,
            'reviews'   => $repo->reviews($pid),
            'related'   => $related,
            'images'    => service('mediaRepository')->forProduct($pid),
            'labels'    => $repo->labels($pid),
            'highlights' => $repo->highlights($pid),
            'faqs'      => $repo->faqs($pid),
            'rules'     => $rules,
            'content'   => $repo->content($pid),
            'specs'     => $repo->specifications($pid),
            'variants'  => $repo->variants($pid),
            'variantMatrix' => $repo->variantMatrix($pid),
            'location'  => $loc,
        ]);
    }

    /**
     * Quick-add variant picker (Blinkit-style bottom sheet) for a multi-variant
     * product — returns an HTML partial listing each variant with its price + a
     * stepper, loaded over AJAX from the product card. Guarded to purchasable
     * products only.
     */
    public function variantsSheet(int $id): string
    {
        $repo  = service('storeCatalogRepository');
        $title = $repo->publishedTitle($id);
        if ($title === null) {
            throw PageNotFoundException::forPageNotFound('Product not found');
        }
        $vars = $repo->variants($id);
        foreach ($vars as &$v) {
            $st       = $repo->variantStock((int) $v['variant_id']);
            $v['out'] = $st['mode'] === 'managed' && ! $st['backorder'] && $st['tracked'] && $st['available'] <= 0;
        }
        unset($v);

        return view('partials/_store_variant_sheet', ['vars' => $vars, 'title' => $title]);
    }

    // ---- Wishlist (session) ----
    public function wishlist(): string
    {
        $ids   = array_values(array_unique(session()->get('store_wishlist') ?: []));
        $items = $ids === [] ? [] : service('storeCatalogRepository')->products(['limit' => 60]);
        $items = array_values(array_filter($items, static fn ($p) => in_array((int) $p['id'], array_map('intval', $ids), true)));

        return view('store/wishlist', ['title' => 'Wishlist', 'items' => $items]);
    }

    public function wishlistAdd(): RedirectResponse
    {
        $id = (int) $this->request->getPost('product_id');
        $w  = session()->get('store_wishlist') ?: [];
        if ($id > 0 && ! in_array($id, $w, true)) {
            $w[] = $id;
            session()->set('store_wishlist', $w);
        }

        return redirect()->back()->with('success', 'Added to wishlist.');
    }

    public function wishlistRemove(): RedirectResponse
    {
        $id = (int) $this->request->getPost('product_id');
        $w  = array_values(array_filter(session()->get('store_wishlist') ?: [], static fn ($x) => (int) $x !== $id));
        session()->set('store_wishlist', $w);

        return redirect()->to('store/wishlist')->with('success', 'Removed from wishlist.');
    }
}
