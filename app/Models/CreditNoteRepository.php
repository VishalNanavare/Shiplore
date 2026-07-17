<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * CreditNoteRepository — reads for the CN registers and the PDF renderer (X2c/X4).
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §12
 */
final class CreditNoteRepository
{
    /** Full graph for rendering: CN + lines + parties + original invoice ref. */
    public function findFull(int $creditNoteId): ?array
    {
        $db = Database::connect();
        $cn = $db->table('credit_notes cn')
            ->select('cn.*, so.sub_order_no, so.vendor_id, so.shop_id, so.order_id, '
                . 's.name AS shop_name, s.gstin AS shop_gstin, s.address_json AS shop_address_json, s.pincode AS shop_pincode, s.state_code AS shop_state, '
                . 'v.display_name AS vendor_name, o.order_no, i.invoice_no AS against_invoice_no, i.invoice_date AS against_invoice_date')
            ->join('sub_orders so', 'so.id = cn.sub_order_id', 'left')
            ->join('shops s', 's.id = so.shop_id', 'left')
            ->join('vendors v', 'v.id = so.vendor_id', 'left')
            ->join('orders o', 'o.id = so.order_id', 'left')
            ->join('invoices i', 'i.sub_order_id = cn.sub_order_id', 'left')
            ->where('cn.id', $creditNoteId)
            ->get()->getRowArray();
        if ($cn === null) {
            return null;
        }

        $lines = $db->table('credit_note_lines')->where('credit_note_id', $creditNoteId)->orderBy('id')->get()->getResultArray();
        $buyer = $cn['order_id'] !== null
            ? $db->table('order_addresses')->where('order_id', $cn['order_id'])
                ->orderBy("type = 'billing'", 'DESC', false)->get(1)->getRowArray()
            : null;

        return ['credit_note' => $cn, 'lines' => $lines, 'buyer' => $buyer];
    }

    /** @return list<array<string,mixed>> admin register */
    public function listForAdmin(?string $from = null, ?string $to = null): array
    {
        $b = Database::connect()->table('credit_notes cn')
            ->select('cn.id, cn.credit_note_no, cn.cn_date, cn.status, cn.grand_total, cn.media_id, '
                . 'so.sub_order_no, v.display_name AS vendor, s.name AS shop')
            ->join('sub_orders so', 'so.id = cn.sub_order_id', 'left')
            ->join('vendors v', 'v.id = so.vendor_id', 'left')
            ->join('shops s', 's.id = so.shop_id', 'left')
            ->orderBy('cn.id', 'DESC')->limit(300);
        if ($from !== null && $from !== '') {
            $b->where('cn.cn_date >=', $from);
        }
        if ($to !== null && $to !== '') {
            $b->where('cn.cn_date <=', $to);
        }

        return $b->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> vendor register (tenant-scoped) */
    public function listForVendor(int $vendorId, ?int $shopId = null): array
    {
        $b = Database::connect()->table('credit_notes cn')
            ->select('cn.id, cn.credit_note_no, cn.cn_date, cn.status, cn.grand_total, cn.media_id, so.sub_order_no, s.name AS shop')
            ->join('sub_orders so', 'so.id = cn.sub_order_id', 'left')
            ->join('shops s', 's.id = so.shop_id', 'left')
            ->where('so.vendor_id', $vendorId)
            ->orderBy('cn.id', 'DESC')->limit(300);
        if ($shopId !== null) {
            $b->where('so.shop_id', $shopId);
        }

        return $b->get()->getResultArray();
    }

    public function vendorOwns(int $creditNoteId, int $vendorId): bool
    {
        return Database::connect()->table('credit_notes cn')
            ->join('sub_orders so', 'so.id = cn.sub_order_id')
            ->where('cn.id', $creditNoteId)->where('so.vendor_id', $vendorId)
            ->countAllResults() > 0;
    }
}
