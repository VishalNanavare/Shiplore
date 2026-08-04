<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Catalog\PurchaseRules;
use App\Libraries\Money;
use App\Libraries\Workflow\StatusMachine;
use Config\Database;
use Throwable;

/**
 * PurchaseOrderRepository — monline B2B purchase orders.
 *
 * A vendor/shop buys from a manufacturer. One PO per manufacturer: a cart spanning two
 * manufacturers is split at placement, because each is fulfilled and received separately.
 *
 * Non-negotiables enforced here rather than trusted from the caller:
 *
 *   * PRICES COME FROM THE DATABASE. The client sends variant ids and quantities only.
 *     unit_price is read from product_variants.base_price at placement. A client-supplied
 *     price is never read, so there is nothing to tamper with.
 *   * MAKING PRICE NEVER LEAVES THE MANUFACTURER. It is not selected, not stored on the
 *     PO line, and not returned by any method here.
 *   * MOQ is re-validated server-side via PurchaseRules against products.min_purchase_qty
 *     and qty_step — the same validator the consumer cart uses.
 *   * Every status change goes through StatusMachine::canPurchaseOrder(), so an illegal
 *     move (e.g. received -> placed) is refused rather than persisted.
 */
final class PurchaseOrderRepository
{
    /**
     * Place a cart as one or more POs, one per manufacturer.
     *
     * @param array<int,float> $cart variantId => qty
     * @return array{ok:bool,error:string,po_ids:list<int>}
     */
    public function placeFromCart(int $buyerVendorId, int $buyerShopId, array $cart, ?string $notes, ?int $actorId): array
    {
        if ($buyerVendorId <= 0 || $buyerShopId <= 0) {
            return ['ok' => false, 'error' => 'Select a destination shop.', 'po_ids' => []];
        }
        if ($cart === []) {
            return ['ok' => false, 'error' => 'Your order is empty.', 'po_ids' => []];
        }

        $lines = $this->resolveLines($cart);
        if ($lines['error'] !== '') {
            return ['ok' => false, 'error' => $lines['error'], 'po_ids' => []];
        }

        $db = Database::connect();

        // Buyer context for GST: the destination shop's state is the place of supply.
        $shop = $db->table('shops')->select('state_code, gstin')
            ->where('id', $buyerShopId)->where('vendor_id', $buyerVendorId)->where('deleted_at', null)
            ->get()->getRowArray();
        if ($shop === null) {
            return ['ok' => false, 'error' => 'That shop does not belong to your business.', 'po_ids' => []];
        }

        // One PO per seller — each is accepted, dispatched and received independently.
        $bySeller = [];
        foreach ($lines['rows'] as $r) {
            $bySeller[(int) $r['seller_vendor_id']][] = $r;
        }

        $poIds  = [];
        $placed = []; // seller notifications, queued here and fired only after the commit
        $db->transBegin();

        try {
            foreach ($bySeller as $sellerId => $rows) {
                $r        = $this->insertPo($db, $buyerVendorId, $buyerShopId, (int) $sellerId, $rows, $shop, $notes, $actorId);
                $poIds[]  = $r['id'];
                $placed[] = ['seller_vendor_id' => (int) $sellerId, 'po_no' => $r['po_no']];
            }
            $db->transComplete();

            if (! $db->transStatus()) {
                return ['ok' => false, 'error' => 'Could not place the order.', 'po_ids' => []];
            }

            // Only after the commit is confirmed — a rolled-back placement must never
            // tell a manufacturer they have a new order.
            foreach ($placed as $p) {
                $this->notifyVendorOwner($p['seller_vendor_id'], 'po.placed', ['po_no' => $p['po_no']]);
            }

            return ['ok' => true, 'error' => '', 'po_ids' => $poIds, 'dropped' => $lines['dropped']];
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'monline PO placement failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'Could not place the order.', 'po_ids' => []];
        }
    }

