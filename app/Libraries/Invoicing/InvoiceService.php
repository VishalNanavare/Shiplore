<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Models\InvoiceSeriesRepository;
use Config\Database;
use DateTimeImmutable;
use Throwable;

/**
 * InvoiceService — Phase X2b (P0): the (previously missing) writer that turns
 * a dispatched/delivered sub-order into a GST document: gapless number from
 * invoice_series, header from the sub-order's stored tax splits, lines from
 * the order_items snapshots. Idempotent per sub-order (UNIQUE on
 * invoices.sub_order_id). tax_invoice when the supplier has a GSTIN,
 * bill_of_supply otherwise. The PDF lands in media via X2c and links through
 * invoices.media_id.
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §11
 */
final class InvoiceService
{
    /** Sub-order statuses for which a GST invoice is due. */
    public const INVOICEABLE = ['out_for_delivery', 'delivered', 'completed'];

    /**
     * Ensure an invoice exists for an invoiceable sub-order, returning its id.
     * Generation is lazy + idempotent, so order-detail pages can surface the PDF
     * button regardless of how the order reached its status (web, seed, API).
     */
    public function ensureForSubOrder(int $subOrderId, ?int $actorId, string $status): ?int
    {
        if (! in_array($status, self::INVOICEABLE, true)) {
            return null;
        }
        $res = $this->generateForSubOrder($subOrderId, $actorId);
        if (! ($res['ok'] ?? false)) {
            return null;
        }
        $id = (int) ($res['invoice_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * Generate (or replay) the invoice for a sub-order.
     * @return array{ok:bool,reason?:string,invoice_id?:int,invoice_no?:string,replay?:bool}
     */
    public function generateForSubOrder(int $subOrderId, ?int $actorId = null, ?DateTimeImmutable $at = null): array
    {
        $at = $at ?? new DateTimeImmutable('now');
        $db = Database::connect();

        $prior = $db->table('invoices')->select('id, invoice_no')->where('sub_order_id', $subOrderId)->get()->getRowArray();
        if ($prior !== null) {
            return ['ok' => true, 'replay' => true, 'invoice_id' => (int) $prior['id'], 'invoice_no' => (string) $prior['invoice_no']];
        }

        $sub = $db->table('sub_orders so')
            ->select('so.*, s.gstin AS shop_gstin, v.gstin AS vendor_gstin')
            ->join('shops s', 's.id = so.shop_id', 'left')
            ->join('vendors v', 'v.id = so.vendor_id', 'left')
            ->where('so.id', $subOrderId)->where('so.deleted_at', null)
            ->get()->getRowArray();
        if ($sub === null) {
            return ['ok' => false, 'reason' => 'sub-order not found'];
        }

        $items = $db->table('order_items')->where('sub_order_id', $subOrderId)->where('status', 'active')->get()->getResultArray();
        if ($items === []) {
            return ['ok' => false, 'reason' => 'no active items'];
        }

        $gstin   = $sub['shop_gstin'] ?: $sub['vendor_gstin'] ?: null;
        $docType = self::docTypeFor($gstin);

        // Build lines + derive the header totals FROM them, so the document is
        // always self-consistent (lines sum to the totals) even when a sub-order's
        // stored aggregate is stale/inconsistent.
        $lineRows   = self::linesFromItems($items);
        $sumTaxable = $sumCgst = $sumSgst = $sumIgst = $sumCess = $sumGrand = 0.0;
        foreach ($lineRows as $l) {
            $sumTaxable += (float) $l['taxable_value'];
            $sumCgst    += (float) $l['cgst'];
            $sumSgst    += (float) $l['sgst'];
            $sumIgst    += (float) $l['igst'];
            $sumCess    += (float) $l['cess'];
            $sumGrand   += (float) $l['line_total'];
        }
        $fmt = static fn (float $v): string => number_format($v, 4, '.', '');

        $db->transBegin();
        try {
            $no = (new InvoiceSeriesRepository())->next((int) $sub['shop_id'], $docType, $at); // inside tx → gapless

            $db->table('invoices')->insert([
                'uuid'            => $this->uuid(),
                'sub_order_id'    => $subOrderId,
                'invoice_no'      => $no,
                'invoice_date'    => $at->format('Y-m-d'),
                'place_of_supply' => (string) $sub['place_of_supply'],
                'supplier_gstin'  => $gstin,
                'taxable_total'   => $fmt($sumTaxable),
                'cgst_total'      => $fmt($sumCgst),
                'sgst_total'      => $fmt($sumSgst),
                'igst_total'      => $fmt($sumIgst),
                'cess_total'      => $fmt($sumCess),
                'round_off'       => '0.0000',
                'grand_total'     => $fmt($sumGrand),
                'doc_type'        => $docType,
                'status'          => 'generated',
                'created_by'      => $actorId,
            ]);
            $invoiceId = (int) $db->insertID();

            foreach ($lineRows as $line) {
                $db->table('invoice_lines')->insert($line + ['invoice_id' => $invoiceId]);
            }

            $db->transComplete();
            if (! $db->transStatus()) {
                return ['ok' => false, 'reason' => 'transaction failed'];
            }
        } catch (Throwable $e) {
            $db->transRollback();

            return ['ok' => false, 'reason' => 'processing error'];
        }

        try {
            service('auditWriter')->log([
                'action' => 'invoice.generated', 'entity_type' => 'invoice', 'entity_id' => $invoiceId,
                'actor_user_id' => $actorId, 'principal_type' => 'system',
                'scope_type' => 'vendor', 'scope_id' => (int) $sub['vendor_id'],
                'metadata' => ['invoice_no' => $no, 'sub_order_id' => $subOrderId, 'doc_type' => $docType],
            ]);
        } catch (Throwable) {
        }

        return ['ok' => true, 'invoice_id' => $invoiceId, 'invoice_no' => $no];
    }

    /** Pure: GST doc type by supplier registration. */
    public static function docTypeFor(?string $gstin): string
    {
        return $gstin !== null && trim($gstin) !== '' ? 'tax_invoice' : 'bill_of_supply';
    }

    /**
     * Pure: order_items snapshots → invoice_lines rows.
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function linesFromItems(array $items): array
    {
        return array_map(static function (array $i): array {
            // Some snapshots (notably POS-origin items) store line_total = 0;
            // derive it from taxable + taxes so an invoice line is never zero.
            $lineTotal = $i['line_total'] ?? null;
            if ($lineTotal === null || (float) $lineTotal <= 0.0) {
                $lineTotal = number_format(
                    (float) $i['taxable_value'] + (float) $i['cgst'] + (float) $i['sgst'] + (float) $i['igst'] + (float) ($i['cess'] ?? 0),
                    4,
                    '.',
                    '',
                );
            }

            return [
                'order_item_id' => (int) $i['id'],
                'description'   => mb_substr((string) $i['product_title_snapshot'], 0, 191),
                'hsn'           => $i['hsn_snapshot'] ?? null,
                'qty'           => $i['qty'],
                'unit_price'    => $i['unit_price'],
                'taxable_value' => $i['taxable_value'],
                'tax_rate'      => $i['tax_rate'],
                'cgst'          => $i['cgst'],
                'sgst'          => $i['sgst'],
                'igst'          => $i['igst'],
                'cess'          => $i['cess'],
                'line_total'    => $lineTotal,
            ];
        }, $items);
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0F | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
