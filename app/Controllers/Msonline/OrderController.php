<?php

declare(strict_types=1);

namespace App\Controllers\Msonline;

use App\Libraries\Catalog\PurchaseRules;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Msonline\OrderController — the B2B cart and checkout.
 *
 * Every method here requires a resolved buyer. Browsing may be anonymous; ordering
 * never is, and neither is seeing a price.
 *
 * The cart holds variant ids and quantities only. Prices are read from the database at
 * placement by PurchaseOrderRepository — a client-supplied price is never accepted
 * anywhere in this flow, so there is nothing to tamper with.
 */
final class OrderController extends BaseMsonlineController
{
    /** Guard: must be a signed-in vendor/shop buyer. */
    private function requireBuyer(): ?RedirectResponse
    {
        if (! $this->isBuyer()) {
            return redirect()->to('msonline')->with('error', 'Sign in with your vendor account to order.');
        }

        return null;
    }

    public function cart()
    {
        if ($denied = $this->requireBuyer()) {
            return $denied;
        }

        $lines = service('msonlineCatalogRepository')->cartLines(service('msonlineCart')->raw());

        return $this->render('msonline/cart', 'Your order', [
            'lines'     => $lines,
            'subtotal'  => array_sum(array_column($lines, 'line_total')),
            'shops'     => $this->buyerShops(),
            'cartCount' => count($lines),
        ]);
    }

    public function add(): RedirectResponse
    {
        if ($denied = $this->requireBuyer()) {
            return $denied;
        }

        $variantId = (int) $this->request->getPost('variant_id');
        $qty       = (float) $this->request->getPost('qty');

        // MOQ is checked here for an immediate message, and AGAIN at placement — the
        // cart sits in a session that can go stale while the product's rules change.
        $rules = service('msonlineCatalogRepository')->findBySlug((string) $this->request->getPost('slug'), false);
        if ($rules !== null) {
            $check = PurchaseRules::validate($qty, [
                'min_purchase_qty' => $rules['min_purchase_qty'] ?? null,
                'max_purchase_qty' => $rules['max_purchase_qty'] ?? null,
                'qty_step'         => $rules['qty_step'] ?? null,
            ]);
            if (! $check['ok']) {
                return redirect()->back()->with('error', $check['message']);
            }
        }

        service('msonlineCart')->add($variantId, $qty);

        return redirect()->to('msonline/cart')->with('success', 'Added to your order.');
    }

    public function update(): RedirectResponse
    {
        if ($denied = $this->requireBuyer()) {
            return $denied;
        }

        $cart = service('msonlineCart');
        foreach ((array) $this->request->getPost('qty') as $variantId => $qty) {
            $cart->setQty((int) $variantId, (float) $qty);
        }

        return redirect()->to('msonline/cart')->with('success', 'Order updated.');
    }

    public function remove(int $variantId): RedirectResponse
    {
        if ($denied = $this->requireBuyer()) {
            return $denied;
        }

        service('msonlineCart')->remove($variantId);

        return redirect()->to('msonline/cart');
    }

    public function place(): RedirectResponse
    {
        if ($denied = $this->requireBuyer()) {
            return $denied;
        }

        $shopId = (int) $this->request->getPost('buyer_shop_id');

        // The destination must be a shop this user may act on — an owner's whole vendor,
        // or a branch manager's assigned shops only. Without this a manager could route
        // stock into a sibling branch.
        if (! in_array($shopId, $this->buyerShopIds(), true)) {
            return redirect()->to('msonline/cart')->with('error', 'Choose a delivery destination from your own shops.');
        }

        $cart = service('msonlineCart');
        $res  = service('purchaseOrderRepository')->placeFromCart(
            (int) $this->buyerVendorId(),
            $shopId,
            $cart->raw(),
            (string) $this->request->getPost('notes'),
            (int) session()->get('user_id'),
        );

        if (! $res['ok']) {
            return redirect()->to('msonline/cart')->with('error', $res['error']);
        }

        $cart->clear();
        $n = count($res['po_ids']);

        return redirect()->to('msonline/orders')->with(
            'success',
            $n === 1
                ? 'Purchase order placed.'
                : $n . ' purchase orders placed — one per manufacturer.',
        );
    }

    public function orders()
    {
        if ($denied = $this->requireBuyer()) {
            return $denied;
        }

        return $this->render('msonline/orders', 'Purchase orders', [
            'orders' => service('purchaseOrderRepository')->listForBuyer(
                (int) $this->buyerVendorId(),
                $this->buyerShopIds(),
                trim((string) $this->request->getGet('status')) ?: null,
            ),
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->requireBuyer()) {
            return $denied;
        }

        $found = service('purchaseOrderRepository')->findFor($id, (int) $this->buyerVendorId(), 'buyer');
        if ($found === null) {
            return redirect()->to('msonline/orders')->with('error', 'Purchase order not found.');
        }

        return $this->render('msonline/order_detail', $found['po']['po_no'], [
            'po'    => $found['po'],
            'items' => $found['items'],
        ]);
    }

    /** Buyer marks the goods received — raises stock at the destination shop. */
    public function receive(int $id): RedirectResponse
    {
        if ($denied = $this->requireBuyer()) {
            return $denied;
        }

        $res = service('purchaseOrderRepository')->receive($id, (int) $this->buyerVendorId(), (int) session()->get('user_id'));

        return $res['ok']
            ? redirect()->to('msonline/orders/' . $id)->with('success', 'Received — stock has been added to the destination shop.')
            : redirect()->to('msonline/orders/' . $id)->with('error', $res['error']);
    }

    public function cancel(int $id): RedirectResponse
    {
        if ($denied = $this->requireBuyer()) {
            return $denied;
        }

        $res = service('purchaseOrderRepository')->transition(
            $id,
            (int) $this->buyerVendorId(),
            'buyer',
            'cancelled',
            (int) session()->get('user_id'),
        );

        return $res['ok']
            ? redirect()->to('msonline/orders/' . $id)->with('success', 'Purchase order cancelled.')
            : redirect()->to('msonline/orders/' . $id)->with('error', $res['error']);
    }
}
