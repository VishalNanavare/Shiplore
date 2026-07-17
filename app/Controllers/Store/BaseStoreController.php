<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/** Shared base for the customer storefront. */
abstract class BaseStoreController extends BaseController
{
    protected function customerId(): ?int
    {
        $id = session()->get('customer_id');

        return $id ? (int) $id : null;
    }

    protected function requireCustomer(): ?RedirectResponse
    {
        if ($this->customerId() === null) {
            return redirect()->to('store/login')->with('error', 'Please sign in to continue.');
        }

        return null;
    }

    /**
     * Login gate that returns the visitor to a specific store path after sign-in
     * (used for checkout). Stores the return path in the session; AccountController
     * consumes it on a successful login.
     */
    protected function requireCustomerFor(string $returnTo, string $msg = 'Please sign in to continue.'): ?RedirectResponse
    {
        if ($this->customerId() === null) {
            session()->set('login_return', $returnTo);

            return redirect()->to('store/login')->with('error', $msg);
        }

        return null;
    }

    /**
     * Guest-safe order ownership check. True when the order was placed in THIS
     * session (covers guest checkout) OR belongs to the logged-in customer.
     * Prevents enumerating other people's orders on the pay/confirmation/track pages.
     */
    protected function ownsOrder(string $orderNo): bool
    {
        if ($orderNo === '') {
            return false;
        }
        $own = (array) (session()->get('my_orders') ?? []);
        if (in_array($orderNo, $own, true)) {
            return true;
        }
        $cid = $this->customerId();

        return $cid !== null && service('storeOrderRepository')->customerOwns($orderNo, $cid);
    }

    /** Remember an order placed in this session so the buyer (guest or logged-in) can view it. */
    protected function rememberOrder(string $orderNo): void
    {
        $own   = (array) (session()->get('my_orders') ?? []);
        $own[] = $orderNo;
        session()->set('my_orders', array_slice(array_values(array_unique($own)), -50));
    }
}
