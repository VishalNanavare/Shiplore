<?php

declare(strict_types=1);

namespace App\Libraries\Purchase;

use Config\Database;
use Throwable;

/**
 * PurchaseInvoiceService — posts a purchase invoice (Add Inventory) the way the
 * WPF Shiplore POS does (Appendix A), adapted to the multi-vendor MySQL cloud.
 *
 * Per line: net = purchase_price − disc1 − disc2; GST amounts from the per-line
 * CGST/SGST/IGST/CESS %; line_total = net·qty + taxes. Stock rises by qty+free via
 * InventoryService::receive() (batch + inventory + ledger 'purchase'). The header
 * aggregates subtotal (without GST), discount, GST split, qty and grand (+APMC).
 * All money rounded to 3 dp (matches WPF). Server-authoritative — the UI preview
 * never decides the stored totals.
 */
final class PurchaseInvoiceService
{
    /**
     * @param array<string,mixed>           $header  supplier_id, challan_no, supplier_invoice_id, invoice_date, apmc
     * @param list<array<string,mixed>>     $lines   each: variant_id, product_id, mode, purchase_price, mrp, sale_price,
     *                                               qty, free_qty, disc_pct, disc_amt, disc_pct2, disc_amt2,
     *                                               cgst_pct, sgst_pct, igst_pct, cess_pct, hsn_code, batch_no, mfg_date, exp_date
     * @return array{ok:bool, id?:int, our_invoice_no?:string, reason?:string}
     */
    public function post(int $vendorId, ?int $shopId, array $header, array $lines, ?int $actorId = null): array
    {
        $clean = array_values(array_filter($lines, static fn ($l) => (int) ($l['variant_id'] ?? 0) > 0 && (float) ($l['qty'] ?? 0) > 0));
        if ($clean === []) {
            return ['ok' => false, 'reason' => 'Add at least one product line with a quantity.'];
        }

        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $ourNo = 'INV-' . date('YmdHis') . random_int(10, 99);

        $db->transBegin();
        try {
            $db->table('purchase_invoices')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'vendor_id' => $vendorId, 'shop_id' => $shopId,
                'supplier_id' => ($header['supplier_id'] ?? 0) ?: null,
                'our_invoice_no' => $ourNo,
                'challan_no' => ($header['challan_no'] ?? '') ?: null,
                'supplier_invoice_id' => ($header['supplier_invoice_id'] ?? '') ?: null,
                'invoice_date' => ($header['invoice_date'] ?? '') ?: null,
                'apmc' => (float) ($header['apmc'] ?? 0),
                'status' => 'posted', 'created_by' => $actorId, 'created_at' => $now,
            ]);
            $invoiceId = (int) $db->insertID();

            $agg = ['subtotal' => 0.0, 'discount' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'cess' => 0.0, 'qty' => 0.0, 'grand' => 0.0];

