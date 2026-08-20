<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\HTTP\RedirectResponse;

/** Vendor\ShopController — the vendor's own shops; open/close + timing/radius. */
final class ShopController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        // S0: a branch manager sees ONLY their assigned shop(s), not every shop
        // of the vendor. allowedShops() applies the shop-assignment filter.
        return $this->render('vendor/shops/index', 'shops', 'My Shops', [
            'shops'   => $this->allowedShops(),
            'isOwner' => $this->isOwner(),
        ]);
    }

    public function new()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->isOwner() && ! $this->can('shop.create')) {
            return redirect()->to('vendor/shops')->with('error', 'Only the owner can add a shop.');
        }
        try {
            $mapsKey = trim((string) (service('integrationRepository')->config('google_maps')['browser_key'] ?? ''));
        } catch (\Throwable) {
            $mapsKey = '';
        }

        return $this->render('vendor/shops/new', 'shops', 'Add Shop', [
            'mapsKey'         => $mapsKey,
            'states'          => \App\Libraries\Geo\IndiaStates::list(),
            'stateNameToCode' => \App\Libraries\Geo\IndiaStates::nameToCodeMap(),
            'maxRadius'       => service('settingsRepository')->deliveryMaxRadiusKm(),
        ]);
    }

    public function create(): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->isOwner() && ! $this->can('shop.create')) {
            return redirect()->to('vendor/shops')->with('error', 'Only the owner can add a shop.');
        }

        $name    = trim((string) $this->request->getPost('name'));
        $pincode = trim((string) $this->request->getPost('pincode'));
        if ($name === '' || trim((string) $this->request->getPost('address')) === '' || trim((string) $this->request->getPost('city')) === '' || ! preg_match('/^\d{6}$/', $pincode)) {
            return redirect()->back()->withInput()->with('error', 'Shop name, address, city and a 6-digit pincode are required.');
        }

        $radius = '';
        if ((string) $this->request->getPost('delivery_enabled') !== '') {
            $max    = service('settingsRepository')->deliveryMaxRadiusKm();
            $radius = (float) $this->request->getPost('delivery_radius');
            if ($radius <= 0 || $radius > $max) {
                return redirect()->back()->withInput()->with('error', 'Delivery radius must be between 0.5 and ' . rtrim(rtrim(number_format($max, 2), '0'), '.') . ' km.');
            }
        }

        $repo = service('vendorShopRepository');
        $id   = $repo->create($this->vendorId(), [
            'name'               => $name,
            'address'            => $this->request->getPost('address'),
            'area'               => $this->request->getPost('area'),
            'city'               => $this->request->getPost('city'),
            'state_code'         => $this->request->getPost('state_code'),
            'pincode'            => $pincode,
            'latitude'           => $this->request->getPost('latitude'),
            'longitude'          => $this->request->getPost('longitude'),
            'delivery_radius_km' => $radius,
        ], (int) session()->get('user_id'));

        if (! $id) {
            return redirect()->to('vendor/shops')->with('error', 'Could not add the shop.');
        }

        // Shop approval, phase 3 — the vendor needs to know their new shop isn't live
        // yet, or "Shop added." reads as done when it's actually the start of a review.
        $pending = ($repo->findById($id, $this->vendorId())['approval_status'] ?? '') === 'pending';

        return redirect()->to('vendor/shops')->with('success', $pending
            ? 'Shop submitted for admin approval. It will go live once approved.'
            : 'Shop added.');
    }

    public function show(int $id)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if ($denied = $this->requireShopAccess($id)) {
            return $denied;
        }

        $repo = service('vendorShopRepository');
        $shop = $repo->findById($id, $this->vendorId());
        if ($shop === null) {
            return redirect()->to('vendor/shops')->with('error', 'Shop not found.');
        }

        return $this->render('vendor/shops/show', 'shops', $shop['name'], [
            'shop'  => $shop,
            'hours' => $repo->hours($id),
            'staff' => service('vendorStaffRepository')->staffForShop($id, (int) $this->vendorId()),
            // Owners/managers may manage directly; branch managers may initiate
            // (staff.request) — both see the staff actions on the shop console.
            'canManageStaff' => $this->isOwner() || $this->can('vendor_staff.manage') || $this->can('staff.request'),
            'canEditShop'    => $this->isOwner() || $this->can('shop.update'),
        ]);
    }

    public function edit(int $id)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if ($denied = $this->requireShopAccess($id)) {
            return $denied;
        }
        $repo = service('vendorShopRepository');
        $shop = $repo->findById($id, $this->vendorId());
        if ($shop === null) {
            return redirect()->to('vendor/shops')->with('error', 'Shop not found.');
        }
        // hours keyed by day (0-6) for the weekly grid
        $hours = [];
        foreach ($repo->hours($id) as $h) {
            $hours[(int) $h['day_of_week']] = $h;
        }

        return $this->render('vendor/shops/form', 'shops', 'Edit ' . $shop['name'], [
            'shop'     => $shop,
            'address'  => json_decode((string) ($shop['address_json'] ?? ''), true) ?: [],
            'hours'    => $hours,
            'holidays' => $repo->holidays($id),
            'canHours' => $this->canManageHours(),
        ]);
    }

    public function save(int $id): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if ($denied = $this->requireShopAccess($id)) {
            return $denied;
        }
        $repo = service('vendorShopRepository');
        if ($repo->findById($id, $this->vendorId()) === null) {
            return redirect()->to('vendor/shops')->with('error', 'Shop not found.');
        }
        $name = trim((string) $this->request->getPost('name'));
        if ($name === '') {
            return redirect()->back()->with('error', 'Shop name is required.');
        }
        // A shop's radius can never exceed the admin \"Max delivery radius (km)\".
        $radius = trim((string) $this->request->getPost('delivery_radius_km'));
        if ($radius !== '' && (float) $radius > 0) {
            $max = service('settingsRepository')->deliveryMaxRadiusKm();
            if ((float) $radius > $max) {
                return redirect()->back()->withInput()->with('error', 'Delivery radius cannot exceed the platform maximum of ' . rtrim(rtrim(number_format($max, 2), '0'), '.') . ' km.');
            }
        }
        $fields = [
            'name' => $name,
            'delivery_radius_km' => $radius,
            'prep_time_min' => trim((string) $this->request->getPost('prep_time_min')),
            'pickup_enabled' => $this->request->getPost('pickup_enabled'),
            'latitude' => trim((string) $this->request->getPost('latitude')),
            'longitude' => trim((string) $this->request->getPost('longitude')),
            'address_line' => $this->request->getPost('address_line'),
            'city' => $this->request->getPost('city'),
            'pincode' => $this->request->getPost('pincode'),
            'state_code' => $this->request->getPost('state_code'),
            'phone' => $this->request->getPost('phone'),
        ];

        // X3 (AR-08): staff/managers without shop.update REQUEST the change;
        // the owner (or permitted staff) writes directly.
        if (! $this->isOwner() && ! $this->can('shop.update')) {
            [$type, $msg] = $this->requestFlash($this->submitChangeRequest('shop', 'settings', $id, null, ['fields' => $fields], 'settings'));

            return redirect()->to('vendor/shops/' . $id . '/edit')->with($type, $msg);
        }

        $repo->update($id, $this->vendorId(), $fields, (int) session()->get('user_id'));

        return redirect()->to('vendor/shops/' . $id . '/edit')->with('success', 'Shop updated.');
    }

    /** Save the weekly opening hours (7-day grid). */
    public function hours(int $id): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if ($denied = $this->requireShopAccess($id)) {
            return $denied;
        }
        if (service('vendorShopRepository')->findById($id, $this->vendorId()) === null) {
            return redirect()->to('vendor/shops')->with('error', 'Shop not found.');
        }
        $rows = [];
        for ($day = 0; $day <= 6; $day++) {
            $rows[$day] = [
                'open' => (string) $this->request->getPost("open_$day"),
                'close' => (string) $this->request->getPost("close_$day"),
                'is_closed' => (bool) $this->request->getPost("closed_$day"),
            ];
        }

        // X3 (AR-08): timing changes by non-permitted staff become requests.
        if (! $this->canManageHours()) {
            [$type, $msg] = $this->requestFlash($this->submitChangeRequest('shop', 'hours', $id, null, ['rows' => $rows], 'hours'));

            return redirect()->to('vendor/shops/' . $id . '/edit')->with($type, $msg);
        }

        $ok = service('vendorShopRepository')->saveHours($id, $this->vendorId(), $rows, (int) session()->get('user_id'));

        return redirect()->to('vendor/shops/' . $id . '/edit')->with($ok ? 'success' : 'error', $ok ? 'Opening hours saved.' : 'Could not save hours.');
    }

    public function holidayAdd(int $id): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if ($denied = $this->requireShopAccess($id)) {
            return $denied;
        }
        if (service('vendorShopRepository')->findById($id, $this->vendorId()) === null) {
            return redirect()->to('vendor/shops')->with('error', 'Shop not found.');
        }

        // X3 (AR-08): holiday changes by non-permitted staff become requests.
        if (! $this->canManageHours()) {
            [$type, $msg] = $this->requestFlash($this->submitChangeRequest('shop', 'holiday', $id, null, [
                'holiday_date' => (string) $this->request->getPost('holiday_date'),
                'reason'       => (string) $this->request->getPost('reason'),
            ], 'holiday'));

            return redirect()->to('vendor/shops/' . $id . '/edit')->with($type, $msg);
        }

        $ok = service('vendorShopRepository')->addHoliday($id, $this->vendorId(), (string) $this->request->getPost('holiday_date'), (string) $this->request->getPost('reason'), (int) session()->get('user_id'));

        return redirect()->to('vendor/shops/' . $id . '/edit')->with($ok ? 'success' : 'error', $ok ? 'Holiday added.' : 'Pick a valid date.');
    }

    public function holidayDelete(int $id, int $hid): RedirectResponse
    {
        if ($denied = $this->guardHours($id)) {
            return $denied;
        }
        service('vendorShopRepository')->deleteHoliday($hid, $id, $this->vendorId());

        return redirect()->to('vendor/shops/' . $id . '/edit')->with('success', 'Holiday removed.');
    }

    public function open(int $id): RedirectResponse
    {
        return $this->toggle($id, 'active', 'Shop opened.');
    }

    public function close(int $id): RedirectResponse
    {
        return $this->toggle($id, 'closed_temp', 'Shop temporarily closed.');
    }

    private function toggle(int $id, string $status, string $msg): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if ($denied = $this->requireShopAccess($id)) {
            return $denied;
        }

        $repo = service('vendorShopRepository');
        $shop = $repo->findById($id, $this->vendorId());
        if ($shop === null) {
            return redirect()->to('vendor/shops')->with('error', 'Shop not found.');
        }

        // Shop approval, phase 3. A 2nd+ shop awaiting (or refused) admin approval
        // cannot be opened OR closed by the vendor — neither action makes sense before
        // the gate resolves: opening would bypass the approval this whole feature
        // exists to enforce, and closing a shop that was never live is meaningless.
        // Once approved (or for a 'not_required' first shop) open/close work exactly as
        // before. Checked before the emergency-close exception below on purpose — a
        // pending shop has no "emergency" to ratify, only an approval to wait on.
        if (in_array($shop['approval_status'] ?? 'not_required', ['pending', 'rejected'], true)) {
            return redirect()->to('vendor/shops')->with('error', 'This shop is awaiting admin approval and cannot be opened or closed yet.');
        }

        // X3 (AR-08): online/offline status by non-permitted staff is a request.
        // EXCEPTION (doc 44 §4 S2): emergency TEMPORARY CLOSURE applies for
        // everyone immediately — safety first — and is ratified via the audit trail.
        $emergencyClose = $status === 'closed_temp';
        if (! $emergencyClose && ! $this->isOwner() && ! $this->can('shop.update')) {
            [$type, $m] = $this->requestFlash($this->submitChangeRequest('shop', 'online_status', $id, null, ['status' => $status], 'online_status'));

            return redirect()->to('vendor/shops')->with($type, $m);
        }

        $repo->updateStatus($id, $this->vendorId(), $status, (int) session()->get('user_id'));
        if ($emergencyClose && ! $this->isOwner()) {
            try {
                service('auditWriter')->log([
                    'action' => 'shop.emergency_closed', 'entity_type' => 'shop', 'entity_id' => $id,
                    'actor_user_id' => (int) session()->get('user_id'), 'actor_role' => $this->actorRole(),
                    'principal_type' => 'vendor', 'scope_type' => 'vendor', 'scope_id' => $this->vendorId(),
                    'reason' => 'staff emergency closure — owner ratification expected',
                ]);
            } catch (\Throwable) {
            }
        }

        return redirect()->to('vendor/shops')->with('success', $msg);
    }

    private function canManageHours(): bool
    {
        return $this->isOwner() || $this->can('shop.hours.manage');
    }

    /** Guard the hours/holiday actions: own the shop + may manage hours. */
    private function guardHours(int $id): ?RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if ($denied = $this->requireShopAccess($id)) {
            return $denied;
        }
        if (service('vendorShopRepository')->findById($id, $this->vendorId()) === null) {
            return redirect()->to('vendor/shops')->with('error', 'Shop not found.');
        }
        if (! $this->canManageHours()) {
            return redirect()->to('vendor/shops/' . $id . '/edit')->with('error', "You don't have permission to manage shop hours.");
        }

        return null;
    }
}
