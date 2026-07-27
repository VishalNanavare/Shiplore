<?php

declare(strict_types=1);

namespace App\Controllers\Msonline;

/**
 * Msonline\CatalogController — the msonline.shiplore.in entry point.
 *
 * This exists NOW, ahead of the full B2B marketplace, for a specific reason: without a
 * route bound to the `msonline` subdomain, the bare '/' falls through to the apex
 * route (Store\StoreController::home) and msonline.shiplore.in serves the CONSUMER
 * storefront — wrong catalogue, wrong audience, and consumer pricing on a B2B hostname.
 *
 * So home() is a deliberate, honest placeholder: it identifies the surface, signs
 * buyers in, and shows nothing it cannot yet show correctly. Ordering, cart and catalog
 * browse land with the rest of phase B.
 */
final class CatalogController extends BaseMsonlineController
{
    public function home(): string
    {
        // Signed-out visitors get the sign-in gate; buyers go straight to the catalogue.
        if (! $this->isBuyer()) {
            return $this->render('msonline/home', 'Wholesale marketplace');
        }

        return $this->browse();
    }

    public function browse(): string
    {
        $opts = [
            'q'               => trim((string) $this->request->getGet('q')),
            'category'        => trim((string) $this->request->getGet('category')),
            'manufacturer_id' => (int) $this->request->getGet('manufacturer'),
            'limit'           => 48,
        ];

        $repo = service('msonlineCatalogRepository');

        // The ONLY place prices are opted into: a resolved buyer. A logged-out visitor
        // gets rows with no price keys at all — not zeroed, not hidden by the template.
        $withPrices = $this->isBuyer();

        return $this->render('msonline/browse', 'Wholesale catalogue', [
            'products'      => $repo->products($opts, $withPrices),
            'total'         => $repo->countProducts($opts),
            'manufacturers' => $repo->manufacturers(),
            'filters'       => $opts,
            'showPrices'    => $withPrices,
            'cartCount'     => $this->isBuyer() ? service('msonlineCart')->count() : 0,
        ]);
    }

    public function product(string $slug)
    {
        $withPrices = $this->isBuyer();
        $product    = service('msonlineCatalogRepository')->findBySlug($slug, $withPrices);

        if ($product === null) {
            return redirect()->to('msonline/browse')->with('error', 'Product not found.');
        }

        return $this->render('msonline/product', (string) $product['title'], [
            'product'    => $product,
            'showPrices' => $withPrices,
            'cartCount'  => $this->isBuyer() ? service('msonlineCart')->count() : 0,
        ]);
    }
}
