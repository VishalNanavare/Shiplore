<?php

declare(strict_types=1);

namespace App\Controllers\Monline;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Monline\CatalogController — the monline.<domain> entry point.
 *
 * Browsing is public: anyone can see manufacturer products, images, categories and MOQ.
 * The price is the only thing gated, and only behind a resolved buyer session — same
 * rule as browse() and product() below.
 */
final class CatalogController extends BaseMonlineController
{
    public function home(): string
    {
        $opts       = ['limit' => 24]; // landing teaser, smaller than browse()'s 48
        $point      = $this->buyerPoint();
        if ($point !== null) {
            $opts['sort_lat'] = $point['lat'];
            $opts['sort_lng'] = $point['lng'];
        }

        $repo       = service('monlineCatalogRepository');
        $withPrices = $this->isBuyer(); // the ONLY gate — unchanged from browse()/product()

        return $this->render('monline/home', 'Wholesale marketplace', [
            'products'      => $repo->products($opts, $withPrices),
            'total'         => $repo->countProducts($opts),
            'showPrices'    => $withPrices,
            'manufacturers' => $repo->manufacturers(),
            'cartCount'     => $this->isBuyer() ? service('monlineCart')->count() : 0,
        ]);
    }

    public function browse(): string
    {
        $limit = 48;
        $page  = max(1, (int) $this->request->getGet('page'));

        $opts = [
            'q'               => trim((string) $this->request->getGet('q')),
            'category'        => trim((string) $this->request->getGet('category')),
            'manufacturer_id' => (int) $this->request->getGet('manufacturer'),
            'limit'           => $limit,
        ];

        $point = $this->buyerPoint();
        if ($point !== null) {
            $opts['sort_lat'] = $point['lat'];
            $opts['sort_lng'] = $point['lng'];
        }

        $repo  = service('monlineCatalogRepository');
        $total = $repo->countProducts($opts);

        // Clamp the page BEFORE it becomes an OFFSET. $page had no ceiling, so
        // ?page=1000000 became LIMIT 48 OFFSET 47999952 — MySQL still generates and
        // discards every one of those rows.
        $pages          = max(1, (int) ceil($total / $limit));
        $page           = min($page, $pages);
        $opts['offset'] = ($page - 1) * $limit;

        // The ONLY place prices are opted into: a resolved buyer. A logged-out visitor
        // gets rows with no price keys at all — not zeroed, not hidden by the template.
        $withPrices = $this->isBuyer();

        return $this->render('monline/browse', 'Wholesale catalogue', [
            'products'      => $repo->products($opts, $withPrices),
            'total'         => $total,
            'manufacturers' => $repo->manufacturers(),
            'filters'       => $opts,
            'showPrices'    => $withPrices,
            'cartCount'     => $this->isBuyer() ? service('monlineCart')->count() : 0,
            'page'          => $page,
            'pages'         => $pages,
        ]);
    }

    /**
     * Set a monline-only location override for the proximity sort. Buyer-only — an
     * anonymous visitor has nothing to sort by (decision: no picker before login).
     * Never touches the cart: monline sorts by distance, it never filters by it, so
     * unlike the consumer storefront's setLocation() there is nothing to drop.
     */
    public function setLocation(): RedirectResponse
    {
        if (! $this->isBuyer()) {
            return redirect()->to('monline')->with('error', 'Sign in to set a location.');
        }

        $lat    = (float) $this->request->getPost('lat');
        $lng    = (float) $this->request->getPost('lng');
        $label  = trim((string) $this->request->getPost('label')) ?: 'Selected location';
        $return = (string) $this->request->getPost('return');

        if ($lat === 0.0 && $lng === 0.0) {
            return redirect()->back()->with('error', 'Choose a location on the map.');
        }

        service('monlineLocationService')->set($lat, $lng, $label);

        $dest = (str_starts_with($return, '/') && ! str_starts_with($return, '//')) ? $return : 'monline/browse';

        return redirect()->to($dest)->with('success', 'Sorting products near ' . $label . '.');
    }

    /** Back to the buyer's own shop as the sort point. */
    public function clearLocation(): RedirectResponse
    {
        service('monlineLocationService')->clear();

        $return = (string) $this->request->getPost('return');
        $dest   = (str_starts_with($return, '/') && ! str_starts_with($return, '//')) ? $return : 'monline/browse';

        return redirect()->to($dest)->with('success', 'Back to your shop’s location.');
    }

    public function product(string $slug)
    {
        $withPrices = $this->isBuyer();
        $product    = service('monlineCatalogRepository')->findBySlug($slug, $withPrices);

        if ($product === null) {
            return redirect()->to('monline/browse')->with('error', 'Product not found.');
        }

        return $this->render('monline/product', (string) $product['title'], [
            'product'    => $product,
            'showPrices' => $withPrices,
            'cartCount'  => $this->isBuyer() ? service('monlineCart')->count() : 0,
        ]);
    }
}
