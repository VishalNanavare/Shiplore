<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * PosSaleRepository — server-side POS sale writer for the web POS. Unlike the
 * offline-sync path (PosSyncRepository, which trusts client-computed totals),
 * this RESOLVES prices + GST on the server, writes pos_sales / pos_sale_items /
 * pos_sale_payments / pos_sale_taxes atomically, and decrements shop stock via
 * InventoryService (pos_sale ledger). Supports split tenders and a price
 * override per line. Idempotent on a client uuid.
 *
 * @see App\Libraries\Tax\GstCalculator, App\Libraries\Inventory\InventoryService
 */
final class PosSaleRepository
{
    /** Resolve sellable lines for a shop's vendor. @param array<int,float> $cart variant_id => qty @return list<array<string,mixed>> */
    public function resolveLines(int $vendorId, array $cart, ?int $shopId = null): array
    {
        $ids = array_filter(array_map('intval', array_keys($cart)));
        if ($ids === []) {
            return [];
        }
        $b = Database::connect()->table('product_variants pv')
            ->select('pv.id, pv.sku, pv.base_price, p.title, p.hsn_sac_id, hs.code AS hsn, COALESCE(tr.igst,0) AS gst_rate')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->join('hsn_sac_codes hs', 'hs.id = p.hsn_sac_id', 'left')
            ->join('tax_rates tr', "tr.tax_class_id = p.tax_class_id AND tr.status = 'active'", 'left')
            ->whereIn('pv.id', $ids)->where('pv.vendor_id', $vendorId)->where('pv.deleted_at', null);
        if ($shopId !== null) {
            // S2: only items listed in this shop may be billed here.
            $b->join('product_shops ps', 'ps.product_id = p.id AND ps.shop_id = ' . (int) $shopId . " AND ps.status = 'active'", 'inner');
        }
        $rows = $b->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $vid = (int) $r['id'];
            $out[] = [
                'variant_id' => $vid, 'sku' => $r['sku'], 'title' => $r['title'], 'hsn' => $r['hsn'] ?? '',
                'qty' => (float) $cart[$vid], 'unit_price' => (float) $r['base_price'], 'tax_rate' => (float) $r['gst_rate'],
            ];
        }

