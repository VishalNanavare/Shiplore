<?php

declare(strict_types=1);

namespace App\Libraries\Msonline;

/**
 * MsonlineCart — the B2B basket for msonline.shiplore.in.
 *
 * Separate from App\Libraries\Store\CartService for one concrete reason: the session
 * cookie is domain-wide (`.shiplore.in`, app/Config/Cookie.php), so shiplore.in and
 * msonline.shiplore.in share ONE session in a given browser. CartService hardcodes
 * `private const KEY = 'store_cart'`; reusing it would silently merge a shopper's
 * consumer basket with a vendor's wholesale order — same browser, same key, two very
 * different carts.
 *
 * Storage is a plain `variantId => qty` map under its own key, mirroring the consumer
 * cart's shape so the two stay easy to reason about side by side.
 *
 * Quantities are floats: wholesale is often sold by weight or in packs, and
 * products.qty_step already permits non-integer multiples.
 */
final class MsonlineCart
{
    private const KEY = 'msonline_cart';

    /** @return array<int,float> variantId => qty */
    public function raw(): array
    {
        $cart = session()->get(self::KEY);

        return is_array($cart) ? $cart : [];
    }

    public function add(int $variantId, float $qty): void
    {
        if ($variantId <= 0 || $qty <= 0) {
            return;
        }
        $cart = $this->raw();
        $cart[$variantId] = round(($cart[$variantId] ?? 0) + $qty, 3);
        session()->set(self::KEY, $cart);
    }

    /** Absolute set; a non-positive qty removes the line. */
    public function setQty(int $variantId, float $qty): void
    {
        $cart = $this->raw();
        if ($qty <= 0) {
            unset($cart[$variantId]);
        } else {
            $cart[$variantId] = round($qty, 3);
        }
        session()->set(self::KEY, $cart);
    }

    public function remove(int $variantId): void
    {
        $cart = $this->raw();
        unset($cart[$variantId]);
        session()->set(self::KEY, $cart);
    }

    public function clear(): void
    {
        session()->remove(self::KEY);
    }

    public function isEmpty(): bool
    {
        return $this->raw() === [];
    }

    /** Distinct line count (not total units). */
    public function count(): int
    {
        return count($this->raw());
    }
}
