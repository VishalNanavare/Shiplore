<?php

declare(strict_types=1);

namespace App\Libraries\Monline;

/**
 * BuyerLocationService — an optional location override for monline's proximity sort.
 *
 * Separate from App\Libraries\Store\LocationService for the same reason MonlineCart is
 * separate from CartService: the session cookie is domain-wide (`.shiplore.in`), so
 * shiplore.in and monline.shiplore.in share ONE session in a given browser. Reusing the
 * storefront's key would let a "deliver my groceries here" pick silently change what a
 * B2B buyer sees sorted by, and vice versa.
 *
 * Deliberately smaller than LocationService — lat/lng/label only. Nothing here needs to
 * reverse-geocode into a shippable address; a purchase order ships to an existing
 * buyer_shop_id already on file, never to a freeform address captured by this picker.
 */
final class BuyerLocationService
{
    private const KEY = 'monline_loc';

    public function set(float $lat, float $lng, string $label): void
    {
        session()->set(self::KEY, [
            'lat'   => $lat,
            'lng'   => $lng,
            'label' => $label !== '' ? $label : 'Selected location',
        ]);
    }

    /** @return array{lat:float,lng:float,label:string}|null */
    public function get(): ?array
    {
        $loc = session()->get(self::KEY);

        return is_array($loc) ? $loc : null;
    }

    public function has(): bool
    {
        return $this->get() !== null;
    }

    public function clear(): void
    {
        session()->remove(self::KEY);
    }
}
