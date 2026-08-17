<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Manufacturer\UnitController — the manufacturer's units (factories), stored in `mshops`.
 *
 * The vendor counterpart (Vendor\ShopController) also manages delivery radius, opening
 * hours and holidays. None of that exists here: `mshops` has no delivery columns at all,
 * so requirement 2 ("manufacturer cannot set a range of delivery") is enforced by the
 * schema rather than by a check that could later be bypassed.
 *
 * Every method taking a unit id calls requireMshopAccess() right after requireManufacturer():
 * owning the manufacturer is not enough, because staff are scoped to assigned units.
 */
final class UnitController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.unit.view')) {
            return $denied;
        }

        $allowed = $this->allowedMshopIds();
        $units   = array_values(array_filter(
            service('manufacturerUnitRepository')->list((int) $this->manufacturerId()),
            static fn (array $u): bool => in_array((int) $u['id'], $allowed, true),
        ));

        return $this->render('manufacturer/units/index', 'units', 'Units', ['units' => $units]);
    }

    public function new()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.unit.create')) {
            return $denied;
        }

        return $this->render('manufacturer/units/form', 'units', 'New Unit', [
            'unit'   => null,
            'states' => \App\Libraries\Geo\IndiaStates::list(),
        ]);
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.unit.create')) {
            return $denied;
        }

        $id = service('manufacturerUnitRepository')->create(
            (int) $this->manufacturerId(),
            (array) $this->request->getPost(),
            (int) session()->get('user_id'),
        );

        if ($id === null) {
            return redirect()->back()->withInput()->with('error', 'Could not create the unit. Name is required.');
        }

        return redirect()->to('manufacturer/units')->with('success', 'Unit created.');
    }

    public function edit(int $mshopId)
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.unit.update')) {
            return $denied;
        }
        if ($denied = $this->requireMshopAccess($mshopId)) {
            return $denied;
        }

        $unit = service('manufacturerUnitRepository')->findById($mshopId, (int) $this->manufacturerId());
        if ($unit === null) {
            return redirect()->to('manufacturer/units')->with('error', 'Unit not found.');
        }

        return $this->render('manufacturer/units/form', 'units', 'Edit Unit', [
            'unit'   => $unit,
            'states' => \App\Libraries\Geo\IndiaStates::list(),
        ]);
    }

    public function update(int $mshopId): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.unit.update')) {
            return $denied;
        }
        if ($denied = $this->requireMshopAccess($mshopId)) {
            return $denied;
        }

        $ok = service('manufacturerUnitRepository')->update(
            $mshopId,
            (int) $this->manufacturerId(),
            (array) $this->request->getPost(),
            (int) session()->get('user_id'),
        );

        return $ok
            ? redirect()->to('manufacturer/units')->with('success', 'Unit updated.')
            : redirect()->back()->withInput()->with('error', 'Could not update the unit.');
    }

    public function toggle(int $mshopId): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.unit.update')) {
            return $denied;
        }
        if ($denied = $this->requireMshopAccess($mshopId)) {
            return $denied;
        }

        $repo = service('manufacturerUnitRepository');
        $unit = $repo->findById($mshopId, (int) $this->manufacturerId());
        if ($unit === null) {
            return redirect()->to('manufacturer/units')->with('error', 'Unit not found.');
        }

        $next = ($unit['status'] ?? '') === 'active' ? 'inactive' : 'active';
        $repo->setStatus($mshopId, (int) $this->manufacturerId(), $next, (int) session()->get('user_id'));

        return redirect()->to('manufacturer/units')->with('success', 'Unit is now ' . $next . '.');
    }
}
