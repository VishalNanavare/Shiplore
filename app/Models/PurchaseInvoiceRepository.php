<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/** PurchaseInvoiceRepository — vendor-scoped reads of posted purchase invoices. */
final class PurchaseInvoiceRepository
{
    /**
     * Search the vendor's variants for a purchase line — returns current pricing +
     * HSN-derived CGST/SGST/IGST/CESS so the line prefills (editable). @return list<array<string,mixed>>
     */
    public function searchVariants(int $vendorId, string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $db = Database::connect();
        $bc = array_column($db->table('product_barcodes')->select('variant_id')
            ->where('status', 'active')->where('deleted_at', null)->like('barcode_value', strtoupper($q))
            ->get()->getResultArray(), 'variant_id');

        $b = $db->table('product_variants pv')
            ->select('pv.id AS variant_id, pv.product_id, p.title AS product_name, pv.sku, pv.mrp, pv.base_price AS sale_price, pv.purchase_price, hs.code AS hsn_code')
            ->select('COALESCE(tr.cgst,0) AS cgst_pct, COALESCE(tr.sgst,0) AS sgst_pct, COALESCE(tr.igst,0) AS igst_pct, COALESCE(tr.cess,0) AS cess_pct', false)
            ->select("(SELECT pb.barcode_value FROM product_barcodes pb WHERE pb.variant_id = pv.id AND pb.status='active' AND pb.deleted_at IS NULL ORDER BY pb.is_primary DESC LIMIT 1) AS barcode", false)
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->join('hsn_sac_codes hs', 'hs.id = p.hsn_sac_id', 'left')
            ->join('tax_rates tr', "tr.tax_class_id = p.tax_class_id AND tr.status = 'active'", 'left')
            ->where('pv.vendor_id', $vendorId)->where('pv.deleted_at', null)->where('p.status', 'published');
        $b->groupStart()->like('pv.sku', $q)->orLike('p.title', $q);
        if ($bc !== []) {
            $b->orWhereIn('pv.id', array_map('intval', $bc));
        }
        $b->groupEnd();

        return $b->orderBy('p.title')->limit($limit)->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    public function list(int $vendorId, int $limit = 50): array
    {
        return Database::connect()->table('purchase_invoices pi')
            ->select('pi.id, pi.our_invoice_no, pi.supplier_invoice_id, pi.challan_no, pi.invoice_date, pi.grand_total, pi.total_qty, pi.status, pi.created_at, s.name AS supplier')
            ->join('suppliers s', 's.id = pi.supplier_id', 'left')
            ->where('pi.vendor_id', $vendorId)->where('pi.deleted_at', null)
            ->orderBy('pi.id', 'DESC')->limit($limit)
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null header + items + supplier */
    public function find(int $id, int $vendorId): ?array
    {
        $db  = Database::connect();
        $row = $db->table('purchase_invoices')->where('id', $id)->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $row['items'] = $db->table('purchase_invoice_items')->where('purchase_invoice_id', $id)->orderBy('id', 'ASC')
            ->get()->getResultArray();
        $row['supplier'] = ($row['supplier_id'] ?? null)
            ? $db->table('suppliers')->where('id', $row['supplier_id'])->get()->getRowArray()
            : null;

        return $row;
    }
}
