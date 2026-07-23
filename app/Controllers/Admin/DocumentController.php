<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Database;

/**
 * Admin\DocumentController — streams invoice / credit-note PDFs (X2c).
 * Generation is lazy via DocumentPdfService; every view is written to
 * data_access_logs (doc 44 §11 I3, §14).
 */
final class DocumentController extends BaseController
{
    /** X4 — invoice register. */
    public function index()
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'payment.view')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return view('admin/documents/invoices', [
            'title'    => 'Invoices',
            'invoices' => service('invoiceRepository')->listForAdmin(
                $this->request->getGet('doc_type') ?: null,
                $this->request->getGet('from') ?: null,
                $this->request->getGet('to') ?: null,
            ),
            'docType'  => (string) ($this->request->getGet('doc_type') ?? ''),
        ]);
    }

    /** X4 — credit-note register. */
    public function creditNotes()
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'payment.view')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return view('admin/documents/credit_notes', [
            'title' => 'Credit Notes',
            'notes' => service('creditNoteRepository')->listForAdmin(
                $this->request->getGet('from') ?: null,
                $this->request->getGet('to') ?: null,
            ),
        ]);
    }

    public function invoicePdf(int $id)
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'payment.view')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }
        $r = service('documentPdfService')->invoicePdf($id, service('scopeContext')->actorId());
        if (! ($r['ok'] ?? false)) {
            throw PageNotFoundException::forPageNotFound('Invoice not found');
        }
        $this->logAccess('invoice', $id);

        return $this->pdfResponse($r['bytes'], $r['filename']);
    }

    public function creditNotePdf(int $id)
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'payment.view')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }
        $r = service('documentPdfService')->creditNotePdf($id, service('scopeContext')->actorId());
        if (! ($r['ok'] ?? false)) {
            throw PageNotFoundException::forPageNotFound('Credit note not found');
        }
        $this->logAccess('credit_note', $id);

        return $this->pdfResponse($r['bytes'], $r['filename']);
    }

    private function pdfResponse(string $bytes, string $filename)
    {
        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->setBody($bytes);
    }

    private function logAccess(string $entityType, int $id): void
    {
        try {
            Database::connect()->table('data_access_logs')->insert([
                'actor_user_id' => service('scopeContext')->actorId(),
                'access_type'   => 'export', 'entity_type' => $entityType,
                'scope_type'    => 'platform', 'scope_id' => null, 'record_count' => 1,
            ]);
        } catch (\Throwable) {
        }
    }
}