    /**
     * Resolve cart variant ids to priced, MOQ-checked lines.
     *
     * An id that no longer resolves (unpublished, deleted, its manufacturer no longer
     * approved) is DROPPED rather than failing the whole cart — the buyer cannot fix that
     * by editing a quantity, so blocking every other, perfectly valid line over it would
     * be a dead end with no way out. A quantity that fails MOQ/step IS fixable by the
     * buyer, so that stays a hard error naming the specific line, not a silent drop.
     *
     * Note what is selected: base_price (the SELLING price) and never making_price.
     *
     * @param array<int,float> $cart
     * @return array{error:string,rows:list<array<string,mixed>>,dropped:list<int>}
     */
    private function resolveLines(array $cart): array
    {
        $ids = array_values(array_filter(array_map('intval', array_keys($cart))));
        if ($ids === []) {
            return ['error' => 'Your order is empty.', 'rows' => [], 'dropped' => []];
        }

        // The GST rate comes from `tax_rates`, the dated source of truth, not from
        // parsing the tax-class code. igst equals cgst+sgst for a given class, so it
        // is the total rate GstCalculator::compute() expects and then splits itself.
        $today = date('Y-m-d');
        $rows  = Database::connect()->table('product_variants pv')
            ->select('pv.id, pv.base_price, pv.sku, p.id AS product_id, p.title, p.vendor_id AS seller_vendor_id,
                      p.min_purchase_qty, p.max_purchase_qty, p.qty_step, p.status,
                      COALESCE(tr.igst, tr.cgst + tr.sgst, 0) AS tax_rate,
                      hs.code AS hsn_code, v.state_code AS seller_state', false)
            ->join('products p', 'p.id = pv.product_id')
            ->join('vendors v', 'v.id = p.vendor_id')
            ->join(
                'tax_rates tr',
                "tr.tax_class_id = p.tax_class_id AND tr.status = 'active'"
                . " AND tr.valid_from <= " . Database::connect()->escape($today)
                . " AND (tr.valid_to IS NULL OR tr.valid_to >= " . Database::connect()->escape($today) . ')',
                'left',
            )
            ->join('hsn_sac_codes hs', 'hs.id = p.hsn_sac_id', 'left')
            ->whereIn('pv.id', $ids)
            ->where('pv.deleted_at', null)->where('pv.status', 'active')
            ->where('p.deleted_at', null)->where('p.status', 'published')
            // Only manufacturers sell on monline.
            ->where('v.party_type', 'manufacturer')
            ->where('v.deleted_at', null)
            // Same account-status gate MonlineCatalogRepository::base() applies to browsing.
            // Without it, a stale or guessed variant id could be ordered from a manufacturer
            // that is still submitted, or was rejected/suspended after the cart was filled.
            ->whereIn('v.status', ['approved', 'active'])
            ->get()->getResultArray();

        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }

        $out     = [];
        $dropped = [];
        foreach ($cart as $variantId => $qty) {
            $vid = (int) $variantId;
            $qty = (float) $qty;
            if (! isset($byId[$vid])) {
                $dropped[] = $vid;
                continue;
            }
            $r = $byId[$vid];

            // Same validator the consumer cart uses — MOQ and pack multiples.
            $check = PurchaseRules::validate($qty, [
                'min_purchase_qty' => $r['min_purchase_qty'],
                'max_purchase_qty' => $r['max_purchase_qty'],
                'qty_step'         => $r['qty_step'],
            ]);
            if (! $check['ok']) {
                return ['error' => $r['title'] . ': ' . $check['message'], 'rows' => [], 'dropped' => []];
            }

            $r['qty'] = $qty;
            $out[]    = $r;
        }

        if ($out === []) {
            return ['error' => 'None of the items in your order are available anymore.', 'rows' => [], 'dropped' => $dropped];
        }

        return ['error' => '', 'rows' => $out, 'dropped' => $dropped];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed>       $shop
     * @return array{id:int,po_no:string} po_no is returned so the caller can notify the
     *                                    seller AFTER the transaction commits
     */
    private function insertPo(object $db, int $buyerVendorId, int $buyerShopId, int $sellerId, array $rows, array $shop, ?string $notes, ?int $actorId): array
    {
        $gst         = service('gstCalculator');
        $placeOf     = (string) ($shop['state_code'] ?? '');
        $sellerState = (string) ($rows[0]['seller_state'] ?? '');
        $inter       = $gst->isInterState($sellerState, $placeOf);

        $poNo = 'PO-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $db->table('mfg_purchase_orders')->insert([
            'uuid'             => $this->uuid(),
            'po_no'            => $poNo,
            'buyer_vendor_id'  => $buyerVendorId,
            'buyer_shop_id'    => $buyerShopId,
            'seller_vendor_id' => $sellerId,
            'buyer_gstin'      => $shop['gstin'] ?? null,
            'place_of_supply'  => $placeOf !== '' ? $placeOf : null,
            'status'           => 'placed',
            'notes'            => $notes !== null && $notes !== '' ? mb_substr($notes, 0, 500) : null,
            'placed_at'        => date('Y-m-d H:i:s'),
            'placed_by'        => $actorId,
            'created_by'       => $actorId,
            'updated_by'       => $actorId,
        ]);
        $poId = (int) $db->insertID();

