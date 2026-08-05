<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * PosReturnRepository — POS returns / exchange. Looks up a completed sale, lets
 * the cashier return some or all lines (partial supported), reverses the stock
 * via InventoryService, records the refund, and marks the sale returned when
 * fully sent back. Tenant-scoped by vendor.
 */
final class PosReturnRepository
{
    /** Returns are allowed within this many days of the sale. */
    private const WINDOW_DAYS = 30;

    /** Look up a sale (by invoice no or id) with its returnable lines. @return array<string,mixed>|null */
    public function findSale(string $ref, int $vendorId): ?array
    {
        $db   = Database::connect();
        $sale = $db->table('pos_sales')->where('vendor_id', $vendorId)
            ->groupStart()->where('server_invoice_no', $ref)->orWhere('id', is_numeric($ref) ? (int) $ref : 0)->groupEnd()
            ->get()->getRowArray();
        if ($sale === null) {
            return null;
        }
        $items = $db->table('pos_sale_items')->where('pos_sale_id', (int) $sale['id'])->where('status', 'active')->get()->getResultArray();
        foreach ($items as &$it) {
            $ret = (float) ($db->table('pos_return_items')->selectSum('qty', 't')->where('pos_sale_item_id', (int) $it['id'])->get()->getRowArray()['t'] ?? 0);
            $it['returned_qty']   = $ret;
            $it['returnable_qty'] = max(0.0, (float) $it['qty'] - $ret);
        }
        $sale['items'] = $items;

        return $sale;
    }

    /**
     * Create a return for some of a sale's lines.
     * @param list<array{pos_sale_item_id:int, qty:float}> $items
     * @return array{ok:bool, message:string, refund:float, return_id:?int}
     */
    public function createReturn(int $saleId, int $vendorId, array $items, string $reason, string $refundMethod, ?int $actorId = null): array
    {
        $db   = Database::connect();
        $sale = $db->table('pos_sales')->where('id', $saleId)->where('vendor_id', $vendorId)->get()->getRowArray();
        if ($sale === null) {
            return $this->fail('Sale not found.');
        }
        if (strtotime((string) $sale['sold_at']) < strtotime('-' . self::WINDOW_DAYS . ' days')) {
            return $this->fail('Return window (' . self::WINDOW_DAYS . ' days) has passed.');
        }
        $shopId = (int) $sale['shop_id'];
        $method = in_array($refundMethod, ['cash', 'card', 'upi', 'wallet', 'exchange', 'credit_note'], true) ? $refundMethod : 'cash';

        $db->transBegin();
        try {
            $refund = $taxable = $cgst = $sgst = $igst = 0.0;
            $rows   = [];
            foreach ($items as $req) {
                $sid = (int) ($req['pos_sale_item_id'] ?? 0);
                $qty = (float) ($req['qty'] ?? 0);
                if ($sid <= 0 || $qty <= 0) {
                    continue;
                }
                $it = $db->table('pos_sale_items')->where('id', $sid)->where('pos_sale_id', $saleId)->get()->getRowArray();
                if ($it === null) {
                    continue;
                }
                $already = (float) ($db->table('pos_return_items')->selectSum('qty', 't')->where('pos_sale_item_id', $sid)->get()->getRowArray()['t'] ?? 0);
                $q = min($qty, (float) $it['qty'] - $already);
                if ($q <= 0) {
                    continue;
                }
                $frac       = (float) $it['qty'] > 0 ? $q / (float) $it['qty'] : 0.0;
                $lineRefund = round((float) $it['line_total'] * $frac, 2);
                $refund  += $lineRefund;
                $taxable += (float) $it['taxable_value'] * $frac;
                $cgst    += (float) $it['cgst'] * $frac;
                $sgst    += (float) $it['sgst'] * $frac;
                $igst    += (float) $it['igst'] * $frac;
                $rows[] = ['pos_sale_item_id' => $sid, 'variant_id' => $it['variant_id'] !== null ? (int) $it['variant_id'] : null, 'qty' => $q, 'amount' => $lineRefund];
            }
            if ($refund <= 0 || $rows === []) {
                $db->transRollback();

                return $this->fail('Nothing to return on this sale.');
            }
            // every POS return is a numbered Credit Note (its own bill no.)
            $cnNo = $this->nextCreditNoteNo($db, $shopId);
            $db->table('pos_returns')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'credit_note_no' => $cnNo, 'pos_sale_id' => $saleId, 'shop_id' => $shopId, 'vendor_id' => $vendorId,
                'reason' => mb_substr($reason, 0, 255) ?: null, 'refund_amount' => number_format($refund, 2, '.', ''),
                'taxable_value' => $this->m($taxable), 'cgst' => $this->m($cgst), 'sgst' => $this->m($sgst), 'igst' => $this->m($igst),
                'refund_method' => $method, 'status' => 'completed', 'created_by' => $actorId,
            ]);
            $returnId = (int) $db->insertID();
            foreach ($rows as $r) {
                $db->table('pos_return_items')->insert(['pos_return_id' => $returnId] + $r);
                if ($r['variant_id'] !== null && $method !== 'exchange') {
                    service('inventoryService')->returnStock((int) $r['variant_id'], $shopId, (float) $r['qty'], $returnId, $actorId);
                }
            }
            // fully returned? mark the sale
            $soldQty = (float) ($db->table('pos_sale_items')->selectSum('qty', 't')->where('pos_sale_id', $saleId)->get()->getRowArray()['t'] ?? 0);
            $retQty  = (float) ($db->table('pos_return_items pri')->selectSum('pri.qty', 't')->join('pos_returns pr', 'pr.id = pri.pos_return_id')->where('pr.pos_sale_id', $saleId)->get()->getRowArray()['t'] ?? 0);
            if ($retQty >= $soldQty) {
                $db->table('pos_sales')->where('id', $saleId)->update(['status' => 'returned']);
            }
            $db->transComplete();
            if (! $db->transStatus()) {
                return $this->fail('Could not save the return.');
            }

