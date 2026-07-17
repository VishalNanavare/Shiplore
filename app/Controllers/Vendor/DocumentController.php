<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Database;

/**
 * Vendor\DocumentController — tenant-scoped invoice / credit-note PDFs (X2c).
 * Ownership is verified against the sub-order's vendor; staff additionally
 * need invoice.view / creditnote.view in their shop-scoped permissions.
 */
final class DocumentController extends BaseVendorController
{
    /** X4 — the vendor's invoice register (tenant-scoped, shop-filterable). */
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->isOwner() && ! $this->can('invoice.view')) {
            return redirect()->to('vendor/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return $this->render('vendor/documents/invoices', 'invoices', 'Invoices', [
            'invoices'    => service('invoiceRepository')->listForVendor((int) $this->vendorId(), $this->effectiveShopId() > 0 ? $this->effectiveShopId() : null),
            'shopOptions' => $this->shopOptions(),
            'shopId'      => $this->requestedShopId(),
        ]);
    }

    /** X4 — the vendor's credit-note register. */
    public function creditNotes()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->isOwner() && ! $this->can('creditnote.view')) {
            return redirect()->to('vendor/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return $this->render('vendor/documents/credit_notes', 'credit-notes', 'Credit Notes', [
            'notes' => service('creditNoteRepository')->listForVendor((int) $this->vendorId(), $this->effectiveShopId() > 0 ? $this->effectiveShopId() : null),
        ]);
    }

    /** X4 — the vendor lens on commission holds (doc 44 §8 C6). */
    public function commissionHolds()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->isOwner() && ! $this->can('commission.hold.view')) {
            return redirect()->to('vendor/dashboard')->with('error', 'You do not have permission to do that.');
        }
        $status = (string) ($this->request->getGet('status') ?: 'on_hold');
        $repo   = service('commissionLedgerRepository');

        return $this->render('vendor/documents/commission_holds', 'commission-holds', 'Commission Holds', [
            'rows'   => $repo->holdQueue((int) $this->vendorId(), $status),
            'totals' => $repo->totalsByStatus((int) $this->vendorId()),
            'status' => $status,
        ]);
    }

    public function invoicePdf(int $id)
    {
        if (($r = $this->requireVendor()) !== null) {
            return $r;
        }
        if (! $this->isOwner() && ! $this->can('invoice.view')) {
            return redirect()->to('vendor/dashboard')->with('error', 'You do not have permission to do that.');
        }
        if (! service('invoiceRepository')->vendorOwns($id, (int) $this->vendorId())) {
            throw PageNotFoundException::forPageNotFound('Invoice not found');
        }
        $res = service('documentPdfService')->invoicePdf($id, (int) session()->get('user_id'));
        if (! ($res['ok'] ?? false)) {
            throw PageNotFoundException::forPageNotFound('Invoice not found');
        }
        $this->logAccess('invoice');

        return $this->pdfResponse($res['bytes'], $res['filename']);
    }

    public function creditNotePdf(int $id)
    {
        if (($r = $this->requireVendor()) !== null) {
            return $r;
        }
        if (! $this->isOwner() && ! $this->can('creditnote.view')) {
            return redirect()->to('vendor/dashboard')->with('error', 'You do not have permission to do that.');
        }
        if (! service('creditNoteRepository')->vendorOwns($id, (int) $this->vendorId())) {
            throw PageNotFoundException::forPageNotFound('Credit note not found');
        }
        $res = service('documentPdfService')->creditNotePdf($id, (int) session()->get('user_id'));
        if (! ($res['ok'] ?? false)) {
            throw PageNotFoundException::forPageNotFound('Credit note not found');
        }
        $this->logAccess('credit_note');

        return $this->pdfResponse($res['bytes'], $res['filename']);
    }

    private function pdfResponse(string $bytes, string $filename)
    {
        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->setBody($bytes);
    }

    private function logAccess(string $entityType): void
    {
        try {
            Database::connect()->table('data_access_logs')->insert([
                'actor_user_id' => (int) session()->get('user_id'),
                'access_type'   => 'export', 'entity_type' => $entityType,
                'scope_type'    => 'vendor', 'scope_id' => $this->vendorId(), 'record_count' => 1,
            ]);
        } catch (\Throwable) {
        }
    }
}
