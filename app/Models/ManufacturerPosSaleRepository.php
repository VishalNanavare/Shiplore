<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * ManufacturerPosSaleRepository — factory-outlet counter sales.
 *
 * Forked from PosSaleRepository rather than parameterised, because the two are not the
 * same query with a different id. The vendor version inner-joins `product_shops`,
 * left-joins `inventory`, joins `shops` and derives its invoice sequence from
 * `pos_sales.shop_id`; every one of those becomes `product_mshops`, `mfg_inventory`,
 * `mshops` and `mfg_pos_sequence` here.
 *
 * IT ALSO TOUCHES NO pos_* TABLE. `api/v1/pos/*` is the sync path for already-shipped
 * mobile and Windows POS clients that cannot be updated in lockstep, so nothing in this
 * class may read or write anything they depend on.
 *
 * @see \App\Models\PosSaleRepository — the vendor counterpart
 */
final class ManufacturerPosSaleRepository
{
    /**
     * Resolve posted cart lines against this manufacturer's catalogue.
     *
     * Prices come from the DATABASE, never from the cart: the client sends variant ids
     * and quantities and nothing else that money is computed from. The INNER JOIN on
     * product_mshops is the tenant AND unit boundary — a variant not listed at this
     * unit resolves to nothing, so a guessed id cannot be sold.
     *
     * @param list<array<string,mixed>> $cart
     * @return list<array<string,mixed>>
     */
    public function resolveLines(int $manufacturerId, array $cart, int $mshopId): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $l): int => (int) ($l['variant_id'] ?? 0),
            $cart,
        ))));
        if ($ids === [] || $manufacturerId <= 0 || $mshopId <= 0) {
            return [];
        }

        $db   = Database::connect();
        $rows = $db->table('product_variants pv')
            ->select('pv.id AS variant_id, pv.sku, pv.base_price, pv.making_price,
                      p.title, hs.code AS hsn, COALESCE(tr.igst, 0) AS tax_rate', false)
            ->join('products p', 'p.id = pv.product_id', 'inner')
            ->join('hsn_sac_codes hs', 'hs.id = p.hsn_id', 'left')
            ->join('tax_rates tr', "tr.tax_class_id = p.tax_class_id AND tr.status = 'active'", 'left')
            // Bound, not interpolated — PosSaleRepository builds this join by string
            // concatenation, which is one careless edit away from an injection.
            ->join('product_mshops pms', 'pms.product_id = p.id AND pms.mshop_id = ' . (int) $mshopId . " AND pms.status = 'active'", 'inner')
            ->where('p.vendor_id', $manufacturerId)
            ->where('p.deleted_at', null)
            ->whereIn('pv.id', $ids)
            ->get()->getResultArray();

        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['variant_id']] = $r;
        }

        $out = [];
        foreach ($cart as $l) {
            $vid = (int) ($l['variant_id'] ?? 0);
            $qty = (float) ($l['qty'] ?? 0);
            if ($qty <= 0 || ! isset($byId[$vid])) {
                continue;
            }
            $r     = $byId[$vid];
            $out[] = [
                'variant_id'    => $vid,
                'sku'           => $r['sku'],
                'title'         => $r['title'],
                'hsn'           => $r['hsn'],
                'qty'           => $qty,
                'unit_price'    => (float) $r['base_price'],
                'making_price'  => $r['making_price'] === null ? null : (float) $r['making_price'],
                'tax_rate'      => (float) $r['tax_rate'],
                'line_discount' => max(0.0, (float) ($l['line_discount'] ?? 0)),
            ];
        }

        return $out;
    }

    /**
     * Counter search: what this unit can sell, with its on-hand balance.
     *
     * @return list<array<string,mixed>>
     */
    public function search(int $manufacturerId, int $mshopId, string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '' || $manufacturerId <= 0 || $mshopId <= 0) {
            return [];
        }

        $db = Database::connect();

        return $db->table('product_variants pv')
            ->select('pv.id AS variant_id, pv.sku, pv.base_price, p.title,
                      COALESCE(tr.igst, 0) AS tax_rate,
                      COALESCE(mi.on_hand, 0) AS on_hand', false)
            ->join('products p', 'p.id = pv.product_id', 'inner')
            ->join('tax_rates tr', "tr.tax_class_id = p.tax_class_id AND tr.status = 'active'", 'left')
            ->join('mfg_inventory mi', 'mi.variant_id = pv.id AND mi.mshop_id = ' . (int) $mshopId, 'left')
            ->join('product_mshops pms', 'pms.product_id = p.id AND pms.mshop_id = ' . (int) $mshopId . " AND pms.status = 'active'", 'inner')
            ->where('p.vendor_id', $manufacturerId)
            ->where('p.deleted_at', null)
            ->where('pv.deleted_at', null)
            ->groupStart()->like('pv.sku', $q)->orLike('p.title', $q)->groupEnd()
            ->orderBy('p.title', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    /**
     * Record a sale: numbering, header, lines, payments and the stock decrement, all
     * in one transaction.
     *
     * @param array<string,mixed>       $ctx      mshop_id, vendor_id, cashier_user_id
     * @param list<array<string,mixed>> $lines    from resolveLines()
     * @param list<array<string,mixed>> $payments [{tender_type, amount, reference}]
     * @param array<string,mixed>       $opts     client_uuid, bill_discount, notes, customer_*
     * @return array{ok:bool,message?:string,sale_id?:int,invoice_no?:string,grand_total?:float,paid?:float,change?:float,error?:string}
     */
    public function createSale(array $ctx, array $lines, array $payments, array $opts = []): array
    {
        $mshopId  = (int) ($ctx['mshop_id'] ?? 0);
        $vendorId = (int) ($ctx['vendor_id'] ?? 0);
        $cashier  = (int) ($ctx['cashier_user_id'] ?? 0);
        $client   = trim((string) ($opts['client_uuid'] ?? ''));

        if ($mshopId <= 0 || $vendorId <= 0) {
            return $this->fail('Pick a unit to sell from.');
        }

        $db = Database::connect();

        // Idempotency, SCOPED BY TENANT. The vendor lookup queries offline_invoice_no
        // with no vendor predicate, so a colliding uuid returns another tenant's sale.
        if ($client !== '') {
            $dup = $db->table('mfg_pos_sales')->select('id, invoice_no, grand_total')
                ->where('vendor_id', $vendorId)->where('client_uuid', $client)
                ->get()->getRowArray();
            if ($dup !== null) {
                return ['ok' => true, 'message' => 'Already recorded.', 'sale_id' => (int) $dup['id'],
                    'invoice_no' => (string) $dup['invoice_no'], 'grand_total' => (float) $dup['grand_total'],
                    'paid' => 0.0, 'change' => 0.0];
            }
        }

        $lines = array_values(array_filter($lines, static fn (array $l): bool => (float) ($l['qty'] ?? 0) > 0));
        if ($lines === []) {
            return $this->fail('Cart is empty.');
        }

        // Availability BEFORE anything is written. bump() floors on_hand at 0 silently,
        // so without this the outlet prints a receipt for goods it does not have.
        $inv = service('manufacturerInventoryService');
        foreach ($lines as $l) {
            $vid = (int) $l['variant_id'];
            if ($vid <= 0) {
                continue;
            }
            $have = (float) ($inv->levels($vid, $mshopId)['on_hand'] ?? 0);
            if ($have + 0.0001 < (float) $l['qty'] && empty($opts['allow_negative'])) {
                return $this->fail('Not enough stock for ' . ($l['title'] ?? 'an item') . ' — ' . $this->n($have, 3) . ' on hand.');
            }
        }

        $gst      = service('gstCalculator');
        $subtotal = $discountTotal = $taxable = $cgst = $sgst = $igst = $grand = 0.0;
        $computed = [];

        foreach ($lines as $l) {
            $qty   = (float) $l['qty'];
            $price = max(0.0, (float) $l['unit_price']);
            $disc  = max(0.0, (float) ($l['line_discount'] ?? 0));
            $gross = max(0.0, $price * $qty - $disc);
            // Inclusive, intra-state — a counter sale is always at the unit's own
            // place of supply, so CGST+SGST rather than IGST.
            $g = $gst->compute((string) $gross, (float) ($l['tax_rate'] ?? 0), true, false);

            $subtotal      += $price * $qty;
            $discountTotal += $disc;
            $taxable       += (float) $g['taxable'];
            $cgst          += (float) $g['cgst'];
            $sgst          += (float) $g['sgst'];
            $igst          += (float) $g['igst'];
            $grand         += $gross;

            $computed[] = ['l' => $l, 'qty' => $qty, 'price' => $price, 'disc' => $disc, 'gross' => $gross, 'g' => $g];
        }

        $billDiscount   = max(0.0, (float) ($opts['bill_discount'] ?? 0));
        $grand          = max(0.0, $grand - $billDiscount);
        $discountTotal += $billDiscount;
        $rounded        = round($grand);
        $roundOff       = round($rounded - $grand, 2);

        $paid = 0.0;
        foreach ($payments as $p) {
            $paid += (float) ($p['amount'] ?? 0);
        }
        if ($paid + 0.01 < $rounded) {
            return $this->fail('Payment ₹' . number_format($paid, 2) . ' is less than the total ₹' . number_format($rounded, 2) . '.');
        }

        // Bootstrapped OUTSIDE the transaction on purpose — see ensureSequenceRow().
        $fy = $this->financialYear();
        $this->ensureSequenceRow($db, $mshopId, $fy);

        $db->transBegin();

        try {
            [$invoiceNo, $seqNo] = $this->nextInvoice($db, $mshopId, $fy);

            $db->table('mfg_pos_sales')->insert([
                'uuid' => $this->uuid(), 'terminal_id' => null, 'shift_id' => null,
                'mshop_id' => $mshopId, 'vendor_id' => $vendorId, 'cashier_user_id' => $cashier ?: null,
                'customer_name' => ($opts['customer_name'] ?? '') ?: null,
                'customer_phone' => ($opts['customer_phone'] ?? '') ?: null,
                'client_uuid' => $client !== '' ? $client : null,
                'invoice_no' => $invoiceNo, 'financial_year' => $fy, 'seq_no' => $seqNo,
                'subtotal' => $this->n($subtotal), 'discount_total' => $this->n($discountTotal),
                'taxable_value' => $this->n($taxable), 'cgst' => $this->n($cgst), 'sgst' => $this->n($sgst),
                'igst' => $this->n($igst), 'cess' => '0.0000',
                'round_off' => $this->n($roundOff), 'grand_total' => $this->n($rounded),
                'payment_method' => (string) ($payments[0]['tender_type'] ?? 'cash'),
                'notes' => ($opts['notes'] ?? '') ?: null,
                'sold_at' => date('Y-m-d H:i:s'), 'status' => 'completed',
            ]);
            $saleId = (int) $db->insertID();

            foreach ($computed as $c) {
                $l = $c['l'];
                $db->table('mfg_pos_sale_items')->insert([
                    'uuid' => $this->uuid(), 'mfg_pos_sale_id' => $saleId,
                    'variant_id' => (int) $l['variant_id'] ?: null,
                    'product_title_snapshot' => mb_substr((string) ($l['title'] ?? ''), 0, 191),
                    'sku_snapshot' => $l['sku'] ?? null, 'hsn_snapshot' => $l['hsn'] ?? null,
                    'qty' => $this->n($c['qty'], 3), 'unit_price' => $this->n($c['price']),
                    'making_price_snapshot' => $l['making_price'] === null ? null : $this->n($l['making_price']),
                    'discount_amount' => $this->n($c['disc']),
                    'taxable_value' => $this->n($c['g']['taxable']), 'tax_rate' => $this->n($l['tax_rate'] ?? 0, 2),
                    'cgst' => $this->n($c['g']['cgst']), 'sgst' => $this->n($c['g']['sgst']),
                    'igst' => $this->n($c['g']['igst']), 'cess' => '0.0000',
                    'is_gst_inclusive' => 1, 'line_total' => $this->n($c['gross']),
                    'is_temporary' => 0, 'status' => 'active',
                ]);
            }

            foreach ($payments as $p) {
                $amt = (float) ($p['amount'] ?? 0);
                if ($amt <= 0) {
                    continue;
                }
                $db->table('mfg_pos_sale_payments')->insert([
                    'uuid' => $this->uuid(), 'mfg_pos_sale_id' => $saleId,
                    'tender_type' => in_array($p['tender_type'] ?? '', ['cash', 'card', 'upi', 'wallet'], true) ? $p['tender_type'] : 'cash',
                    'amount' => $this->n($amt), 'reference' => ($p['reference'] ?? '') ?: null,
                    'status' => 'captured',
                ]);
            }

            // Stock, inside the SAME transaction and with the result checked.
            // PosSaleRepository discards this return, which is how the vendor panel can
            // produce a committed sale with no inventory movement and no error.
            foreach ($computed as $c) {
                $vid = (int) $c['l']['variant_id'];
                if ($vid <= 0) {
                    continue;
                }
                if (! $inv->sellFromOutlet($vid, $mshopId, $c['qty'], $saleId, $cashier ?: null, $db)) {
                    throw new \RuntimeException('stock decrement failed');
                }
            }

            $db->transComplete();

            if (! $db->transStatus()) {
                return $this->fail('Could not record the sale.');
            }

            return ['ok' => true, 'sale_id' => $saleId, 'invoice_no' => $invoiceNo,
                'grand_total' => (float) $rounded, 'paid' => $paid, 'change' => max(0.0, $paid - $rounded)];
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'manufacturer POS sale failed: ' . $e->getMessage());

            return $this->fail('Could not record the sale.');
        }
    }

    /** @return array<string,mixed>|null the sale, its lines and payments, tenant-scoped */
    public function findForReceipt(int $saleId, int $manufacturerId): ?array
    {
        $db   = Database::connect();
        $sale = $db->table('mfg_pos_sales s')
            ->select('s.*, m.name AS unit_name, m.gstin AS unit_gstin, m.address_json, c.name AS cashier_name')
            ->join('mshops m', 'm.id = s.mshop_id', 'left')
            ->join('users c', 'c.id = s.cashier_user_id', 'left')
            ->where('s.id', $saleId)->where('s.vendor_id', $manufacturerId)
            ->get()->getRowArray();

        if ($sale === null) {
            return null;
        }

        $sale['items'] = $db->table('mfg_pos_sale_items')
            ->where('mfg_pos_sale_id', $saleId)->where('status', 'active')
            ->orderBy('id')->get()->getResultArray();
        $sale['payments'] = $db->table('mfg_pos_sale_payments')
            ->where('mfg_pos_sale_id', $saleId)->where('status', 'captured')
            ->orderBy('id')->get()->getResultArray();

        // GST buckets computed here rather than stored: every line carries its own
        // hsn/rate/tax columns, so a frozen rollup table would only add a second thing
        // to keep in step. Add one if GSTR filing ever needs it.
        $buckets = [];
        foreach ($sale['items'] as $it) {
            $key = (string) ($it['tax_rate'] ?? '0');
            $buckets[$key] ??= ['rate' => (float) $key, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0];
            $buckets[$key]['taxable'] += (float) $it['taxable_value'];
            $buckets[$key]['cgst']    += (float) $it['cgst'];
            $buckets[$key]['sgst']    += (float) $it['sgst'];
        }
        $sale['tax_buckets'] = array_values($buckets);

        return $sale;
    }

    /** @return list<array<string,mixed>> recent sales at these units */
    public function recent(int $manufacturerId, array $mshopIds, int $limit = 20): array
    {
        if ($mshopIds === []) {
            return [];
        }

        return Database::connect()->table('mfg_pos_sales s')
            ->select('s.id, s.invoice_no, s.grand_total, s.sold_at, s.status, m.name AS unit_name')
            ->join('mshops m', 'm.id = s.mshop_id', 'left')
            ->where('s.vendor_id', $manufacturerId)
            ->whereIn('s.mshop_id', $mshopIds)
            ->orderBy('s.id', 'DESC')->limit($limit)
            ->get()->getResultArray();
    }

    // ---- internals ---------------------------------------------------------

    /**
     * Make sure this unit has a counter row for the year, BEFORE the sale transaction
     * opens.
     *
     * Two reasons it lives out here rather than inside nextInvoice():
     *
     *   - the obvious one-statement form is `INSERT IGNORE`, which MariaDB accepts and
     *     SQLite rejects outright, so the tests could never run production's path;
     *   - the portable alternative is to let a duplicate INSERT fail and swallow it —
     *     but CI4 flips transStatus to false on ANY failed query while a transaction is
     *     open (BaseConnection::handleTransStatus), so a swallowed collision would
     *     silently roll back the whole sale. Out here that failure is inert.
     *
     * The unique key on (mshop_id, financial_year) is what makes the race safe: a
     * concurrent bootstrap loses the insert and simply bumps the winner's row.
     */
    private function ensureSequenceRow(object $db, int $mshopId, string $fy): void
    {
        $has = $db->table('mfg_pos_sequence')
            ->where('mshop_id', $mshopId)->where('financial_year', $fy)
            ->countAllResults();
        if ($has > 0) {
            return;
        }

        try {
            $db->table('mfg_pos_sequence')->insert([
                'mshop_id' => $mshopId, 'financial_year' => $fy, 'last_invoice_no' => 0,
            ]);
        } catch (Throwable) {
            // Lost the bootstrap race; the winner's row is there and the UPDATE bumps it.
        }
    }

    /**
     * Next invoice number for a unit in the current financial year.
     *
     * The UPDATE locks the counter row, so two concurrent sales serialise here rather
     * than racing. Called inside the sale transaction, on a row ensureSequenceRow()
     * has already created. A counter — never COUNT(*) + 1, which would reissue the
     * number of any voided sale.
     *
     * @return array{0:string,1:int} [invoice_no, seq_no]
     */
    private function nextInvoice(object $db, int $mshopId, string $fy): array
    {
        $db->query(
            'UPDATE ' . $db->prefixTable('mfg_pos_sequence') . ' SET last_invoice_no = last_invoice_no + 1 WHERE mshop_id = ? AND financial_year = ?',
            [$mshopId, $fy],
        );

        $row = $db->table('mfg_pos_sequence')->select('last_invoice_no')
            ->where('mshop_id', $mshopId)->where('financial_year', $fy)->get()->getRowArray();
        $seq = (int) ($row['last_invoice_no'] ?? 1);

        $code = $db->table('mshops')->select('code')->where('id', $mshopId)->get()->getRowArray();
        $unit = trim((string) ($code['code'] ?? '')) ?: ('U' . $mshopId);

        return [$unit . '/' . $fy . '/' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT), $seq];
    }

    /** Indian financial year, rolling on 1 April. Same rule as PosSyncRepository. */
    private function financialYear(): string
    {
        $y = (int) date('Y');
        $m = (int) date('n');

        return $m >= 4
            ? $y . '-' . substr((string) ($y + 1), 2, 2)
            : ($y - 1) . '-' . substr((string) $y, 2, 2);
    }

    private function n(mixed $v, int $dp = 4): string
    {
        return number_format((float) $v, $dp, '.', '');
    }

    /** @return array{ok:bool,error:string} */
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
