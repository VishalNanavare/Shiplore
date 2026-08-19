<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * Counter returns at a factory outlet, and the credit notes they produce.
 *
 * Parallel to PosReturnRepository for the same reason mfg_pos_sales is parallel to
 * pos_sales: those tables are on the api/v1 sync path used by mobile and Windows POS
 * clients already in users' hands, which cannot be updated in step. Nothing here reads
 * or writes any pos_* table.
 *
 * The invariant that carries the whole feature is CUMULATIVE: what may be returned is
 * the quantity sold on that line MINUS everything already returned against it. Checking
 * a return in isolation lets two refunds of 3 against a line of 5 both succeed, and the
 * outlet pays out 6 units of a 5-unit sale with every individual check passing.
 *
 * @see \App\Models\ManufacturerPosSaleRepository the sale side
 */
final class ManufacturerPosReturnRepository
{
    /** Find a completed sale by invoice number, scoped to the acting manufacturer. */
    public function findSale(string $invoiceNo, int $manufacturerId): ?array
    {
        $invoiceNo = trim($invoiceNo);
        if ($invoiceNo === '' || $manufacturerId <= 0) {
            return null;
        }

        $db   = Database::connect();
        $sale = $db->table('mfg_pos_sales')
            ->where('invoice_no', $invoiceNo)->where('vendor_id', $manufacturerId)
            ->where('status', 'completed')
            ->get()->getRowArray();

        if ($sale === null) {
            return null;
        }

        // Each line carries what is STILL returnable, so the screen never offers a
        // quantity the rule below would then refuse.
        $sale['items'] = $db->table('mfg_pos_sale_items i')
            ->select('i.*, COALESCE((SELECT SUM(ri.qty) FROM ' . $db->prefixTable('mfg_pos_return_items') . ' ri
                        JOIN ' . $db->prefixTable('mfg_pos_returns') . ' r ON r.id = ri.return_id AND r.status = \'completed\'
                       WHERE ri.mfg_pos_sale_item_id = i.id), 0) AS returned_qty', false)
            ->where('i.mfg_pos_sale_id', (int) $sale['id'])->where('i.status', 'active')
            ->orderBy('i.id')->get()->getResultArray();

        foreach ($sale['items'] as &$it) {
            $it['returnable_qty'] = max(0.0, (float) $it['qty'] - (float) $it['returned_qty']);
        }

