<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Manufacturer\UnitController — the manufacturer's units (factories), stored in `mshops`.
 *
 * Units DO now carry delivery settings. They originally did not: `mshops` shipped with
 * no delivery columns at all, so "a manufacturer cannot set a delivery range" was
 * enforced by the schema rather than by a check someone could later bypass. The
 * operator asked for full parity with the vendor panel including delivery, so
 * 75_manufacturer_delivery.sql adds those columns and this screen exposes them.
 *
 * Serviceability is gated on its own permission (mfg.unit.serviceability), separate
 * from mfg.unit.update: correcting a factory's address and changing what that factory
 * promises buyers are different-sized decisions. The repository only writes those
 * columns when the controller says so, so a user without the permission cannot blank
 * them by omission or set them by hand-crafting a post.
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
            // Serviceability is its own permission: a unit manager may correct an
            // address without being able to change what the unit promises buyers.
            'canServiceability' => $this->can('mfg.unit.serviceability'),
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

        $data = (array) $this->request->getPost();

        // Serviceability is a separate, separately-permissioned section of the same
        // form. The repository only writes those columns when this flag is set, so a
        // user without mfg.unit.serviceability editing the unit's address cannot blank
        // its delivery settings by omission — and cannot set them by hand-crafting a
        // post either, since the flag is decided here rather than taken from input.
        unset($data['serviceability']);
        if ($this->can('mfg.unit.serviceability')) {
            $data['serviceability'] = true;
        }

        $ok = service('manufacturerUnitRepository')->update(
            $mshopId,
            (int) $this->manufacturerId(),
            $data,
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