            foreach ($clean as $l) {
                $c   = $this->lineMath($l);
                $vid = (int) $l['variant_id'];

                // Update the variant's commercial prices when the purchase carries them.
                $patch = [];
                if ((float) ($l['mrp'] ?? 0) > 0) {
                    $patch['mrp'] = (float) $l['mrp'];
                }
                if ((float) ($l['sale_price'] ?? 0) > 0) {
                    $patch['base_price'] = (float) $l['sale_price'];
                }
                if ((float) ($l['purchase_price'] ?? 0) > 0) {
                    $patch['purchase_price'] = (float) $l['purchase_price'];
                }
                if ($patch !== []) {
                    $db->table('product_variants')->where('id', $vid)->where('vendor_id', $vendorId)->update($patch);
                }

                // Stock in: qty + free, costed at the post-discount unit net.
                $ok = service('inventoryService')->receive($vid, (int) $shopId, $c['qtyTotal'], $c['net'], [
                    'batch_no' => $l['batch_no'] ?? null,
                    'mfg_date' => ($l['mfg_date'] ?? '') ?: null,
                    'expiry_date' => ($l['exp_date'] ?? '') ?: null,
                    'reason' => 'purchase',
                ], $actorId);
                if (! $ok) {
                    throw new \RuntimeException('stock receive failed for variant ' . $vid);
                }

                $db->table('purchase_invoice_items')->insert([
                    'purchase_invoice_id' => $invoiceId,
                    'mode' => ($l['mode'] ?? 'insert') === 'update' ? 'update' : 'insert',
                    'product_id' => ($l['product_id'] ?? 0) ?: null,
                    'variant_id' => $vid,
                    'product_name' => mb_substr((string) ($l['product_name'] ?? ''), 0, 191) ?: null,
                    'barcode' => mb_substr((string) ($l['barcode'] ?? ''), 0, 64) ?: null,
                    'hsn_code' => mb_substr((string) ($l['hsn_code'] ?? ''), 0, 12) ?: null,
                    'purchase_price' => (float) ($l['purchase_price'] ?? 0),
                    'mrp' => (float) ($l['mrp'] ?? 0), 'sale_price' => (float) ($l['sale_price'] ?? 0),
                    'qty' => (float) ($l['qty'] ?? 0), 'free_qty' => (float) ($l['free_qty'] ?? 0),
                    'disc_pct' => (float) ($l['disc_pct'] ?? 0), 'disc_amt' => $c['disc1'],
                    'disc_pct2' => (float) ($l['disc_pct2'] ?? 0), 'disc_amt2' => $c['disc2'],
                    'cgst_pct' => (float) ($l['cgst_pct'] ?? 0), 'sgst_pct' => (float) ($l['sgst_pct'] ?? 0),
                    'igst_pct' => (float) ($l['igst_pct'] ?? 0), 'cess_pct' => (float) ($l['cess_pct'] ?? 0),
                    'batch_no' => ($l['batch_no'] ?? '') ?: null,
                    'mfg_date' => ($l['mfg_date'] ?? '') ?: null, 'exp_date' => ($l['exp_date'] ?? '') ?: null,
                    'line_total' => $c['lineTotal'], 'created_at' => $now,
                ]);

                $agg['subtotal'] += $c['without'];
                $agg['discount'] += $c['discTotal'];
                $agg['cgst'] += $c['cgst'];
                $agg['sgst'] += $c['sgst'];
                $agg['igst'] += $c['igst'];
                $agg['cess'] += $c['cess'];
                $agg['qty'] += (float) ($l['qty'] ?? 0);
                $agg['grand'] += $c['lineTotal'];
            }

            $apmc = (float) ($header['apmc'] ?? 0);
            $db->table('purchase_invoices')->where('id', $invoiceId)->update([
                'subtotal' => round($agg['subtotal'], 3), 'discount_total' => round($agg['discount'], 3),
                'cgst_total' => round($agg['cgst'], 3), 'sgst_total' => round($agg['sgst'], 3),
                'igst_total' => round($agg['igst'], 3), 'cess_total' => round($agg['cess'], 3),
                'total_qty' => round($agg['qty'], 3), 'grand_total' => round($agg['grand'] + $apmc, 3),
            ]);

            $db->transComplete();
            if (! $db->transStatus()) {
                return ['ok' => false, 'reason' => 'Could not save the purchase. Please try again.'];
            }

            return ['ok' => true, 'id' => $invoiceId, 'our_invoice_no' => $ourNo];
        } catch (Throwable $e) {
            $db->transRollback();

            return ['ok' => false, 'reason' => 'Could not save the purchase. Please try again.'];
        }
    }

    /** Appendix A.1 per-line arithmetic (round 3 dp). @param array<string,mixed> $l @return array<string,float> */
    private function lineMath(array $l): array
    {
        $pp   = (float) ($l['purchase_price'] ?? 0);
        $qty  = (float) ($l['qty'] ?? 0);
        $free = (float) ($l['free_qty'] ?? 0);

        $disc1 = (float) ($l['disc_amt'] ?? 0) > 0 ? (float) $l['disc_amt'] : $pp * (float) ($l['disc_pct'] ?? 0) / 100;
        $disc2 = (float) ($l['disc_amt2'] ?? 0) > 0 ? (float) $l['disc_amt2'] : $pp * (float) ($l['disc_pct2'] ?? 0) / 100;
        $net   = max(0.0, $pp - $disc1 - $disc2);

        $cgst = round($net * $qty * (float) ($l['cgst_pct'] ?? 0) / 100, 3);
        $sgst = round($net * $qty * (float) ($l['sgst_pct'] ?? 0) / 100, 3);
        $igst = round($net * $qty * (float) ($l['igst_pct'] ?? 0) / 100, 3);
        $cess = round($net * $qty * (float) ($l['cess_pct'] ?? 0) / 100, 3);
        $without = round($net * $qty, 3);

        return [
            'disc1' => round($disc1, 4), 'disc2' => round($disc2, 4), 'net' => round($net, 4),
            'qtyTotal' => $qty + $free,
            'cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst, 'cess' => $cess,
            'without' => $without, 'discTotal' => round(($disc1 + $disc2) * $qty, 3),
            'lineTotal' => round($without + $cgst + $sgst + $igst + $cess, 3),
        ];
    }
}