        $sub = Money::of('0');
        $tax = ['cgst' => Money::of('0'), 'sgst' => Money::of('0'), 'igst' => Money::of('0'), 'cess' => Money::of('0')];
        $taxable = Money::of('0');

        foreach ($rows as $r) {
            $qty  = (string) $r['qty'];
            $unit = Money::of((string) $r['base_price']);
            $line = $unit->mulQty($qty);
            $rate = (float) ($r['tax_rate'] ?? 0);

            // B2B prices are quoted exclusive of GST.
            $g = $gst->compute($line->amount(), $rate, false, $inter);

            $sub     = $sub->add($line);
            $taxable = $taxable->add(Money::of((string) $g['taxable']));
            foreach (['cgst', 'sgst', 'igst', 'cess'] as $k) {
                $tax[$k] = $tax[$k]->add(Money::of((string) ($g[$k] ?? '0')));
            }

            $db->table('mfg_purchase_order_items')->insert([
                'po_id'                  => $poId,
                'variant_id'             => (int) $r['id'],
                'product_title_snapshot' => (string) $r['title'],
                'sku_snapshot'           => $r['sku'] ?? null,
                'hsn_snapshot'           => $r['hsn_code'] ?? null,
                'qty'                    => $qty,
                'unit_price'             => $unit->amount(),
                'taxable_value'          => (string) $g['taxable'],
                'tax_rate'               => $rate,
                'cgst'                   => (string) ($g['cgst'] ?? '0'),
                'sgst'                   => (string) ($g['sgst'] ?? '0'),
                'igst'                   => (string) ($g['igst'] ?? '0'),
                'cess'                   => (string) ($g['cess'] ?? '0'),
                'line_total'             => (string) $g['total'],
            ]);
        }

        $grand = $taxable->add($tax['cgst'])->add($tax['sgst'])->add($tax['igst'])->add($tax['cess']);

        $db->table('mfg_purchase_orders')->where('id', $poId)->update([
            'subtotal'      => $sub->amount(),
            'taxable_value' => $taxable->amount(),
            'cgst'          => $tax['cgst']->amount(),
            'sgst'          => $tax['sgst']->amount(),
            'igst'          => $tax['igst']->amount(),
            'cess'          => $tax['cess']->amount(),
            'grand_total'   => $grand->amount(),
        ]);

