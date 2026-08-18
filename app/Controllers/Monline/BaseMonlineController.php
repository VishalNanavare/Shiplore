<?php

declare(strict_types=1);

namespace App\Controllers\Monline;

use App\Controllers\BaseController;

/**
 * BaseMonlineController — shared base for monline.<domain>, the B2B marketplace
 * where vendors and shops buy from manufacturers.
 *
 * Buyers are EXISTING vendor-panel users (principal_type='vendor') with a resolvable
 * vendor — there is no separate monline account. Identity is resolved from the session
 * user via VendorAccountRepository (read-only use; that class is not modified).
 *
 * Two rules this class exists to make hard to break:
 *
 *   1. NO PRICING TO LOGGED-OUT VISITORS. Browsing may be public, but every price is
 *      omitted SERVER-SIDE unless isBuyer() is true. Never hidden with CSS — a price in
 *      the HTML is a price that has been disclosed.
 *   2. MAKING PRICE NEVER LEAVES THE MANUFACTURER. It is their internal production cost
 *      and must not appear in any monline response, for any viewer.
 *
 * Follows the rider-panel precedent: a standalone surface with its own session handling
 * rather than reusing the vendor route group's webAuth pin.
 */
abstract class BaseMonlineController extends BaseController
{
    /** @var array<string,mixed>|null */
    private ?array $buyerRow = null;
    private bool $resolved = false;
    /** @var list<int>|null */
    private ?array $shopIds = null;

    /**
     * The vendor this visitor buys on behalf of, or null when not signed in as one.
     *
     * @return array<string,mixed>|null
     */
    protected function buyer(): ?array
    {
        if (! $this->resolved) {
            $this->resolved = true;

            if (session()->get('isLoggedIn') && (string) session()->get('principal_type') === 'vendor') {
                $uid  = (int) session()->get('user_id');
                $repo = service('vendorAccountRepository');
                $this->buyerRow = $repo->findByOwnerUserId($uid) ?? $repo->findStaffVendor($uid);
            }
        }

        return $this->buyerRow;
    }

    protected function buyerVendorId(): ?int
    {
        $b = $this->buyer();

        return $b !== null ? (int) $b['id'] : null;
    }

    /**
     * The single gate for showing any price on monline.
     *
     * Manufacturers are explicitly NOT buyers: they sell here. A manufacturer session
     * has principal_type='manufacturer', so buyer() returns null for it already, but
     * that is worth stating rather than leaving to be inferred.
     */
    protected function isBuyer(): bool
    {
        return $this->buyerVendorId() !== null;
    }

    /**
     * Shops this buyer may route stock into.
     *
     * Mirrors BaseVendorController's rule: an owner gets every shop of their vendor,
     * assigned staff get only theirs. A branch manager must not be able to order goods
     * into a sibling branch, so this is the allow-list `place()` checks against.
     *
     * @return list<int>
     */
    protected function buyerShopIds(): array
    {
        if ($this->shopIds === null) {
            $vid = $this->buyerVendorId();
            if ($vid === null) {
                return $this->shopIds = [];
            }

            $repo  = service('vendorAccountRepository');
            $staff = $this->buyer()['vendor_staff_id'] ?? null;

            $this->shopIds = $staff === null
                ? $repo->shopIdsForVendor($vid)
                : $repo->shopIdsForStaff((int) $staff);
        }

        return $this->shopIds;
    }

    /** id => name for the destination picker. @return array<int,string> */
    protected function buyerShops(): array
    {
        $allowed = $this->buyerShopIds();
        if ($allowed === []) {
            return [];
        }

        $rows = \Config\Database::connect()->table('shops')
            ->select('id, name')
            ->whereIn('id', $allowed)
            ->where('deleted_at', null)
            ->orderBy('name')
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }

        return $out;
    }

    /** @var array{lat:float,lng:float,label:string}|null */
    private ?array $buyerPointCache = null;
    private bool $buyerPointResolved = false;

    /**
     * The point to sort the wholesale catalogue by: an explicit monline location
     * override if the buyer set one, else their own (first) shop's address, else null
     * — anonymous visitor, a buyer with no shops, or (defensive; shops.latitude is
     * NOT NULL so should not happen) a shop somehow missing coordinates.
     *
     * @return array{lat:float,lng:float,label:string}|null
     */
    protected function buyerPoint(): ?array
    {
        if (! $this->buyerPointResolved) {
            $this->buyerPointResolved = true;
            $this->buyerPointCache    = $this->resolveBuyerPoint();
        }

        return $this->buyerPointCache;
    }

    /** @return array{lat:float,lng:float,label:string}|null */
    private function resolveBuyerPoint(): ?array
    {
        if (! $this->isBuyer()) {
            return null;
        }

        $override = service('monlineLocationService')->get();
        if ($override !== null) {
            return $override;
        }

        $shopIds = $this->buyerShopIds();
        if ($shopIds === []) {
            return null;
        }

        $row = \Config\Database::connect()->table('shops')
            ->select('name, latitude, longitude')
            ->whereIn('id', $shopIds)
            ->where('deleted_at', null)
            ->orderBy('name')
            ->get()->getRowArray();

        if ($row === null || $row['latitude'] === null || $row['longitude'] === null) {
            return null;
        }

        return ['lat' => (float) $row['latitude'], 'lng' => (float) $row['longitude'], 'label' => (string) $row['name']];
    }

    /** Common chrome for every monline view. */
    protected function render(string $view, string $pageTitle, array $data = []): string
    {
        $b     = $this->buyer();
        $point = $this->buyerPoint();

        return view($view, array_merge([
            'title'               => $pageTitle . ' · monline',
            'pageTitle'           => $pageTitle,
            'isBuyer'             => $this->isBuyer(),
            'buyerName'           => $b['display_name'] ?? null,
            'nearLabel'           => $point['label'] ?? null,
            'hasLocationOverride' => service('monlineLocationService')->has(),
            'navCategories'       => service('monlineCatalogRepository')->categories(),
        ], $data));
    }
}
