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
        return $this->render('msonline/home', 'Wholesale marketplace');
    }
}
