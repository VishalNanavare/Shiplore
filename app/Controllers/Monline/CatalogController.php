<?php

declare(strict_types=1);

namespace App\Controllers\Monline;

/**
 * Monline\CatalogController — the monline.shiplore.in entry point.
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
        $repo       = service('monlineCatalogRepository');
        $withPrices = $this->isBuyer(); // the ONLY gate — unchanged from browse()/product()

        return $this->render('monline/home', 'Wholesale marketplace', [
            'products'   => $repo->products($opts, $withPrices),
            'total'      => $repo->countProducts($opts),
            'showPrices' => $withPrices,
            'cartCount'  => $this->isBuyer() ? service('monlineCart')->count() : 0,
        ]);
    }

    public function browse(): string
    {
        $opts = [
            'q'               => trim((string) $this->request->getGet('q')),
            'category'        => trim((string) $this->request->getGet('category')),
            'manufacturer_id' => (int) $this->request->getGet('manufacturer'),
            'limit'           => 48,
        ];

        $repo = service('monlineCatalogRepository');

        // The ONLY place prices are opted into: a resolved buyer. A logged-out visitor
        // gets rows with no price keys at all — not zeroed, not hidden by the template.
        $withPrices = $this->isBuyer();

        return $this->render('monline/browse', 'Wholesale catalogue', [
            'products'      => $repo->products($opts, $withPrices),
            'total'         => $repo->countProducts($opts),
            'manufacturers' => $repo->manufacturers(),
            'filters'       => $opts,
            'showPrices'    => $withPrices,
            'cartCount'     => $this->isBuyer() ? service('monlineCart')->count() : 0,
        ]);
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