        return $out;
    }

    /**
     * Create a completed sale. Lines come pre-resolved (variant_id, qty,
     * unit_price [override allowed], tax_rate, title, sku, hsn) plus optional
     * temp lines (variant_id=0). Payments are [tender_type, amount, reference].
     * @param array<string,mixed> $ctx shop_id/vendor_id/terminal_id/shift_id/cashier_user_id
     * @return array{ok:bool, message:string, sale_id:?int, invoice_no:?string, grand_total:float, paid:float, change:float}
     */
    public function createSale(array $ctx, array $lines, array $payments, array $opts = []): array
    {
        $db       = Database::connect();
        $shopId   = (int) $ctx['shop_id'];
        $vendorId = (int) $ctx['vendor_id'];
        $cashier  = (int) ($ctx['cashier_user_id'] ?? 0);
        $clientUuid = (string) ($opts['client_uuid'] ?? '');

        // idempotency: a re-sent sale returns the original
        if ($clientUuid !== '') {
            $dup = $db->table('pos_sales')->select('id, server_invoice_no, grand_total')->where('offline_invoice_no', $clientUuid)->get()->getRowArray();
            if ($dup !== null) {
                return ['ok' => true, 'message' => 'Already recorded.', 'sale_id' => (int) $dup['id'], 'invoice_no' => $dup['server_invoice_no'], 'grand_total' => (float) $dup['grand_total'], 'paid' => 0, 'change' => 0];
            }
        }
        $lines = array_values(array_filter($lines, static fn ($l) => (float) ($l['qty'] ?? 0) > 0));
        if ($lines === []) {
            return $this->fail('Cart is empty.');
        }

        $gst = service('gstCalculator');
        $subtotal = $discountTotal = $taxable = $cgst = $sgst = $igst = $grand = 0.0;
        $billDiscount = max(0.0, (float) ($opts['bill_discount'] ?? 0));
        $computed = [];
        foreach ($lines as $l) {
            $qty   = (float) $l['qty'];
            $price = max(0.0, (float) $l['unit_price']);
            $lineDisc = max(0.0, (float) ($l['line_discount'] ?? 0));
            $gross = max(0.0, $price * $qty - $lineDisc);
            $g = $gst->compute((string) $gross, (float) ($l['tax_rate'] ?? 0), true, false); // inclusive, intra-state
            $subtotal += $price * $qty;
            $discountTotal += $lineDisc;
            $taxable += (float) $g['taxable'];
            $cgst += (float) $g['cgst'];
            $sgst += (float) $g['sgst'];
            $igst += (float) $g['igst'];
            $grand += $gross;
            $computed[] = ['l' => $l, 'qty' => $qty, 'price' => $price, 'disc' => $lineDisc, 'gross' => $gross, 'g' => $g];
        }
        $grand -= $billDiscount;
        $discountTotal += $billDiscount;
        $deliveryFee = max(0.0, (float) ($opts['delivery_fee'] ?? 0));
        $grand += $deliveryFee; // shipping charge added on top (non-taxable)
        $grand = max(0.0, $grand);
        $rounded = round($grand);
        $roundOff = round($rounded - $grand, 2);
        $grandFinal = $rounded;

        $paid = 0.0;
        foreach ($payments as $p) {
            $paid += (float) ($p['amount'] ?? 0);
        }
        $mode = (string) ($opts['mode'] ?? 'sale');
        if ($mode !== 'credit' && $paid + 0.01 < $grandFinal) {
            return $this->fail('Payment ₹' . number_format($paid, 2) . ' is less than the total ₹' . number_format($grandFinal, 2) . '.');
        }

        $db->transBegin();
        try {
            $invoiceNo = $this->nextInvoice($db, $shopId);
            $db->table('pos_sales')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'terminal_id' => $ctx['terminal_id'] ?? null, 'shop_id' => $shopId, 'vendor_id' => $vendorId,
                'shift_id' => $ctx['shift_id'] ?? null, 'cashier_user_id' => $cashier ?: null, 'customer_id' => $opts['customer_id'] ?? null,
                'offline_invoice_no' => $clientUuid ?: null, 'server_invoice_no' => $invoiceNo,
                'order_type' => ($opts['order_type'] ?? 'takeaway') === 'delivery' ? 'delivery' : 'takeaway',
                'delivery_fee' => $this->n($deliveryFee),
                'subtotal' => $this->n($subtotal), 'discount_total' => $this->n($discountTotal), 'taxable_value' => $this->n($taxable),
                'cgst' => $this->n($cgst), 'sgst' => $this->n($sgst), 'igst' => $this->n($igst), 'cess' => 0,
                'round_off' => $this->n($roundOff), 'grand_total' => $this->n($grandFinal),
                'sold_at' => date('Y-m-d H:i:s'), 'origin' => 'web_pos', 'sync_status' => 'synced', 'status' => 'completed',
            ]);
            $saleId = (int) $db->insertID();

            $taxBuckets = [];
            foreach ($computed as $c) {
                $l = $c['l'];
                $db->table('pos_sale_items')->insert([
                    'uuid' => bin2hex(random_bytes(18)), 'pos_sale_id' => $saleId, 'variant_id' => (int) ($l['variant_id'] ?? 0) ?: null,
                    'product_title_snapshot' => mb_substr((string) ($l['title'] ?? 'Item'), 0, 191), 'sku_snapshot' => mb_substr((string) ($l['sku'] ?? ''), 0, 64),
                    'hsn_snapshot' => mb_substr((string) ($l['hsn'] ?? ''), 0, 12), 'qty' => $this->n($c['qty'], 3),
                    'unit_price' => $this->n($c['price']), 'mrp_snapshot' => ($l['mrp'] ?? null) !== null && (float) $l['mrp'] > 0 ? $this->n((float) $l['mrp']) : null,
                    'discount_amount' => $this->n($c['disc']), 'taxable_value' => $c['g']['taxable'],
                    'tax_rate' => $this->n((float) ($l['tax_rate'] ?? 0), 2), 'cgst' => $c['g']['cgst'], 'sgst' => $c['g']['sgst'], 'igst' => $c['g']['igst'], 'cess' => 0,
                    'is_gst_inclusive' => 1, 'is_temporary' => (int) ($l['variant_id'] ?? 0) > 0 ? 0 : 1,
                    'line_total' => $this->n($c['gross']), 'origin' => 'web_pos', 'status' => 'active',
                ]);
                // stock decrement for real variants (temp lines have no variant)
                if ((int) ($l['variant_id'] ?? 0) > 0) {
                    service('inventoryService')->sell((int) $l['variant_id'], $shopId, $c['qty'], true, $saleId, $cashier);
                }
                $key = ($l['hsn'] ?? '') . '|' . (float) ($l['tax_rate'] ?? 0);
                $taxBuckets[$key] ??= ['hsn' => $l['hsn'] ?? '', 'rate' => (float) ($l['tax_rate'] ?? 0), 'taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
                $taxBuckets[$key]['taxable'] += (float) $c['g']['taxable'];
                $taxBuckets[$key]['cgst'] += (float) $c['g']['cgst'];
                $taxBuckets[$key]['sgst'] += (float) $c['g']['sgst'];
                $taxBuckets[$key]['igst'] += (float) $c['g']['igst'];
            }
            foreach ($taxBuckets as $tb) {
                $db->table('pos_sale_taxes')->insert([
                    'pos_sale_id' => $saleId, 'hsn' => mb_substr((string) $tb['hsn'], 0, 12), 'tax_rate' => $this->n($tb['rate'], 2),
                    'taxable_value' => $this->n($tb['taxable']), 'cgst' => $this->n($tb['cgst']), 'sgst' => $this->n($tb['sgst']), 'igst' => $this->n($tb['igst']), 'cess' => 0, 'supply_type' => 'intra',
                ]);
            }
            foreach ($payments as $p) {
                if ((float) ($p['amount'] ?? 0) <= 0) {
                    continue;
                }
                $db->table('pos_sale_payments')->insert([
                    'uuid' => bin2hex(random_bytes(18)), 'pos_sale_id' => $saleId, 'tender_type' => (string) ($p['tender_type'] ?? 'cash'),
                    'amount' => $this->n((float) $p['amount']), 'reference' => mb_substr((string) ($p['reference'] ?? ''), 0, 120), 'origin' => 'web_pos', 'status' => 'captured',
                ]);
            }

            // credit / udhaar: the unpaid remainder becomes a customer credit row
            $credit = round($grandFinal - $paid, 2);
            if ($mode === 'credit' && $credit > 0 && ! empty($opts['customer_id'])) {
                $db->table('customer_credits')->insert([
                    'uuid' => bin2hex(random_bytes(18)), 'vendor_id' => $vendorId, 'shop_id' => $shopId,
                    'customer_id' => (int) $opts['customer_id'], 'pos_sale_id' => $saleId,
                    'amount' => $this->n($credit), 'balance' => $this->n($credit),
                    'due_date' => ($opts['due_date'] ?? '') ?: null, 'status' => 'open', 'created_by' => $cashier ?: null,
                ]);
            }

            $db->transComplete();
            if (! $db->transStatus()) {
                return $this->fail('Could not save the sale.');
            }

            return ['ok' => true, 'message' => 'Sale completed.', 'sale_id' => $saleId, 'invoice_no' => $invoiceNo, 'grand_total' => $grandFinal, 'paid' => $paid, 'change' => max(0.0, round($paid - $grandFinal, 2))];
        } catch (Throwable) {
            $db->transRollback();

            return $this->fail('Server error while saving the sale.');
        }
    }

    /**
     * POS product search by name / SKU / barcode, with the shop's available
     * stock. @return list<array<string,mixed>>
     */
    public function search(int $vendorId, int $shopId, string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $db = Database::connect();
        $bcVariants = array_column($db->table('product_barcodes')->select('variant_id')->where('status', 'active')->where('deleted_at', null)->like('barcode_value', strtoupper($q))->get()->getResultArray(), 'variant_id');

        $b = $db->table('product_variants pv')
            ->select('pv.id, pv.sku, pv.base_price, pv.base_price AS unit_price, p.title, COALESCE(tr.igst,0) AS tax_rate, COALESCE(i.available,0) AS available')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->join('tax_rates tr', "tr.tax_class_id = p.tax_class_id AND tr.status = 'active'", 'left')
            ->join('inventory i', 'i.variant_id = pv.id AND i.shop_id = ' . (int) $shopId, 'left')
            // S2: only products listed in THIS shop are sellable here.
            ->join('product_shops ps', 'ps.product_id = p.id AND ps.shop_id = ' . (int) $shopId . " AND ps.status = 'active'", 'inner')
            ->where('pv.vendor_id', $vendorId)->where('pv.status', 'active')->where('pv.deleted_at', null)->where('p.status', 'published');
        $b->groupStart()->like('pv.sku', $q)->orLike('p.title', $q);
        if ($bcVariants !== []) {
            $b->orWhereIn('pv.id', array_map('intval', $bcVariants));
        }
        $b->groupEnd();

        return $b->orderBy('p.title')->limit($limit)->get()->getResultArray();
    }

    /** @return array<string,mixed>|null full sale (header + items + payments + taxes) for the receipt */
    public function findForReceipt(int $saleId, int $vendorId): ?array
    {
        $db = Database::connect();
        $sale = $db->table('pos_sales ps')->select('ps.*, s.name AS shop_name, c.name AS customer_name')
            ->join('shops s', 's.id = ps.shop_id', 'left')->join('customers cu', 'cu.id = ps.customer_id', 'left')->join('users c', 'c.id = cu.user_id', 'left')
            ->where('ps.id', $saleId)->where('ps.vendor_id', $vendorId)->get()->getRowArray();
        if ($sale === null) {
            return null;
        }
        $sale['items'] = $db->table('pos_sale_items')->where('pos_sale_id', $saleId)->where('status', 'active')->get()->getResultArray();
        $sale['payments'] = $db->table('pos_sale_payments')->where('pos_sale_id', $saleId)->get()->getResultArray();
        $sale['taxes'] = $db->table('pos_sale_taxes')->where('pos_sale_id', $saleId)->get()->getResultArray();

        return $sale;
    }

    private function nextInvoice(object $db, int $shopId): string
    {
        $n = $db->table('pos_sales')->where('shop_id', $shopId)->countAllResults() + 1;

        return 'INV-' . date('y') . '-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    private function n(float $v, int $dp = 2): string
    {
        return number_format($v, $dp, '.', '');
    }

    /** @return array{ok:bool,message:string,sale_id:null,invoice_no:null,grand_total:float,paid:float,change:float} */
    private function fail(string $msg): array
    {
        return ['ok' => false, 'message' => $msg, 'sale_id' => null, 'invoice_no' => null, 'grand_total' => 0.0, 'paid' => 0.0, 'change' => 0.0];
    }
}
