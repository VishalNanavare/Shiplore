<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\ShopController — shop oversight across all vendors. Lists shops and
 * toggles active/inactive. Session-guarded (`webAuth`); RBAC-checked
 * (`shop.view` / `shop.update`); POSTs CSRF-protected at the route.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md §2
 */
final class ShopController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('shop.view')) {
            return $denied;
        }

        $req      = $this->request;
        $status   = trim((string) $req->getGet('status'));
        $q        = trim((string) $req->getGet('q'));
        $vendorId = (int) $req->getGet('vendor_id');
        $perRaw   = (string) $req->getGet('per_page');
        $perPage  = in_array((int) $perRaw, [25, 50, 100, 200], true) ? (int) $perRaw : 50;
        $page     = max(1, (int) $req->getGet('page'));

        $f     = ['status' => $status ?: null, 'q' => $q, 'vendor_id' => $vendorId ?: null];
        $repo  = service('shopRepository');
        $total = $repo->countList($f);
        $f['limit']  = $perPage;
        $f['offset'] = ($page - 1) * $perPage;

        // Reached from a vendor's detail page's "View all shops" link — name the
        // vendor in the banner rather than leaving the scoping silent.
        $filterVendor = $vendorId > 0 ? service('vendorRepository')->findById($vendorId) : null;

        return view('admin/shops/index', [
            'title'        => 'Shops · Admin',
            'pageTitle'    => 'Shops',
            'active'       => 'shops',
            'userName'     => session()->get('user_name') ?: 'User',
            'shops'        => $repo->list($f),
            'total'        => $total,
            'page'         => $page,
            'perPage'      => $perPage,
            'filters'      => ['status' => $status, 'q' => $q, 'vendor_id' => $vendorId ?: ''],
            'filterVendor' => $filterVendor,
        ]);
    }

    public function new()
    {
        if ($denied = $this->guard('shop.create')) {
            return $denied;
        }

        return $this->form(null);
    }

    public function edit(int $id)
    {
        if ($denied = $this->guard('shop.update')) {
            return $denied;
        }
        $shop = service('shopRepository')->findById($id);
        if ($shop === null) {
            return redirect()->to('admin/shops')->with('error', 'Shop not found.');
        }

        return $this->form($shop);
    }

    /** Read-only shop detail/info panel (with the "Go to Shop Portal" action). */
    public function show(int $id)
    {
        if ($denied = $this->guard('shop.view')) {
            return $denied;
        }
        $shop = service('shopRepository')->findById($id);
        if ($shop === null) {
            return redirect()->to('admin/shops')->with('error', 'Shop not found.');
        }

        $db     = \Config\Database::connect();
        $vendor = $db->table('vendors')->select('id, display_name, status')->where('id', (int) $shop['vendor_id'])->get()->getRowArray();

        $orders = $db->query(
            "SELECT COUNT(*) cnt,
                    COALESCE(SUM(CASE WHEN status IN ('delivered','completed') THEN grand_total ELSE 0 END),0) revenue,
                    COUNT(CASE WHEN status IN ('delivered','completed') THEN 1 END) completed,
                    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) cancelled,
                    COUNT(CASE WHEN status IN ('pending','confirmed','accepted','packed','ready','out_for_delivery') THEN 1 END) `open`
             FROM sub_orders WHERE shop_id = ? AND deleted_at IS NULL",
            [$id]
        )->getRowArray() ?? [];

        $deliveries = $db->query(
            "SELECT COUNT(*) cnt,
                    COUNT(CASE WHEN status = 'delivered' THEN 1 END) delivered,
                    COUNT(CASE WHEN status = 'failed' THEN 1 END) failed,
                    COUNT(CASE WHEN status = 'out_for_delivery' THEN 1 END) in_transit
             FROM deliveries WHERE shop_id = ? AND deleted_at IS NULL",
            [$id]
        )->getRowArray() ?? [];

        $products = (int) $db->table('product_shops')->where('shop_id', $id)->where('status', 'active')->countAllResults();
        try {
            $staffCount = (int) $db->table('staff_shop_assignments')->where('shop_id', $id)->countAllResults();
        } catch (\Throwable) {
            $staffCount = 0;
        }
        try {
            $mapsKey = trim((string) (service('integrationRepository')->config('google_maps')['browser_key'] ?? ''));
        } catch (\Throwable) {
            $mapsKey = '';
        }

        return view('admin/shops/show', [
            'title'      => 'Shop · ' . ($shop['name'] ?? ''),
            'pageTitle'  => $shop['name'] ?? 'Shop',
            'active'     => 'shops',
            'userName'   => session()->get('user_name') ?: 'User',
            'shop'       => $shop,
            'vendor'     => $vendor,
            'addr'       => json_decode((string) ($shop['address_json'] ?? '{}'), true) ?: [],
            'stateName'  => \App\Libraries\Geo\IndiaStates::list()[(string) ($shop['state_code'] ?? '')] ?? ($shop['state_code'] ?? '—'),
            'orders'     => $orders,
            'deliveries' => $deliveries,
            'products'   => $products,
            'staffCount' => $staffCount,
            'mapsKey'    => $mapsKey,
        ]);
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->guard('shop.create')) {
            return $denied;
        }
        $in = $this->shopInput(true);
        if (($err = $this->validateShop($in, true)) !== '') {
            return redirect()->back()->withInput()->with('error', $err);
        }
        $id = service('shopRepository')->create($in, (int) session()->get('user_id'));

        return redirect()->to('admin/shops')->with($id ? 'success' : 'error', $id ? 'Shop created.' : 'Could not create shop.');
    }

    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->guard('shop.update')) {
            return $denied;
        }
        if (service('shopRepository')->findById($id) === null) {
            return redirect()->to('admin/shops')->with('error', 'Shop not found.');
        }
        $in = $this->shopInput(false);
        if (($err = $this->validateShop($in, false)) !== '') {
            return redirect()->back()->withInput()->with('error', $err);
        }
        service('shopRepository')->update($id, $in, (int) session()->get('user_id'));

        return redirect()->to('admin/shops')->with('success', 'Shop updated.');
    }

    /** @param array<string,mixed>|null $shop */
    private function form(?array $shop): string
    {
        $addr = $shop !== null ? (json_decode((string) ($shop['address_json'] ?? '{}'), true) ?: []) : [];
        try {
            $mapsKey = trim((string) (service('integrationRepository')->config('google_maps')['browser_key'] ?? ''));
        } catch (\Throwable) {
            $mapsKey = '';
        }

        return view('admin/shops/form', [
            'title'           => ($shop ? 'Edit' : 'New') . ' Shop · Admin',
            'pageTitle'       => $shop ? 'Edit Shop' : 'New Shop',
            'active'          => 'shops',
            'userName'        => session()->get('user_name') ?: 'User',
            'shop'            => $shop,
            'addr'            => $addr,
            'vendors'         => service('shopRepository')->vendorsForSelect(),
            'maxRadius'       => service('settingsRepository')->deliveryMaxRadiusKm(),
            'mapsKey'         => $mapsKey,
            'states'          => \App\Libraries\Geo\IndiaStates::list(),
            'stateNameToCode' => \App\Libraries\Geo\IndiaStates::nameToCodeMap(),
        ]);
    }

    /** @return array<string,mixed> */
    private function shopInput(bool $withVendor): array
    {
        $p = static fn (string $k): string => trim((string) service('request')->getPost($k));
        $in = [
            'name' => $p('name'), 'code' => $p('code'), 'gstin' => strtoupper($p('gstin')),
            'address' => $p('address'), 'area' => $p('area'), 'city' => $p('city'),
            'state_code' => $p('state_code') ?: '27', 'pincode' => $p('pincode'),
            'latitude' => $p('latitude') ?: '0', 'longitude' => $p('longitude') ?: '0',
            'delivery_radius_km' => $p('delivery_radius_km'), 'prep_time_min' => $p('prep_time_min'),
            'pickup_enabled' => service('request')->getPost('pickup_enabled'),
        ];
        if ($withVendor) {
            $in['vendor_id'] = (int) service('request')->getPost('vendor_id');
        }

        return $in;
    }

    /** @param array<string,mixed> $in */
    private function validateShop(array $in, bool $isCreate): string
    {
        if ($isCreate && (int) ($in['vendor_id'] ?? 0) <= 0) { return 'Vendor is required.'; }
        if ($in['name'] === '') { return 'Shop name is required.'; }
        if ($isCreate && $in['code'] === '') { return 'Shop code is required.'; }
        if ($in['address'] === '' || $in['city'] === '') { return 'Address and city are required.'; }
        if (! preg_match('/^[0-9]{6}$/', $in['pincode'])) { return 'Pincode must be 6 digits.'; }
        if ($in['gstin'] !== '' && ! preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', $in['gstin'])) { return 'GSTIN must be 15 characters.'; }
        // Geo + delivery sanity (these feed serviceability — bad values break the storefront).
        $lat = (string) ($in['latitude'] ?? '');
        $lng = (string) ($in['longitude'] ?? '');
        if ($lat !== '' && (! is_numeric($lat) || (float) $lat < -90 || (float) $lat > 90)) { return 'Latitude must be between -90 and 90.'; }
        if ($lng !== '' && (! is_numeric($lng) || (float) $lng < -180 || (float) $lng > 180)) { return 'Longitude must be between -180 and 180.'; }
        $rad = (string) ($in['delivery_radius_km'] ?? '');
        if ($rad !== '' && (! is_numeric($rad) || (float) $rad < 0)) { return 'Delivery radius must be a non-negative number.'; }
        // A shop's radius can never exceed the admin "Max delivery radius (km)".
        // Empty = no own limit (capped at admin max); 0 = delivery disabled.
        if ($rad !== '' && (float) $rad > 0) {
            $max = service('settingsRepository')->deliveryMaxRadiusKm();
            if ((float) $rad > $max) {
                return 'Delivery radius cannot exceed the platform maximum of ' . rtrim(rtrim(number_format($max, 2), '0'), '.') . ' km.';
            }
        }

        return '';
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->transition($id, 'active', 'Shop activated.');
    }

    public function deactivate(int $id): RedirectResponse
    {
        return $this->transition($id, 'inactive', 'Shop deactivated.');
    }

    private function transition(int $id, string $status, string $okMessage): RedirectResponse
    {
        if ($denied = $this->guard('shop.update')) {
            return $denied;
        }

        $repo = service('shopRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/shops')->with('error', 'Shop not found.');
        }

        $repo->updateStatus($id, $status, session()->get('user_id'));

        return redirect()->to('admin/shops')->with('success', $okMessage);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