        return ['id' => $poId, 'po_no' => $poNo];
    }

    /** POs placed BY this vendor. @return list<array<string,mixed>> */
    public function listForBuyer(int $buyerVendorId, ?array $shopIds = null, ?string $status = null): array
    {
        if ($buyerVendorId <= 0) {
            return [];
        }
        $b = Database::connect()->table('mfg_purchase_orders po')
            ->select('po.*, v.display_name AS seller_name, s.name AS shop_name')
            ->join('vendors v', 'v.id = po.seller_vendor_id', 'left')
            ->join('shops s', 's.id = po.buyer_shop_id', 'left')
            ->where('po.buyer_vendor_id', $buyerVendorId)
            ->where('po.deleted_at', null);

        // Staff are scoped to their assigned shops.
        if ($shopIds !== null) {
            $b->whereIn('po.buyer_shop_id', $shopIds ?: [0]);
        }
        if ($status !== null && $status !== '') {
            $b->where('po.status', $status);
        }

        return $b->orderBy('po.id', 'DESC')->limit(200)->get()->getResultArray();
    }

    /** POs received BY this manufacturer. @return list<array<string,mixed>> */
    public function listForSeller(int $sellerVendorId, ?string $status = null): array
    {
        if ($sellerVendorId <= 0) {
            return [];
        }
        $b = Database::connect()->table('mfg_purchase_orders po')
            ->select('po.*, v.display_name AS buyer_name, s.name AS buyer_shop_name')
            ->join('vendors v', 'v.id = po.buyer_vendor_id', 'left')
            ->join('shops s', 's.id = po.buyer_shop_id', 'left')
            ->where('po.seller_vendor_id', $sellerVendorId)
            ->where('po.deleted_at', null);

        if ($status !== null && $status !== '') {
            $b->where('po.status', $status);
        }

        return $b->orderBy('po.id', 'DESC')->limit(200)->get()->getResultArray();
    }

    // ---- Platform oversight ------------------------------------------------
    // Unscoped to either trading party. Only ever reached from the admin panel,
    // behind monline.po.oversight.* permissions.

    /** @param array<string,mixed> $f keys: status, q, buyer_vendor_id, seller_vendor_id */
    private function adminBaseQuery(array $f): object
    {
        $b = Database::connect()->table('mfg_purchase_orders po')
            ->select('po.*, bv.display_name AS buyer_name, sv.display_name AS seller_name, s.name AS buyer_shop_name')
            ->join('vendors bv', 'bv.id = po.buyer_vendor_id', 'left')
            ->join('vendors sv', 'sv.id = po.seller_vendor_id', 'left')
            ->join('shops s', 's.id = po.buyer_shop_id', 'left')
            ->where('po.deleted_at', null);

        if (! empty($f['status'])) {
            $b->where('po.status', $f['status']);
        }
        if (! empty($f['buyer_vendor_id'])) {
            $b->where('po.buyer_vendor_id', (int) $f['buyer_vendor_id']);
        }
        if (! empty($f['seller_vendor_id'])) {
            $b->where('po.seller_vendor_id', (int) $f['seller_vendor_id']);
        }
        if (! empty($f['q'])) {
            $b->groupStart()
                ->like('po.po_no', $f['q'])
                ->orLike('bv.display_name', $f['q'])
                ->orLike('sv.display_name', $f['q'])
                ->groupEnd();
        }

        return $b;
    }

    /** @return list<array<string,mixed>> */
    public function listForAdmin(array $f = [], int $limit = 50, int $offset = 0): array
    {
        return $this->adminBaseQuery($f)->orderBy('po.id', 'DESC')->limit($limit, $offset)->get()->getResultArray();
    }

    public function countForAdmin(array $f = []): int
    {
        return $this->adminBaseQuery($f)->countAllResults();
    }

    /**
     * A PO with its lines, for platform staff. Unlike findFor(), cancelled lines are
     * included — oversight should see what was removed, not a tidied-up version.
     *
     * @return array{po:array<string,mixed>,items:list<array<string,mixed>>}|null
     */
    public function findForAdmin(int $poId): ?array
    {
        $po = Database::connect()->table('mfg_purchase_orders po')
            ->select('po.*, bv.display_name AS buyer_name, bv.gstin AS buyer_vendor_gstin, bv.owner_user_id AS buyer_owner_user_id,
                      sv.display_name AS seller_name, sv.gstin AS seller_gstin, sv.owner_user_id AS seller_owner_user_id,
                      s.name AS buyer_shop_name')
            ->join('vendors bv', 'bv.id = po.buyer_vendor_id', 'left')
            ->join('vendors sv', 'sv.id = po.seller_vendor_id', 'left')
            ->join('shops s', 's.id = po.buyer_shop_id', 'left')
            ->where('po.id', $poId)->where('po.deleted_at', null)
            ->get()->getRowArray();
        if ($po === null) {
            return null;
        }

        $items = Database::connect()->table('mfg_purchase_order_items')
            ->where('po_id', $poId)->orderBy('id')->get()->getResultArray();

        return ['po' => $po, 'items' => $items];
    }

    /**
     * Platform force-cancel — the only write oversight has, for an order stuck between
     * two parties who will not move it.
     *
     * Deliberately delegated to the same StatusMachine gate everyone else uses, so it
     * cannot cancel a dispatched or received order: stock has moved by then and nothing
     * here would unwind it. The reason is stored in the existing reject_reason column,
     * prefixed, rather than adding a schema column for a rare action.
     *
     * @return array{ok:bool,error:string}
     */
    public function adminCancel(int $poId, ?int $actorId, string $reason): array
    {
        $po = Database::connect()->table('mfg_purchase_orders')
            ->where('id', $poId)->where('deleted_at', null)->get()->getRowArray();
        if ($po === null) {
            return ['ok' => false, 'error' => 'Purchase order not found.'];
        }

        $from = (string) $po['status'];
        if (! StatusMachine::canPurchaseOrder($from, 'cancelled')) {
            return ['ok' => false, 'error' => 'A ' . $from . ' order cannot be cancelled — the goods have already moved.'];
        }

        Database::connect()->table('mfg_purchase_orders')->where('id', $poId)->update([
            'status'        => 'cancelled',
            'reject_reason' => mb_substr('[Admin] ' . $reason, 0, 255),
            'updated_by'    => $actorId,
        ]);

        foreach ([(int) $po['buyer_vendor_id'], (int) $po['seller_vendor_id']] as $vid) {
            $this->notifyVendorOwner($vid, 'po.cancelled', ['po_no' => $po['po_no']]);
        }

        return ['ok' => true, 'error' => ''];
    }

    /** GST totals across a manufacturer's settled B2B orders. @return array<string,mixed> */
    public function sellerGstSummary(int $sellerVendorId): array
    {
        $row = Database::connect()->table('mfg_purchase_orders')
            ->select('COUNT(*) AS orders, COALESCE(SUM(taxable_value),0) AS taxable, COALESCE(SUM(cgst),0) AS cgst,
                      COALESCE(SUM(sgst),0) AS sgst, COALESCE(SUM(igst),0) AS igst, COALESCE(SUM(grand_total),0) AS grand', false)
            ->where('seller_vendor_id', $sellerVendorId)
            ->where('deleted_at', null)
            ->whereNotIn('status', ['draft', 'cancelled', 'rejected'])
            ->get()->getRowArray();

        return $row ?: ['orders' => 0, 'taxable' => '0', 'cgst' => '0', 'sgst' => '0', 'igst' => '0', 'grand' => '0'];
    }

    /**
     * A PO with its lines, visible only to its buyer or its seller.
     *
     * @return array{po:array<string,mixed>,items:list<array<string,mixed>>}|null
     */
    public function findFor(int $poId, int $vendorId, string $side): ?array
    {
        $col = $side === 'seller' ? 'seller_vendor_id' : 'buyer_vendor_id';
        $po  = Database::connect()->table('mfg_purchase_orders')
            ->where('id', $poId)->where($col, $vendorId)->where('deleted_at', null)
            ->get()->getRowArray();
        if ($po === null) {
            return null;
        }

        $items = Database::connect()->table('mfg_purchase_order_items')
            ->where('po_id', $poId)->where('status', 'active')
            ->orderBy('id')->get()->getResultArray();

        return ['po' => $po, 'items' => $items];
    }

    /**
     * Move a PO to a new status, validating the transition.
     *
     * @return array{ok:bool,error:string}
     */
    public function transition(int $poId, int $vendorId, string $side, string $to, ?int $actorId, array $extra = []): array
    {
        $found = $this->findFor($poId, $vendorId, $side);
        if ($found === null) {
            return ['ok' => false, 'error' => 'Purchase order not found.'];
        }
        $from = (string) $found['po']['status'];

        if (! StatusMachine::canPurchaseOrder($from, $to)) {
            return ['ok' => false, 'error' => 'Cannot move a ' . $from . ' order to ' . $to . '.'];
        }

        $set = array_merge(['status' => $to, 'updated_by' => $actorId], $extra);
        $now = date('Y-m-d H:i:s');
        if ($to === 'accepted') {
            $set['accepted_at'] = $now;
            $set['accepted_by'] = $actorId;
        } elseif ($to === 'dispatched') {
            $set['dispatched_at'] = $now;
        } elseif ($to === 'received') {
            $set['received_at'] = $now;
            $set['received_by'] = $actorId;
        }

        Database::connect()->table('mfg_purchase_orders')->where('id', $poId)->update($set);

        // Only the seller ever drives these three; the buyer's only transition is
        // 'cancelled'. So branching on $to alone is enough — no $side check needed.
        if (in_array($to, ['accepted', 'rejected', 'dispatched'], true)) {
            $this->notifyVendorOwner((int) $found['po']['buyer_vendor_id'], 'po.' . $to, ['po_no' => $found['po']['po_no']]);
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Buyer marks the PO received: raise stock at the destination shop.
     *
     * Reuses InventoryService::receive(), which is tenant-agnostic — it takes a variant
     * and a shop and leaves ownership to the caller, which is checked by findFor() above.
     * Each line writes an inventory_ledger row referencing this PO, so the buyer's stock
     * history explains itself without a second manual entry.
     *
     * @return array{ok:bool,error:string}
     */
    public function receive(int $poId, int $buyerVendorId, ?int $actorId): array
    {
        $found = $this->findFor($poId, $buyerVendorId, 'buyer');
        if ($found === null) {
            return ['ok' => false, 'error' => 'Purchase order not found.'];
        }
        $po   = $found['po'];
        $from = (string) $po['status'];
        // Explicit status check, not StatusMachine::canPurchaseOrder() alone: that helper
        // treats $from === $to as an idempotent no-op, which is fine for a transition that
        // just rewrites a timestamp but wrong here — a second call on an already-'received'
        // order would otherwise pass the guard and credit stock a second time.
        if ($from !== 'dispatched') {
            return ['ok' => false, 'error' => 'Only a dispatched order can be received.'];
        }

        $db      = Database::connect();
        $shopId  = (int) $po['buyer_shop_id'];
        $service = service('inventoryService');

        $db->transBegin();

        try {
            foreach ($found['items'] as $item) {
                $qty = (float) $item['qty'];
                if ($qty <= 0) {
                    continue;
                }
                $service->receive(
                    (int) $item['variant_id'],
                    $shopId,
                    $qty,
                    (float) $item['unit_price'],
                    ['ref_type' => 'mfg_purchase_order', 'ref_id' => $poId],
                    $actorId,
                );
                $db->table('mfg_purchase_order_items')->where('id', (int) $item['id'])
                    ->update(['qty_received' => $qty]);
            }

            $db->table('mfg_purchase_orders')->where('id', $poId)->update([
                'status'      => 'received',
                'received_at' => date('Y-m-d H:i:s'),
                'received_by' => $actorId,
                'updated_by'  => $actorId,
            ]);

            $db->transComplete();

            if (! $db->transStatus()) {
                return ['ok' => false, 'error' => 'Could not record the receipt.'];
            }

            $this->notifyVendorOwner((int) $po['seller_vendor_id'], 'po.received', ['po_no' => $po['po_no']]);

            return ['ok' => true, 'error' => ''];
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'monline PO receive failed for PO ' . $poId . ': ' . $e->getMessage());

            return ['ok' => false, 'error' => 'Could not record the receipt.'];
        }
    }

    /**
     * Best-effort notification to a vendor's or manufacturer's owner.
     *
     * Mirrors TransferService::notifyShop(): silent no-op if the owner cannot be
     * resolved, and never throws — a notification failure must not roll back or block
     * a purchase order that has already been committed.
     */
    private function notifyVendorOwner(int $vendorId, string $eventCode, array $vars): void
    {
        try {
            $row = Database::connect()->table('vendors')->select('owner_user_id')
                ->where('id', $vendorId)->get()->getRowArray();
            $uid = $row['owner_user_id'] ?? null;
            if ($uid) {
                service('notificationService')->notify((int) $uid, $eventCode, $vars);
            }
        } catch (Throwable) {
        }
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0F) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
