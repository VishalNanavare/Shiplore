<?php

declare(strict_types=1);

namespace App\Libraries\Catalog;

/**
 * BarcodeDecision — Phase X5: the pure POS quick-create decision engine
 * (doc 44 §3.2, edge cases E1–E12). Given what was scanned/typed and what
 * already exists in the vendor's catalog, it decides exactly one action:
 *
 *   update_stock   — barcode + MRP + GST + pack all match → stock receive
 *   new_variant    — same product family, attribute差 → new variant with a
 *                    NEW internal barcode (never re-point a live EAN)
 *   gst_change_request — same physical item, only the tax rate differs →
 *                    route to AR-06 (a govt rate change is not a new variant)
 *   new_product    — barcode unknown to this vendor → quick-create (POS-only)
 *   weighted_item  — price-embedded scale barcode (prefix 2x) → sale line, never a create
 *   reject_combo   — code belongs to a bundle/combo → component ops forbidden
 *   reject_invalid — malformed EAN/UPC checksum (when strict)
 *
 * Pure + fully unit-tested; the endpoint/UI wires it to repositories later.
 */
final class BarcodeDecision
{
    /**
     * @param array<string,mixed>      $scan     barcode, mrp, gst_rate, pack_size
     * @param array<string,mixed>|null $existing the vendor's variant carrying this
     *        barcode: mrp, gst_rate, pack_size, is_combo, status — null if none
     * @param bool $strictChecksum reject invalid EAN13/UPC instead of treating as internal
     * @return array{action:string,reason:string}
     */
    public static function decide(array $scan, ?array $existing, bool $strictChecksum = false): array
    {
        $code = trim((string) ($scan['barcode'] ?? ''));

        // E12 — weighted/PLU barcodes embed price/weight; never a product create
        if (preg_match('/^2[0-9]{12}$/', $code) === 1) {
            return ['action' => 'weighted_item', 'reason' => 'price-embedded scale barcode'];
        }

        // E8 — checksum validation for retail symbologies
        if ($code !== '' && preg_match('/^[0-9]{12,13}$/', $code) === 1 && ! self::checksumOk($code)) {
            if ($strictChecksum) {
                return ['action' => 'reject_invalid', 'reason' => 'EAN/UPC checksum failed'];
            }
            // tolerated: stored as internal symbology by the caller
        }

        // E5/E7 — unknown to THIS vendor (uniqueness scope is per-vendor)
        if ($existing === null) {
            return ['action' => 'new_product', 'reason' => $code === '' ? 'no barcode — auto internal code' : 'barcode unknown to this vendor'];
        }

        // E6 — combos are managed by the combo engine, not component ops
        if ((bool) ($existing['is_combo'] ?? false)) {
            return ['action' => 'reject_combo', 'reason' => 'barcode belongs to a combo'];
        }

        // E9 — discontinued variant: restore request vs new variant is a human call
        if (($existing['status'] ?? '') === 'discontinued') {
            return ['action' => 'new_variant', 'reason' => 'existing variant is discontinued — fresh variant with new internal barcode'];
        }

        $mrpSame  = self::numEq($scan['mrp'] ?? null, $existing['mrp'] ?? null);
        $gstSame  = self::numEq($scan['gst_rate'] ?? null, $existing['gst_rate'] ?? null);
        $packSame = self::packEq($scan['pack_size'] ?? null, $existing['pack_size'] ?? null);

        // E1 — exact tuple match → stock receive on the existing variant
        if ($mrpSame && $gstSame && $packSame) {
            return ['action' => 'update_stock', 'reason' => 'barcode, MRP, GST and pack all match'];
        }

        // E3 — ONLY the GST differs: that's a rate change on the same physical
        // item (govt revision) → tax-class change request, NOT a new variant.
        if ($mrpSame && $packSame && ! $gstSame) {
            return ['action' => 'gst_change_request', 'reason' => 'same item, different GST — route to AR-06'];
        }

        // E2/E4 — MRP or pack differs → new variant, NEW internal barcode
        $what = [];
        if (! $mrpSame) {
            $what[] = 'MRP';
        }
        if (! $packSame) {
            $what[] = 'pack size';
        }

        return ['action' => 'new_variant', 'reason' => implode(' + ', $what) . ' differ — new variant with new internal barcode; scanned code stays on the old variant'];
    }

    /** Pure: EAN-13 / UPC-A check digit. */
    public static function checksumOk(string $code): bool
    {
        $digits = str_split($code);
        $check  = (int) array_pop($digits);
        $digits = array_reverse($digits); // weight from the right: 3,1,3,1…
        $sum    = 0;
        foreach ($digits as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10 === $check;
    }

    private static function numEq(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return true; // unspecified on either side → not a difference signal
        }

        return abs((float) $a - (float) $b) < 0.005;
    }

    private static function packEq(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null || $a === '' || $b === '') {
            return true;
        }

        return strtolower(trim((string) $a)) === strtolower(trim((string) $b));
    }
}
