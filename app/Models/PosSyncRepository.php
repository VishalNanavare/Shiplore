<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * PosSyncRepository — server side of the offline-first Windows POS. Terminal
 * activation, catalog/stock download, delta pull (sync_cursors), idempotent
 * invoice push (offline_invoice_no → server_invoice_no, dedup = conflict-safe
 * replay), shift open/close (daily Z-closing) and customer lookup. All data is
 * scoped to the terminal's shop/vendor (branch-level isolation).
 *
 * @see docs/architecture/34-POS-WINDOWS.md
 */
final class PosSyncRepository
{
    /** Bind a device to a terminal via its activation code. @return array<string,mixed>|null */
    public function activate(string $code, string $fingerprint): ?array
    {
        $db  = Database::connect();
        $row = $db->table('pos_terminals')
            ->select('id, shop_id, vendor_id, name, invoice_prefix, status')
            ->where('activation_code', $code)->where('status', 'active')->where('deleted_at', null)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $db->table('pos_terminals')->where('id', $row['id'])
            ->update(['device_fingerprint' => mb_substr($fingerprint, 0, 191), 'last_sync_at' => date('Y-m-d H:i:s')]);

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function terminal(int $terminalId): ?array
    {
        $row = Database::connect()->table('pos_terminals')
            ->select('id, shop_id, vendor_id, name, invoice_prefix, status')
            ->where('id', $terminalId)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** Full catalog + stock for the terminal's shop (initial download). @return array<string,mixed> */
    public function bootstrap(array $terminal): array
    {
        return [
            'server_time' => date('Y-m-d H:i:s'),
            'catalog'     => $this->catalog((int) $terminal['vendor_id'], (int) $terminal['shop_id']),
            'stock'       => $this->stock((int) $terminal['shop_id']),
        ];
    }

    /** Delta pull since an anchor; advances the terminal's cursor. @return array<string,mixed> */
    public function pull(array $terminal, ?string $since): array
    {
        $now   = date('Y-m-d H:i:s');
        $since = $since ?: '1970-01-01 00:00:00';

        $catalog = $this->catalog((int) $terminal['vendor_id'], (int) $terminal['shop_id'], $since);
        $stock   = $this->stock((int) $terminal['shop_id'], $since);

        $this->advanceCursor((int) $terminal['id'], 'catalog', $now);
        $this->advanceCursor((int) $terminal['id'], 'stock', $now);

        return ['server_time' => $now, 'catalog' => $catalog, 'stock' => $stock, 'next_anchor' => $now];
    }

    /**
     * Idempotently apply a batch of offline sales.
     * @param list<array<string,mixed>> $sales
     * @return list<array<string,mixed>> per-sale result
     */
    public function push(array $terminal, ?int $shiftId, int $cashierUserId, array $sales): array
    {
        // A client-supplied shift_id must belong to THIS terminal — otherwise drop it
        // (sales attach to no shift) rather than booking them against another shop's shift.
        if ($shiftId !== null) {
            $owns = Database::connect()->table('pos_shifts')
                ->where('id', $shiftId)->where('terminal_id', (int) $terminal['id'])->countAllResults();
            if ($owns === 0) {
                $shiftId = null;
            }
        }
        $results = [];
        foreach ($sales as $sale) {
            $offlineNo = (string) ($sale['offline_invoice_no'] ?? '');
            if ($offlineNo === '') {
                $results[] = ['offline_invoice_no' => '', 'status' => 'rejected', 'reason' => 'missing offline_invoice_no'];
                continue;
            }

            $existing = Database::connect()->table('pos_sales')
                ->select('server_invoice_no')
                ->where('terminal_id', $terminal['id'])->where('offline_invoice_no', $offlineNo)
                ->get()->getRowArray();
            if ($existing !== null) {
                // Replay of an already-synced invoice — idempotent, not an error.
                $results[] = ['offline_invoice_no' => $offlineNo, 'status' => 'duplicate', 'server_invoice_no' => $existing['server_invoice_no']];
                continue;
            }

            $results[] = $this->insertSale($terminal, $shiftId, $cashierUserId, $sale, $offlineNo);
        }

        return $results;
    }

    public function openShift(int $terminalId, int $cashierUserId, float $openingFloat): ?int
    {
        $db = Database::connect();
        $db->table('pos_shifts')->insert([
            'uuid' => bin2hex(random_bytes(18)), 'terminal_id' => $terminalId, 'cashier_user_id' => $cashierUserId,
            'opened_at' => date('Y-m-d H:i:s'), 'opening_float' => number_format($openingFloat, 2, '.', ''),
            'sync_status' => 'synced', 'status' => 'open',
        ]);

        return (int) $db->insertID();
    }

    public function currentShift(int $terminalId): ?array
    {
        $row = Database::connect()->table('pos_shifts')
            ->where('terminal_id', $terminalId)->where('status', 'open')
            ->orderBy('id', 'DESC')->get()->getRowArray();

        return $row ?: null;
    }

    /** Close a shift with a counted-cash count → Z-report (expected vs counted, variance). */
    public function closeShift(int $shiftId, float $countedCash, ?int $terminalId = null): ?array
    {
        $db    = Database::connect();
        $b     = $db->table('pos_shifts')->where('id', $shiftId)->where('status', 'open');
        if ($terminalId !== null) {
            $b->where('terminal_id', $terminalId); // scope: only this terminal's shift
        }
        $shift = $b->get()->getRowArray();
        if ($shift === null) {
            return null;
        }

        $cashSales = (float) ($db->table('pos_sale_payments psp')
            ->selectSum('psp.amount', 'cash')
            ->join('pos_sales ps', 'ps.id = psp.pos_sale_id', 'left')
            ->where('ps.shift_id', $shiftId)->where('psp.tender_type', 'cash')
            ->get()->getRowArray()['cash'] ?? 0);

        $salesTotal = (float) ($db->table('pos_sales')->selectSum('grand_total', 't')
            ->where('shift_id', $shiftId)->get()->getRowArray()['t'] ?? 0);
        $salesCount = (int) $db->table('pos_sales')->where('shift_id', $shiftId)->countAllResults();

        $expected = (float) $shift['opening_float'] + $cashSales;
        $variance = round($countedCash - $expected, 2);

        $db->table('pos_shifts')->where('id', $shiftId)->update([
            'closed_at' => date('Y-m-d H:i:s'),
            'closing_cash' => number_format($countedCash, 2, '.', ''),
            'expected_cash' => number_format($expected, 2, '.', ''),
            'variance' => number_format($variance, 2, '.', ''),
            'status' => 'closed',
        ]);

        return [
            'shift_id' => $shiftId, 'opening_float' => (float) $shift['opening_float'],
            'cash_sales' => round($cashSales, 2), 'sales_total' => round($salesTotal, 2), 'sales_count' => $salesCount,
            'expected_cash' => round($expected, 2), 'counted_cash' => round($countedCash, 2), 'variance' => $variance,
        ];
    }

    /**
     * Customer lookup for POS, scoped to customers who have transacted with the
     * given vendor — never a platform-wide PII search. @return list<array<string,mixed>>
     */
    public function customers(string $q, ?int $vendorId = null): array
    {
        $b = Database::connect()->table('customers c')
            ->select('c.id, u.name, u.phone, u.email')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->groupStart()->like('u.phone', $q)->orLike('u.name', $q)->groupEnd()
            ->where('c.deleted_at', null);
        if ($vendorId !== null) {
            $b->where("EXISTS (SELECT 1 FROM orders o JOIN sub_orders so ON so.order_id = o.id WHERE o.customer_id = c.id AND so.vendor_id = " . (int) $vendorId . ")", null, false);
        }

        return $b->limit(10)->get()->getResultArray();
    }

    // ---- internals ----
    /** @return list<array<string,mixed>> */
    private function catalog(int $vendorId, int $shopId, ?string $since = null): array
    {
        $b = Database::connect()->table('product_variants pv')
            ->select('pv.id AS variant_id, pv.sku, pv.barcode, pv.mrp, pv.base_price, p.id AS product_id, p.title, p.product_type, hs.code AS hsn_code, tc.code AS tax_code, p.status')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->join('hsn_sac_codes hs', 'hs.id = p.hsn_sac_id', 'left')
            ->join('tax_classes tc', 'tc.id = p.tax_class_id', 'left')
            // S2: a terminal only syncs the catalog for its own shop.
            ->join('product_shops ps', 'ps.product_id = p.id AND ps.shop_id = ' . (int) $shopId . " AND ps.status = 'active'", 'inner')
            ->where('pv.vendor_id', $vendorId)->where('p.status', 'published')->where('pv.deleted_at', null)
            // Hotel/hospitality products are not sellable at a retail POS.
            ->where("COALESCE(c.path,'') NOT LIKE 'hotels-hospitality%'", null, false);
        if ($since !== null) {
            $b->groupStart()->where('pv.updated_at >', $since)->orWhere('p.updated_at >', $since)->groupEnd();
        }
        $rows = $b->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['tax_rate'] = preg_match('/(\d+)/', (string) ($r['tax_code'] ?? ''), $m) ? (int) $m[1] : 0;
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function stock(int $shopId, ?string $since = null): array
    {
        $b = Database::connect()->table('inventory')
            ->select('variant_id, on_hand, reserved, available, updated_at')
            ->where('shop_id', $shopId); // inventory has no soft-delete column
        if ($since !== null) {
            $b->where('updated_at >', $since);
        }

        return $b->get()->getResultArray();
    }

    private function advanceCursor(int $terminalId, string $entity, string $anchor): void
    {
        $db  = Database::connect();
        // last_anchor is BIGINT (unix ts); last_pulled_at is the DATETIME.
        $ts  = (int) strtotime($anchor);
        $has = $db->table('sync_cursors')->where('terminal_id', $terminalId)->where('entity_type', $entity)->countAllResults();
        if ($has) {
            $db->table('sync_cursors')->where('terminal_id', $terminalId)->where('entity_type', $entity)
                ->update(['last_anchor' => $ts, 'last_pulled_at' => $anchor]);
        } else {
            $db->table('sync_cursors')->insert(['terminal_id' => $terminalId, 'entity_type' => $entity, 'last_anchor' => $ts, 'last_pulled_at' => $anchor]);
        }
    }

    /** @return array<string,mixed> */
    private function insertSale(array $terminal, ?int $shiftId, int $cashierUserId, array $sale, string $offlineNo): array
    {
        $db = Database::connect();
        $db->transBegin();
        try {
            $serverNo = $this->nextInvoiceNo((int) $terminal['id'], (string) $terminal['invoice_prefix']);
            $db->table('pos_sales')->insert([
                'uuid' => bin2hex(random_bytes(18)),
                'terminal_id' => $terminal['id'], 'shop_id' => $terminal['shop_id'], 'vendor_id' => $terminal['vendor_id'],
                'shift_id' => $shiftId, 'cashier_user_id' => $cashierUserId,
                'customer_id' => $sale['customer_id'] ?? null,
                'offline_invoice_no' => $offlineNo, 'server_invoice_no' => $serverNo,
                'subtotal' => $this->n($sale['subtotal'] ?? 0), 'discount_total' => $this->n($sale['discount_total'] ?? 0),
                'taxable_value' => $this->n($sale['taxable_value'] ?? 0),
                'cgst' => $this->n($sale['cgst'] ?? 0), 'sgst' => $this->n($sale['sgst'] ?? 0),
                'igst' => $this->n($sale['igst'] ?? 0), 'cess' => $this->n($sale['cess'] ?? 0),
                'round_off' => $this->n($sale['round_off'] ?? 0), 'grand_total' => $this->n($sale['grand_total'] ?? 0),
                'sold_at' => $sale['sold_at'] ?? date('Y-m-d H:i:s'),
                'origin' => 'pos', 'sync_status' => 'synced', 'status' => ($sale['type'] ?? 'sale') === 'return' ? 'returned' : 'completed',
            ]);
            $saleId = (int) $db->insertID();

            foreach ((array) ($sale['lines'] ?? []) as $l) {
                $db->table('pos_sale_items')->insert([
                    'uuid' => bin2hex(random_bytes(18)), 'pos_sale_id' => $saleId,
                    'variant_id' => (int) ($l['variant_id'] ?? 0),
                    'product_title_snapshot' => mb_substr((string) ($l['title'] ?? ''), 0, 191),
                    'sku_snapshot' => mb_substr((string) ($l['sku'] ?? ''), 0, 64),
                    'hsn_snapshot' => mb_substr((string) ($l['hsn'] ?? ''), 0, 12),
                    'qty' => $this->n($l['qty'] ?? 1, 3), 'unit_price' => $this->n($l['unit_price'] ?? 0),
                    'discount_amount' => $this->n($l['discount_amount'] ?? 0), 'taxable_value' => $this->n($l['taxable_value'] ?? 0),
                    'tax_rate' => $this->n($l['tax_rate'] ?? 0, 2),
                    'cgst' => $this->n($l['cgst'] ?? 0), 'sgst' => $this->n($l['sgst'] ?? 0),
                    'igst' => $this->n($l['igst'] ?? 0), 'cess' => $this->n($l['cess'] ?? 0),
                    'is_gst_inclusive' => ! empty($l['is_gst_inclusive']) ? 1 : 0,
                    'line_total' => $this->n($l['line_total'] ?? 0), 'origin' => 'pos', 'status' => 'active',
                ]);
                // decrement local shop stock (best-effort; POS already sold offline)
                if (! empty($l['variant_id'])) {
                    $db->table('inventory')->where('shop_id', $terminal['shop_id'])->where('variant_id', $l['variant_id'])
                        ->set('on_hand', 'GREATEST(on_hand - ' . (float) ($l['qty'] ?? 0) . ', 0)', false)->update();
                }
            }

            foreach ((array) ($sale['payments'] ?? []) as $p) {
                $db->table('pos_sale_payments')->insert([
                    'uuid' => bin2hex(random_bytes(18)), 'pos_sale_id' => $saleId,
                    'tender_type' => (string) ($p['tender_type'] ?? 'cash'), 'amount' => $this->n($p['amount'] ?? 0),
                    'reference' => mb_substr((string) ($p['reference'] ?? ''), 0, 120), 'origin' => 'pos', 'status' => 'captured',
                ]);
            }

            $db->transComplete();
            if (! $db->transStatus()) {
                return ['offline_invoice_no' => $offlineNo, 'status' => 'conflict', 'reason' => 'transaction failed'];
            }

            return ['offline_invoice_no' => $offlineNo, 'status' => 'synced', 'server_invoice_no' => $serverNo];
        } catch (Throwable $e) {
            $db->transRollback();

            return ['offline_invoice_no' => $offlineNo, 'status' => 'conflict', 'reason' => 'server error'];
        }
    }

    private function nextInvoiceNo(int $terminalId, string $prefix): string
    {
        $db = Database::connect();
        $fy = $this->financialYear();
        $row = $db->table('pos_sequence')->where('terminal_id', $terminalId)->where('financial_year', $fy)->get()->getRowArray();
        if ($row === null) {
            $db->table('pos_sequence')->insert(['terminal_id' => $terminalId, 'financial_year' => $fy, 'last_invoice_no' => 1]);
            $n = 1;
        } else {
            $n = (int) $row['last_invoice_no'] + 1;
            $db->table('pos_sequence')->where('id', $row['id'])->update(['last_invoice_no' => $n]);
        }

        return $prefix . '/' . $fy . '/' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    private function financialYear(): string
    {
        $y = (int) date('Y');
        $m = (int) date('n');

        return $m >= 4 ? $y . '-' . substr((string) ($y + 1), 2, 2) : ($y - 1) . '-' . substr((string) $y, 2, 2);
    }

    private function n(mixed $v, int $dp = 4): string
    {
        return number_format(round((float) $v, $dp), $dp, '.', '');
    }
}
