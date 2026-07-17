<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * InvoiceRepository — reads for the invoice registers (admin/vendor), the
 * customer download, and the PDF renderer's full document graph (X2c/X4).
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §11
 */
final class InvoiceRepository
{
    /** Full graph for rendering: invoice + lines + supplier + buyer. */
    public function findFull(int $invoiceId): ?array
    {
        $db  = Database::connect();
        $inv = $db->table('invoices i')
            ->select('i.*, so.sub_order_no, so.vendor_id, so.shop_id, so.order_id, '
                . 's.name AS shop_name, s.address_json AS shop_address_json, s.pincode AS shop_pincode, s.state_code AS shop_state, '
                . 'v.display_name AS vendor_name, o.order_no')
            ->join('sub_orders so', 'so.id = i.sub_order_id', 'left')
            ->join('shops s', 's.id = so.shop_id', 'left')
            ->join('vendors v', 'v.id = so.vendor_id', 'left')
            ->join('orders o', 'o.id = so.order_id', 'left')
            ->where('i.id', $invoiceId)
            ->get()->getRowArray();
        if ($inv === null) {
            return null;
        }

        $lines = $db->table('invoice_lines')->where('invoice_id', $invoiceId)->orderBy('id')->get()->getResultArray();

        $addrs   = $db->table('order_addresses')->where('order_id', $inv['order_id'])->get()->getResultArray();
        $billing = null;
        $shipping = null;
        foreach ($addrs as $a) {
            if (($a['type'] ?? '') === 'billing' && $billing === null) {
                $billing = $a;
            }
            if (($a['type'] ?? '') === 'shipping' && $shipping === null) {
                $shipping = $a;
            }
        }
        $buyer = $billing ?? $shipping ?? ($addrs[0] ?? null);

        return ['invoice' => $inv, 'lines' => $lines, 'buyer' => $buyer, 'billing' => $billing ?? $buyer, 'shipping' => $shipping ?? $buyer];
    }

    /** @return list<array<string,mixed>> admin register */
    public function listForAdmin(?string $docType = null, ?string $from = null, ?string $to = null): array
    {
        $b = Database::connect()->table('invoices i')
            ->select('i.id, i.invoice_no, i.invoice_date, i.doc_type, i.status, i.grand_total, i.media_id, '
                . 'so.sub_order_no, v.display_name AS vendor, s.name AS shop')
            ->join('sub_orders so', 'so.id = i.sub_order_id', 'left')
            ->join('vendors v', 'v.id = so.vendor_id', 'left')
            ->join('shops s', 's.id = so.shop_id', 'left')
            ->orderBy('i.id', 'DESC')->limit(300);
        if ($docType !== null && $docType !== '') {
            $b->where('i.doc_type', $docType);
        }
        if ($from !== null && $from !== '') {
            $b->where('i.invoice_date >=', $from);
        }
        if ($to !== null && $to !== '') {
            $b->where('i.invoice_date <=', $to);
        }

        return $b->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> vendor register (tenant-scoped) */
    public function listForVendor(int $vendorId, ?int $shopId = null): array
    {
        $b = Database::connect()->table('invoices i')
            ->select('i.id, i.invoice_no, i.invoice_date, i.doc_type, i.status, i.grand_total, i.media_id, so.sub_order_no, s.name AS shop')
            ->join('sub_orders so', 'so.id = i.sub_order_id', 'left')
            ->join('shops s', 's.id = so.shop_id', 'left')
            ->where('so.vendor_id', $vendorId)
            ->orderBy('i.id', 'DESC')->limit(300);
        if ($shopId !== null) {
            $b->where('so.shop_id', $shopId);
        }

        return $b->get()->getResultArray();
    }

    /** Tenant guard: does this invoice belong to the vendor? */
    public function vendorOwns(int $invoiceId, int $vendorId): bool
    {
        return Database::connect()->table('invoices i')
            ->join('sub_orders so', 'so.id = i.sub_order_id')
            ->where('i.id', $invoiceId)->where('so.vendor_id', $vendorId)
            ->countAllResults() > 0;
    }

    /** Ownership guard for the customer download. */
    public function customerOwns(int $invoiceId, int $customerId): bool
    {
        return Database::connect()->table('invoices i')
            ->join('sub_orders so', 'so.id = i.sub_order_id')
            ->join('orders o', 'o.id = so.order_id')
            ->where('i.id', $invoiceId)->where('o.customer_id', $customerId)
            ->countAllResults() > 0;
    }

    /** The invoice id for a sub-order, if one has been generated. */
    public function invoiceIdForSubOrder(int $subOrderId): ?int
    {
        $row = Database::connect()->table('invoices')->select('id')->where('sub_order_id', $subOrderId)->get()->getRowArray();

        return $row !== null ? (int) $row['id'] : null;
    }

    /** Invoiceable sub-orders (id,status) for a customer that have no invoice yet. */
    public function pendingInvoiceSubOrdersForCustomer(int $customerId): array
    {
        return Database::connect()->table('sub_orders so')
            ->select('so.id, so.status')
            ->join('orders o', 'o.id = so.order_id')
            ->join('invoices i', 'i.sub_order_id = so.id', 'left')
            ->where('o.customer_id', $customerId)
            ->whereIn('so.status', \App\Libraries\Invoicing\InvoiceService::INVOICEABLE)
            ->where('i.id', null)
            ->where('so.deleted_at', null)
            ->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> all of a customer's invoices (for the orders page) */
    public function listForCustomer(int $customerId): array
    {
        return Database::connect()->table('invoices i')
            ->select('i.id, i.invoice_no, o.order_no')
            ->join('sub_orders so', 'so.id = i.sub_order_id')
            ->join('orders o', 'o.id = so.order_id')
            ->where('o.customer_id', $customerId)
            ->orderBy('i.id', 'DESC')->limit(200)->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> a customer's invoices for one order */
    public function listForCustomerOrder(string $orderNo, int $customerId): array
    {
        return Database::connect()->table('invoices i')
            ->select('i.id, i.invoice_no, i.invoice_date, i.grand_total, i.sub_order_id, s.name AS shop')
            ->join('sub_orders so', 'so.id = i.sub_order_id')
            ->join('orders o', 'o.id = so.order_id')
            ->join('shops s', 's.id = so.shop_id', 'left')
            ->where('o.order_no', $orderNo)->where('o.customer_id', $customerId)
            ->orderBy('i.id')->get()->getResultArray();
    }
}
