<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Vendor\TransferController — inter-store stock transfers between the vendor's
 * own shops. Drives the full lifecycle (request -> approve -> pack -> dispatch
 * -> receive -> close, + reject/cancel) via TransferService, which moves stock
 * through InventoryService. Every action is vendor-scoped.
 */
final class TransferController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $repo = service('vendorTransferRepository');
        $vid  = $this->vendorId();

        // S0: staff see only transfers that touch one of their assigned shops,
        // and may pick only their shops in the request form.
        $allowed   = $this->allowedShopIds();
        $transfers = $repo->list($vid);
        if (! $this->isOwner()) {
            $transfers = array_values(array_filter($transfers, static fn (array $t): bool => in_array((int) $t['from_shop_id'], $allowed, true) || in_array((int) $t['to_shop_id'], $allowed, true)));
        }

        return $this->render('vendor/transfers/index', 'transfers', 'Stock Transfers', [
            'transfers' => $transfers,
            'shops'     => $this->allowedShops(),
            'variants'  => $repo->variants($vid),
        ]);
    }

    public function find()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $repo = service('vendorTransferRepository');
        $vid  = $this->vendorId();
        $q    = trim((string) $this->request->getGet('q'));

        return $this->render('vendor/transfers/find', 'transfers', 'Find Stock', [
            'q'       => $q,
            'results' => $q !== '' ? $repo->searchStock($vid, $q) : [],
            'shops'   => $this->allowedShops(),
        ]);
    }

    public function create(): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $fromShop = (int) $this->request->getPost('from_shop_id');
        $toShop   = (int) $this->request->getPost('to_shop_id');
        $variant  = (int) $this->request->getPost('variant_id');
        $qty      = (float) $this->request->getPost('qty');
        $reason   = (string) $this->request->getPost('reason');

        // S4: the DESTINATION must be one of the user's own stores. The SOURCE may
        // be any sibling store of the vendor (an inter-shop "trade" pulling stock
        // in) — but a staff member's request is held for the vendor's approval;
        // the owner moves stock directly.
        if (! in_array($toShop, $this->allowedShopIds(), true)) {
            return redirect()->to('vendor/transfers')->with('error', 'The destination must be one of your own stores.');
        }

        if (! $this->isOwner()) {
            $payload = ['from_shop_id' => $fromShop, 'to_shop_id' => $toShop, 'variant_id' => $variant, 'qty' => $qty, 'reason' => $reason, 'shop_id' => $toShop];
            [$type, $msg] = $this->requestFlash($this->submitChangeRequest('transfer', 'request', null, null, $payload, 'transfer-' . $fromShop . '-' . $toShop . '-' . $variant));

            return redirect()->to('vendor/transfers')->with($type, $msg);
        }

        $res = service('transferService')->request($this->vendorId(), $fromShop, $toShop, $variant, $qty, $reason, (int) session()->get('user_id'));

        return redirect()->to('vendor/transfers')->with($res['ok'] ? 'success' : 'error', $res['message']);
    }

    public function approve(int $id): RedirectResponse
    {
        return $this->act($id, fn () => service('transferService')->approve($id, $this->vendorId(), (int) session()->get('user_id')));
    }

    public function reject(int $id): RedirectResponse
    {
        return $this->act($id, fn () => service('transferService')->reject($id, $this->vendorId(), (int) session()->get('user_id'), (string) $this->request->getPost('reason')));
    }

    public function pack(int $id): RedirectResponse
    {
        return $this->act($id, fn () => service('transferService')->pack($id, $this->vendorId(), (int) session()->get('user_id')));
    }

    public function dispatch(int $id): RedirectResponse
    {
        return $this->act($id, fn () => service('transferService')->dispatch($id, $this->vendorId(), (int) session()->get('user_id')));
    }

    public function receive(int $id): RedirectResponse
    {
        return $this->act($id, fn () => service('transferService')->receive($id, $this->vendorId(), (float) $this->request->getPost('qty_received'), (int) session()->get('user_id')));
    }

    public function close(int $id): RedirectResponse
    {
        return $this->act($id, fn () => service('transferService')->close($id, $this->vendorId(), (int) session()->get('user_id')));
    }

    public function cancel(int $id): RedirectResponse
    {
        return $this->act($id, fn () => service('transferService')->cancel($id, $this->vendorId(), (int) session()->get('user_id')));
    }

    /** @param callable():array $fn */
    private function act(int $id, callable $fn): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if ($denied = $this->requireTransferAccess($id)) {
            return $denied;
        }
        $res = $fn();

        return redirect()->to('vendor/transfers')->with($res['ok'] ? 'success' : 'error', $res['message']);
    }

    /**
     * index() already filters the list so non-owner staff see only transfers
     * touching one of their assigned shops; approve/reject/pack/dispatch/receive/
     * close/cancel funnelled through act() with no equivalent check — any active
     * staff row could act on a transfer between two shops they are not assigned to
     * by POSTing a small sequential id, defeating the same-controller governance on
     * create() (a staffer cannot REQUEST a transfer but could execute one already
     * sitting in the queue).
     */
    private function requireTransferAccess(int $id): ?RedirectResponse
    {
        if ($this->isOwner()) {
            return null;
        }
        $t       = service('vendorTransferRepository')->findOne($id, (int) $this->vendorId());
        $allowed = $this->allowedShopIds();
        if ($t === null
            || (! in_array((int) $t['from_shop_id'], $allowed, true) && ! in_array((int) $t['to_shop_id'], $allowed, true))
        ) {
            return redirect()->to('vendor/transfers')->with('error', 'Transfer not found.');
        }

        return null;
    }
}
