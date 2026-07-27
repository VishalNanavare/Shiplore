<?php

declare(strict_types=1);

namespace App\Controllers\Msonline;

use App\Controllers\BaseController;

/**
 * BaseMsonlineController — shared base for msonline.shiplore.in, the B2B marketplace
 * where vendors and shops buy from manufacturers.
 *
 * Buyers are EXISTING vendor-panel users (principal_type='vendor') with a resolvable
 * vendor — there is no separate msonline account. Identity is resolved from the session
 * user via VendorAccountRepository (read-only use; that class is not modified).
 *
 * Two rules this class exists to make hard to break:
 *
 *   1. NO PRICING TO LOGGED-OUT VISITORS. Browsing may be public, but every price is
 *      omitted SERVER-SIDE unless isBuyer() is true. Never hidden with CSS — a price in
 *      the HTML is a price that has been disclosed.
 *   2. MAKING PRICE NEVER LEAVES THE MANUFACTURER. It is their internal production cost
 *      and must not appear in any msonline response, for any viewer.
 *
 * Follows the rider-panel precedent: a standalone surface with its own session handling
 * rather than reusing the vendor route group's webAuth pin.
 */
abstract class BaseMsonlineController extends BaseController
{
    /** @var array<string,mixed>|null */
    private ?array $buyerRow = null;
    private bool $resolved = false;

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
     * The single gate for showing any price on msonline.
     *
     * Manufacturers are explicitly NOT buyers: they sell here. A manufacturer session
     * has principal_type='manufacturer', so buyer() returns null for it already, but
     * that is worth stating rather than leaving to be inferred.
     */
    protected function isBuyer(): bool
    {
        return $this->buyerVendorId() !== null;
    }

    /** Common chrome for every msonline view. */
    protected function render(string $view, string $pageTitle, array $data = []): string
    {
        $b = $this->buyer();

        return view($view, array_merge([
            'title'      => $pageTitle . ' · msonline',
            'pageTitle'  => $pageTitle,
            'isBuyer'    => $this->isBuyer(),
            'buyerName'  => $b['display_name'] ?? null,
        ], $data));
    }
}