        return $sale;
    }

    /**
     * Record a return: credit the money, put the stock back, issue a credit note.
     *
     * @param list<array{sale_item_id:int|string,qty:int|float|string}> $lines
     * @return array{ok:bool,return_id?:int,credit_note_no?:string,total?:float,error?:string}
     */
    public function createReturn(int $saleId, int $manufacturerId, array $lines, string $reason, string $refundMethod, ?int $actorId = null): array
    {
        $db   = Database::connect();
        $sale = $db->table('mfg_pos_sales')
            ->where('id', $saleId)->where('vendor_id', $manufacturerId)->where('status', 'completed')
            ->get()->getRowArray();

        if ($sale === null) {
            return $this->fail('Sale not found.');
        }

        $wanted = [];

        foreach ($lines as $l) {
            $iid = (int) ($l['sale_item_id'] ?? 0);
            $qty = (float) ($l['qty'] ?? 0);
            if ($iid > 0 && $qty > 0) {
                $wanted[$iid] = ($wanted[$iid] ?? 0) + $qty;
            }
        }
        if ($wanted === []) {
            return $this->fail('Nothing to return.');
        }

        // Only lines OF THIS SALE, with what is still returnable on each. Scoping the
        // lookup by sale id is what stops a line id from another bill being smuggled in.
        $items = $db->table('mfg_pos_sale_items i')
            ->select('i.*, COALESCE((SELECT SUM(ri.qty) FROM ' . $db->prefixTable('mfg_pos_return_items') . ' ri
                        JOIN ' . $db->prefixTable('mfg_pos_returns') . ' r ON r.id = ri.return_id AND r.status = \'completed\'
                       WHERE ri.mfg_pos_sale_item_id = i.id), 0) AS returned_qty', false)
            ->where('i.mfg_pos_sale_id', $saleId)->where('i.status', 'active')
            ->whereIn('i.id', array_keys($wanted))
            ->get()->getResultArray();

        if (count($items) !== count($wanted)) {
            return $this->fail('One or more items do not belong to this bill.');
        }

        $computed = [];
        $taxable  = $cgst = $sgst = $total = 0.0;

        foreach ($items as $it) {
            $iid  = (int) $it['id'];
            $qty  = $wanted[$iid];
            $left = (float) $it['qty'] - (float) $it['returned_qty'];

            if ($qty > $left + 0.0001) {
                return $this->fail('More than was sold — ' . $this->n($left, 3) . ' left to return on one line.');
            }

            // Refund at the price ACTUALLY CHARGED on that line, prorated. The same
            // variant can appear twice on one bill at different prices, so refunding a
            // catalogue price would hand back the wrong money.
            $share    = $qty / max(0.001, (float) $it['qty']);
            $lineTax  = (float) $it['taxable_value'] * $share;
            $lineCgst = (float) $it['cgst'] * $share;
            $lineSgst = (float) $it['sgst'] * $share;
            $lineTot  = (float) $it['line_total'] * $share;

            $taxable += $lineTax;
            $cgst    += $lineCgst;
            $sgst    += $lineSgst;
            $total   += $lineTot;

            $computed[] = ['it' => $it, 'qty' => $qty, 'taxable' => $lineTax,
                'cgst' => $lineCgst, 'sgst' => $lineSgst, 'total' => $lineTot];
        }

        $fy = $this->financialYear();
        $this->ensureSequenceRow($db, (int) $sale['mshop_id'], $fy);

        $db->transBegin();

        try {
            [$cnNo, $seq] = $this->nextCreditNote($db, (int) $sale['mshop_id'], $fy);

            $db->table('mfg_pos_returns')->insert([
                'uuid' => $this->uuid(), 'credit_note_no' => $cnNo, 'financial_year' => $fy, 'seq_no' => $seq,
                'mfg_pos_sale_id' => $saleId, 'mshop_id' => (int) $sale['mshop_id'], 'vendor_id' => $manufacturerId,
                'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
                'refund_method' => in_array($refundMethod, ['cash', 'card', 'upi', 'wallet', 'adjust'], true) ? $refundMethod : 'cash',
                'taxable_value' => $this->n($taxable), 'cgst' => $this->n($cgst), 'sgst' => $this->n($sgst),
                'igst' => '0.0000', 'total' => $this->n($total),
                'status' => 'completed', 'created_by' => $actorId,
            ]);
            $rid = (int) $db->insertID();
            $inv = service('manufacturerInventoryService');

            foreach ($computed as $c) {
                $it = $c['it'];
                $db->table('mfg_pos_return_items')->insert([
                    'return_id' => $rid, 'mfg_pos_sale_item_id' => (int) $it['id'],
                    'variant_id' => (int) $it['variant_id'] ?: null,
                    'qty' => $this->n($c['qty'], 3), 'unit_price' => $this->n($it['unit_price']),
                    'taxable_value' => $this->n($c['taxable']), 'tax_rate' => $this->n($it['tax_rate'], 2),
                    'cgst' => $this->n($c['cgst']), 'sgst' => $this->n($c['sgst']),
                    'line_total' => $this->n($c['total']),
                ]);

                // Goods come back into the unit that sold them, inside this transaction
                // so a failed credit cannot leave stock already restored.
                $vid = (int) $it['variant_id'];
                if ($vid > 0 && ! $inv->returnToOutlet($vid, (int) $sale['mshop_id'], $c['qty'], $rid, $actorId, $db)) {
                    throw new \RuntimeException('stock return failed');
                }
            }

            $db->transComplete();

            return $db->transStatus()
                ? ['ok' => true, 'return_id' => $rid, 'credit_note_no' => $cnNo, 'total' => round($total, 2)]
                : $this->fail('Could not record the return.');
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'mfg pos return failed: ' . $e->getMessage());

            return $this->fail('Could not record the return.');
        }
    }

    /** @return array<string,mixed>|null the credit note and its lines, tenant-scoped */
    public function findForCreditNote(int $returnId, int $manufacturerId): ?array
    {
        $db = Database::connect();
        $r  = $db->table('mfg_pos_returns r')
            ->select('r.*, m.name AS unit_name, m.gstin AS unit_gstin, s.invoice_no')
            ->join('mshops m', 'm.id = r.mshop_id', 'left')
            ->join('mfg_pos_sales s', 's.id = r.mfg_pos_sale_id', 'left')
            ->where('r.id', $returnId)->where('r.vendor_id', $manufacturerId)
            ->get()->getRowArray();

        if ($r === null) {
            return null;
        }
        $r['items'] = $db->table('mfg_pos_return_items i')
            ->select('i.*, si.product_title_snapshot, si.sku_snapshot')
            ->join('mfg_pos_sale_items si', 'si.id = i.mfg_pos_sale_item_id', 'left')
            ->where('i.return_id', $returnId)->orderBy('i.id')->get()->getResultArray();

        return $r;
    }

    /** @return list<array<string,mixed>> recent credit notes at these units */
    public function recent(int $manufacturerId, array $mshopIds, int $limit = 20): array
    {
        if ($manufacturerId <= 0 || $mshopIds === []) {
            return [];
        }

        return Database::connect()->table('mfg_pos_returns r')
            ->select('r.id, r.credit_note_no, r.total, r.created_at, r.status, s.invoice_no')
            ->join('mfg_pos_sales s', 's.id = r.mfg_pos_sale_id', 'left')
            ->where('r.vendor_id', $manufacturerId)->whereIn('r.mshop_id', $mshopIds)
            ->orderBy('r.id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    // ---- internals ---------------------------------------------------------

    /**
     * Bootstrapped OUTSIDE the transaction, exactly as the sale sequence is: INSERT
     * IGNORE is MariaDB-only and SQLite rejects it, and letting a duplicate insert fail
     * inside an open transaction flips CI4's transStatus and would silently roll the
     * whole credit note back.
     */
    private function ensureSequenceRow(object $db, int $mshopId, string $fy): void
    {
        $has = $db->table('mfg_pos_cn_sequence')->where('mshop_id', $mshopId)->where('financial_year', $fy)->countAllResults();
        if ($has > 0) {
            return;
        }

        try {
            $db->table('mfg_pos_cn_sequence')->insert(['mshop_id' => $mshopId, 'financial_year' => $fy, 'last_cn_no' => 0]);
        } catch (Throwable) {
            // Lost the bootstrap race; the winner's row is there and the UPDATE bumps it.
        }
    }

    /**
     * A counter, never COUNT(*) + 1 — which would reissue the number of any voided
     * credit note. Its OWN series: GST treats a credit note as a distinct document, and
     * sharing the invoice series would make a return indistinguishable from the sale it
     * reverses.
     *
     * @return array{0:string,1:int}
     */
    private function nextCreditNote(object $db, int $mshopId, string $fy): array
    {
        $db->query(
            'UPDATE ' . $db->prefixTable('mfg_pos_cn_sequence') . ' SET last_cn_no = last_cn_no + 1 WHERE mshop_id = ? AND financial_year = ?',
            [$mshopId, $fy],
        );
        $row = $db->table('mfg_pos_cn_sequence')->select('last_cn_no')
            ->where('mshop_id', $mshopId)->where('financial_year', $fy)->get()->getRowArray();
        $seq = (int) ($row['last_cn_no'] ?? 1);

        $code = $db->table('mshops')->select('code')->where('id', $mshopId)->get()->getRowArray();
        $unit = trim((string) ($code['code'] ?? '')) ?: ('U' . $mshopId);

        return ['CN/' . $unit . '/' . $fy . '/' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT), $seq];
    }

    private function financialYear(): string
    {
        $y = (int) date('Y');

        return (int) date('n') >= 4
            ? $y . '-' . substr((string) ($y + 1), 2, 2)
            : ($y - 1) . '-' . substr((string) $y, 2, 2);
    }

    private function n(mixed $v, int $dp = 4): string
    {
        return number_format((float) $v, $dp, '.', '');
    }

    /** @return array{ok:false,error:string} */
    private function fail(string $why): array
    {
        return ['ok' => false, 'error' => $why];
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
