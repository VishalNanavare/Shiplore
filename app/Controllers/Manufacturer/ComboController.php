<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Manufacturer\ComboController — several items sold as one.
 *
 * Forked from Vendor\ComboController for the reason set out in
 * ManufacturerComboRepository: the vendor version can publish a combo to the consumer
 * storefront and prices it with MRP, both of which are wrong for a manufacturer.
 *
 * @see \App\Controllers\Vendor\ComboController the vendor counterpart
 */
final class ComboController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.combo.manage')) {
            return $denied;
        }

        return $this->render('manufacturer/combos/index', 'combos', 'Combo Offers', [
            'combos' => service('manufacturerComboRepository')->list((int) $this->manufacturerId()),
            'units'  => $this->mshopOptions(),
        ]);
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.combo.manage')) {
            return $denied;
        }

        $pid = service('manufacturerComboRepository')->create(
            (int) $this->manufacturerId(),
            (array) $this->request->getPost(),
            (int) session()->get('user_id'),
        );

        // create() returns null for every rejection — too few components, a foreign
        // component, or a price pair that fails the making < selling invariant. The
        // message names all three rather than guessing which one it was, since the
        // repository deliberately does not distinguish them to the caller.
        return $pid === null
            ? redirect()->back()->withInput()->with(
                'error',
                'Could not create the combo. It needs at least two of your own items, and a making price below the selling price.',
            )
            : redirect()->to('manufacturer/products/' . $pid . '/variants')
                ->with('success', 'Combo created as a draft. Set stock and submit it for approval.');
    }
}
