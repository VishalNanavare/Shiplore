<?php

declare(strict_types=1);

namespace App\Libraries\Store;

use App\Libraries\Store\Vertical;
use App\Libraries\Tax\GstCalculator;
use Config\Database;

/**
 * CartService — session cart for the storefront (works for guests). Resolves
 * variant lines from the catalog and computes GST-inclusive totals through the
 * GST engine, applying a coupon percentage and a delivery fee.
 *
 * @see docs/architecture/05-SYSTEM-ARCHITECTURE.md
 */
final class CartService
{
    private const KEY = 'store_cart';
    // Fallback constants used when no shop-specific delivery rules are found.
    private const FREE_DELIVERY_OVER = 500.0;
    private const DELIVERY_FEE = 40.0;
    // Flat handling charge applied to customer-app orders (Blinkit-style). The web
    // storefront does not pass it, so its totals are unchanged.
    public const HANDLING_FEE = 5.0;

    public function add(int $variantId, int $qty = 1): void
    {
        $cart = $this->raw();
        $cart[$variantId] = max(1, ($cart[$variantId] ?? 0) + $qty);
        session()->set(self::KEY, $cart);
    }

    public function setQty(int $variantId, int $qty): void
    {
        $cart = $this->raw();
        if ($qty <= 0) {
            unset($cart[$variantId]);
        } else {
            $cart[$variantId] = $qty;
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

    /** @return array<int,int> variantId => qty */
    public function raw(): array
    {
        $c = session()->get(self::KEY);

        return is_array($c) ? $c : [];
    }

    public function count(): int
    {
        return array_sum($this->raw());
    }

    public function isEmpty(): bool
    {
        return $this->raw() === [];
    }

    /** The vertical the cart currently holds (goods|food), or null when empty. */
    public function vertical(): ?string
    {
        $items = $this->items();

        return $items === [] ? null : ($items[0]['vertical'] ?? Vertical::GOODS);
    }

    /** Distinct vendor ids currently in the cart. @return list<int> */
    public function vendorIds(): array
    {
        return array_values(array_unique(array_map(static fn ($l) => (int) $l['vendor_id'], $this->items())));
    }

    /** @return list<array<string,mixed>> Resolved cart lines. */
    public function items(): array
    {
        return $this->linesFor($this->raw());
    }

    /**
     * Remove cart items that no in-range shop can deliver to ($lat,$lng), capping
     * each shop's reach at the admin "Max delivery radius (km)". Only the failing
     * variants are dropped — deliverable items from other shops stay (HR1). Shared
     * by the location-change guard and checkout address selection.
     *
     * @return list<array{variant_id:int, shop_id:?int, title:string, reason:string}>
     *         the removed items, each with a canonical reason for distinct messaging.
     */
    public function removeUndeliverable(float $lat, float $lng): array
    {
        if ($this->isEmpty()) {
            return [];
        }
        $catalog  = service('storeCatalogRepository');
        $adminMax = service('settingsRepository')->deliveryMaxRadiusKm();
        $removed  = [];
        foreach ($this->items() as $line) {
            $vid = (int) ($line['variant_id'] ?? 0);
            if ($vid <= 0) {
                continue;
            }
            $d = $catalog->variantDeliverability($vid, $lat, $lng, $adminMax);
            if (! $d['deliverable']) {
                $this->remove($vid);
                $removed[] = [
                    'variant_id' => $vid,
                    'shop_id'    => $d['shop_id'],
                    'title'      => (string) ($line['title'] ?? 'Item'),
                    'reason'     => (string) $d['reason'],
                ];
            }
        }

        return $removed;
    }

    /**
     * Resolve a [variantId => qty] map into priced lines (reused by the mobile
     * API checkout, which holds the cart client-side).
     * @param array<int,int> $cart
     * @return list<array<string,mixed>>
     */
    public function linesFor(array $cart): array
    {
        if ($cart === []) {
            return [];
        }

        // Only resolve variants that are genuinely buyable: published, online,
        // publicly visible, inside their availability window, and an active variant.
        // This keeps draft/unpublished/expired/hidden/inactive items out of the
        // cart total AND out of checkout (they silently drop if their status changed).
        $today = date('Y-m-d');
        $rows  = Database::connect()->table('product_variants pv')
            ->select('pv.id, pv.base_price, pv.mrp, pv.sku, p.title, p.slug, p.vendor_id, p.id AS product_id, tc.code AS tax_code, v.display_name AS vendor, bt.code AS bt_code')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->join('tax_classes tc', 'tc.id = p.tax_class_id', 'left')
            ->join('vendors v', 'v.id = p.vendor_id', 'left')
            ->join('business_types bt', 'bt.id = v.business_type_id', 'left')
            ->whereIn('pv.id', array_keys($cart))
            ->where('p.deleted_at', null)->where('p.status', 'published')->where('p.is_online_enabled', 1)
            ->whereIn('p.visibility', ['public', 'logged_in'])
            ->groupStart()->where('p.available_from', null)->orWhere('p.available_from <=', $today)->groupEnd()
            ->groupStart()->where('p.available_to', null)->orWhere('p.available_to >=', $today)->groupEnd()
            ->where('pv.status', 'active')->where('pv.deleted_at', null)
            ->get()->getResultArray();

        $lines = [];
        foreach ($rows as $r) {
            $qty   = (int) ($cart[(int) $r['id']] ?? 0);
            $price = (float) $r['base_price'];
            $lines[] = [
                'variant_id' => (int) $r['id'],
                'product_id' => (int) $r['product_id'],
                'vendor_id'  => (int) $r['vendor_id'],
                'vendor'     => $r['vendor'] ?? '',
                'vertical'   => Vertical::forBusinessType($r['bt_code'] ?? null),
                'title'      => $r['title'],
                'slug'       => $r['slug'],
                'sku'        => $r['sku'],
                'price'      => $price,
                'mrp'        => (float) $r['mrp'],
                'qty'        => $qty,
                'tax_rate'   => $this->rate((string) ($r['tax_code'] ?? '')),
                'line_total' => round($price * $qty, 2),
            ];
        }

        return $lines;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param float $tip      delivery-partner tip (customer app); added to grand.
     * @param float $handling flat handling charge (pass CartService::HANDLING_FEE);
     *                        only applied when the cart is non-empty.
     * @return array{subtotal:string,discount:string,taxable:string,tax:string,delivery:string,handling:string,tip:string,savings:string,grand:string,count:int}
     */
    public function totals(array $items, float $couponPct = 0.0, ?GstCalculator $gst = null, float $tip = 0.0, float $handling = 0.0): array
    {
        $gst    = $gst ?? new GstCalculator();
        $factor = max(0.0, 1 - $couponPct / 100);

        $subtotal = 0.0;
        $taxable  = 0.0;
        $tax      = 0.0;
        $savings  = 0.0;
        $count    = 0;
        foreach ($items as $it) {
            $subtotal += (float) $it['line_total'];
            $count    += (int) $it['qty'];
            $savings  += max(0.0, (float) $it['mrp'] - (float) $it['price']) * (int) $it['qty'];
            $lineAfter = (float) $it['line_total'] * $factor;
            $g = $gst->compute((string) $lineAfter, (float) $it['tax_rate'], true, false);
            $taxable += (float) $g['taxable'];
            $tax     += (float) $g['tax'];
        }
        $discount  = round($subtotal * $couponPct / 100, 2);
        $afterDisc = $subtotal - $discount;
        $delivery  = $this->deliveryFee($afterDisc, $items);
        $tip       = max(0.0, $tip);
        $handling  = $subtotal > 0 ? max(0.0, $handling) : 0.0;
        $grand     = round($afterDisc + $delivery + $handling + $tip, 2);

        return [
            'subtotal' => $this->fmt($subtotal),
            'discount' => $this->fmt($discount),
            'taxable'  => $this->fmt($taxable),
            'tax'      => $this->fmt($tax),
            'delivery' => $this->fmt($delivery),
            'handling' => $this->fmt($handling),
            'tip'      => $this->fmt($tip),
            'savings'  => $this->fmt($savings),
            'grand'    => $this->fmt($grand),
            'count'    => $count,
        ];
    }

    /**
     * Compute delivery fee using shop-specific rules when available.
     * Uses the most user-favourable rules across all shops in the cart
     * (lowest fee + lowest free-delivery threshold). Falls back to the
     * class constants when no matching active shop is found.
     *
     * @param list<array<string,mixed>> $items Resolved cart lines (must include vendor_id).
     */
    private function deliveryFee(float $afterDisc, array $items): float
    {
        if ($afterDisc <= 0) {
            return 0.0;
        }
        $vendorIds = array_values(array_unique(array_map(static fn ($l) => (int) $l['vendor_id'], $items)));
        if ($vendorIds === []) {
            return $afterDisc >= self::FREE_DELIVERY_OVER ? 0.0 : self::DELIVERY_FEE;
        }
        $row = Database::connect()->table('shops')
            ->selectMin('delivery_fee', 'fee')
            ->selectMin('free_delivery_above', 'free_above')
            ->whereIn('vendor_id', $vendorIds)
            ->where('status', 'active')->where('deleted_at', null)
            ->get()->getRowArray();

        $fee       = ($row && $row['fee'] !== null) ? (float) $row['fee'] : self::DELIVERY_FEE;
        $freeAbove = ($row && $row['free_above'] !== null) ? (float) $row['free_above'] : self::FREE_DELIVERY_OVER;

        return $afterDisc >= $freeAbove ? 0.0 : $fee;
    }

    private function rate(string $taxCode): float
    {
        return preg_match('/(\d+)/', $taxCode, $m) ? (float) $m[1] : 0.0;
    }

    private function fmt(float $v): string
    {
        return number_format(round($v, 2), 2, '.', '');
    }
}