            return ['ok' => true, 'message' => 'Credit Note ' . $cnNo . ' — refund ₹' . number_format($refund, 2) . ($method === 'exchange' ? ' (exchange)' : '') . '.', 'refund' => $refund, 'return_id' => $returnId, 'credit_note_no' => $cnNo];
        } catch (Throwable) {
            $db->transRollback();

            return $this->fail('Server error during the return.');
        }
    }

    /**
     * Standalone (walk-in) Credit Note: a customer returns goods without the
     * original POS bill. The cashier enters the lines; stock is added back for
     * known variants. Tax is computed inclusive (GstCalculator).
     *
     * @param list<array{variant_id:?int,title:string,qty:float,unit_price:float,tax_rate:float}> $lines
     * @param array{name?:string,phone?:string} $customer
     * @return array{ok:bool,message:string,refund:float,return_id:?int,credit_note_no?:string}
     */
    public function createStandalone(int $vendorId, int $shopId, array $lines, array $customer, string $reason, string $refundMethod, ?int $actorId = null): array
    {
        if (! $this->ownsShop($shopId, $vendorId)) {
            return $this->fail('That is not your store.');
        }
        $method = in_array($refundMethod, ['cash', 'card', 'upi', 'wallet', 'credit_note'], true) ? $refundMethod : 'cash';
        $gst    = service('gstCalculator');

        $db = Database::connect();
        $db->transBegin();
        try {
            $refund = $taxable = $cgst = $sgst = $igst = 0.0;
            $rows   = [];
            foreach ($lines as $l) {
                $qty   = (float) ($l['qty'] ?? 0);
                $price = (float) ($l['unit_price'] ?? 0);
                if ($qty <= 0 || $price <= 0) {
                    continue;
                }
                $gross = $price * $qty;
                $g     = $gst->compute((string) $gross, (float) ($l['tax_rate'] ?? 0), true, false);
                $refund  += $gross;
                $taxable += (float) $g['taxable'];
                $cgst    += (float) $g['cgst'];
                $sgst    += (float) $g['sgst'];
                $igst    += (float) $g['igst'];
                $rows[] = ['variant_id' => ($l['variant_id'] ?? null) ?: null, 'title' => (string) ($l['title'] ?? 'Item'), 'qty' => $qty, 'amount' => round($gross, 2)];
            }
            if ($refund <= 0 || $rows === []) {
                $db->transRollback();

                return $this->fail('Add at least one line with a quantity and price.');
            }
            $cnNo = $this->nextCreditNoteNo($db, $shopId);
            $db->table('pos_returns')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'credit_note_no' => $cnNo, 'pos_sale_id' => null, 'shop_id' => $shopId, 'vendor_id' => $vendorId,
                'customer_name' => mb_substr((string) ($customer['name'] ?? ''), 0, 120) ?: null,
                'customer_phone' => mb_substr((string) ($customer['phone'] ?? ''), 0, 20) ?: null,
                'reason' => mb_substr($reason, 0, 255) ?: null, 'refund_amount' => $this->m($refund),
                'taxable_value' => $this->m($taxable), 'cgst' => $this->m($cgst), 'sgst' => $this->m($sgst), 'igst' => $this->m($igst),
                'refund_method' => $method, 'status' => 'completed', 'created_by' => $actorId,
            ]);
            $returnId = (int) $db->insertID();
            foreach ($rows as $r) {
                $db->table('pos_return_items')->insert(['pos_return_id' => $returnId, 'pos_sale_item_id' => null, 'variant_id' => $r['variant_id'], 'qty' => $this->m($r['qty']), 'amount' => $this->m($r['amount'])]);
                if ($r['variant_id'] !== null) {
                    service('inventoryService')->returnStock((int) $r['variant_id'], $shopId, (float) $r['qty'], $returnId, $actorId);
                }
            }
            $db->transComplete();
            if (! $db->transStatus()) {
                return $this->fail('Could not save the credit note.');
            }

            return ['ok' => true, 'message' => 'Credit Note ' . $cnNo . ' for ₹' . number_format($refund, 2) . ' created.', 'refund' => $refund, 'return_id' => $returnId, 'credit_note_no' => $cnNo];
        } catch (Throwable) {
            $db->transRollback();

            return $this->fail('Server error while creating the credit note.');
        }
    }

    /** Full credit note (header + lines + shop) for the 80mm print. @return array<string,mixed>|null */
    public function findCreditNote(int $returnId, int $vendorId): ?array
    {
        $db = Database::connect();
        $cn = $db->table('pos_returns pr')
            ->select('pr.*, ps.server_invoice_no AS against_invoice, s.name AS shop_name')
            ->join('pos_sales ps', 'ps.id = pr.pos_sale_id', 'left')
            ->join('shops s', 's.id = pr.shop_id', 'left')
            ->where('pr.id', $returnId)->where('pr.vendor_id', $vendorId)->get()->getRowArray();
        if ($cn === null) {
            return null;
        }
        $cn['items'] = $db->table('pos_return_items pri')
            ->select('pri.qty, pri.amount, COALESCE(psi.product_title_snapshot, p.title, "Item") AS title, COALESCE(psi.sku_snapshot, pv.sku, "") AS sku')
            ->join('pos_sale_items psi', 'psi.id = pri.pos_sale_item_id', 'left')
            ->join('product_variants pv', 'pv.id = pri.variant_id', 'left')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->where('pri.pos_return_id', $returnId)->get()->getResultArray();

        return $cn;
    }

    private function nextCreditNoteNo(object $db, int $shopId): string
    {
        $n = $db->table('pos_returns')->where('shop_id', $shopId)->countAllResults() + 1;

        return 'CN-' . date('y') . '-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    private function m(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    /** @return list<array<string,mixed>> recent credit notes / returns for the vendor */
    public function recent(int $vendorId, int $limit = 100): array
    {
        return Database::connect()->table('pos_returns pr')
            ->select('pr.id, pr.credit_note_no, pr.customer_name, pr.refund_amount, pr.refund_method, pr.reason, pr.created_at, ps.server_invoice_no, s.name AS shop')
            ->join('pos_sales ps', 'ps.id = pr.pos_sale_id', 'left')->join('shops s', 's.id = pr.shop_id', 'left')
            ->where('pr.vendor_id', $vendorId)->orderBy('pr.created_at', 'DESC')->limit($limit)
            ->get()->getResultArray();
    }

    private function ownsShop(int $shopId, int $vendorId): bool
    {
        return (bool) Database::connect()->table('shops')->where('id', $shopId)->where('vendor_id', $vendorId)->where('deleted_at', null)->countAllResults();
    }

    /** @return array{ok:bool,message:string,refund:float,return_id:null} */
    private function fail(string $msg): array
    {
        return ['ok' => false, 'message' => $msg, 'refund' => 0.0, 'return_id' => null];
    }
}
