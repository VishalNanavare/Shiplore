<?php

declare(strict_types=1);

namespace App\Libraries\Pdf;

use Config\Database;
use Dompdf\Dompdf;
use Dompdf\Options;
use Throwable;

/**
 * DocumentPdfService — Phase X2c (P0): the PDF pipeline. Renders the HTML
 * document templates (app/Views/documents/*) through dompdf (installed since
 * day one, never wired) and stores the result as a private media asset,
 * linking it via invoices.media_id / credit_notes.media_id. Generation is
 * lazy + cached: the first view generates, later views reuse the stored PDF.
 *
 * Paper: 'a4' for invoices/CN, 'receipt' = 80 mm thermal roll.
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §11.2
 */
final class DocumentPdfService
{
    /** Pure-ish: HTML → PDF bytes (no DB). */
    public function renderHtml(string $html, string $paper = 'a4'): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);          // templates are self-contained
        $options->set('isHtml5ParserEnabled', true);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        if ($paper === 'receipt') {
            $pdf->setPaper([0, 0, 226.77, 800]);          // 80 mm wide thermal roll
        } else {
            $pdf->setPaper('a4', 'portrait');
        }
        $pdf->render();

        return (string) $pdf->output();
    }

    /**
     * Invoice PDF bytes — generate + persist on first call, stream from media
     * after. @return array{ok:bool,reason?:string,bytes?:string,filename?:string}
     */
    public function invoicePdf(int $invoiceId, ?int $actorId = null): array
    {
        $full = service('invoiceRepository')->findFull($invoiceId);
        if ($full === null) {
            return ['ok' => false, 'reason' => 'invoice not found'];
        }
        $inv = $full['invoice'];

        $cached = $this->cachedBytes($inv['media_id'] ?? null);
        if ($cached !== null) {
            return ['ok' => true, 'bytes' => $cached, 'filename' => $this->filename($inv['invoice_no'])];
        }

        try {
            $bytes = $this->renderHtml(view('documents/invoice_a4', $full + ['brandName' => service('settingsRepository')->brandName()]));
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'render failed'];
        }

        $stored = service('mediaService')->storeBytes($bytes, 'pdf', 'application/pdf', 'invoice', $invoiceId, $actorId);
        if ($stored['ok'] ?? false) {
            Database::connect()->table('invoices')->where('id', $invoiceId)->update(['media_id' => $stored['id']]);
        }

        return ['ok' => true, 'bytes' => $bytes, 'filename' => $this->filename($inv['invoice_no'])];
    }

    /** Credit-note PDF bytes — same lazy generate-and-cache contract. */
    public function creditNotePdf(int $creditNoteId, ?int $actorId = null): array
    {
        $full = service('creditNoteRepository')->findFull($creditNoteId);
        if ($full === null) {
            return ['ok' => false, 'reason' => 'credit note not found'];
        }
        $cn = $full['credit_note'];

        $cached = $this->cachedBytes($cn['media_id'] ?? null);
        if ($cached !== null) {
            return ['ok' => true, 'bytes' => $cached, 'filename' => $this->filename($cn['credit_note_no'])];
        }

        try {
            $bytes = $this->renderHtml(view('documents/credit_note_a4', $full));
        } catch (Throwable) {
            return ['ok' => false, 'reason' => 'render failed'];
        }

        $stored = service('mediaService')->storeBytes($bytes, 'pdf', 'application/pdf', 'credit_note', $creditNoteId, $actorId);
        if ($stored['ok'] ?? false) {
            Database::connect()->table('credit_notes')->where('id', $creditNoteId)->update(['media_id' => $stored['id']]);
        }

        return ['ok' => true, 'bytes' => $bytes, 'filename' => $this->filename($cn['credit_note_no'])];
    }

    /**
     * POS sale receipt PDF (80mm). The caller (PosController) loads + tenant-scopes
     * the data, so this just renders. @return array{ok:bool,reason?:string,bytes?:string,filename?:string}
     */
    public function posReceiptPdf(array $sale, array $bill): array
    {
        try {
            $bytes = $this->renderHtml(view('documents/pos_receipt_80mm', ['sale' => $sale, 'bill' => $bill]), 'receipt');
        } catch (Throwable) {
            return ['ok' => false, 'reason' => 'render failed'];
        }

        return ['ok' => true, 'bytes' => $bytes, 'filename' => $this->filename((string) ($sale['server_invoice_no'] ?? $sale['offline_invoice_no'] ?? 'receipt'))];
    }

    /** POS credit-note PDF (80mm). */
    public function posCreditNotePdf(array $cn, array $bill): array
    {
        try {
            $bytes = $this->renderHtml(view('documents/pos_credit_note_80mm', ['cn' => $cn, 'bill' => $bill]), 'receipt');
        } catch (Throwable) {
            return ['ok' => false, 'reason' => 'render failed'];
        }

        return ['ok' => true, 'bytes' => $bytes, 'filename' => $this->filename((string) ($cn['credit_note_no'] ?? 'credit-note'))];
    }

    // ------------------------------------------------------------------

    private function cachedBytes(mixed $mediaId): ?string
    {
        if ($mediaId === null || (int) $mediaId <= 0) {
            return null;
        }
        $asset = Database::connect()->table('media_assets')
            ->where('id', (int) $mediaId)->where('status', 'active')->get()->getRowArray();
        if ($asset === null) {
            return null;
        }
        // S3-backed PDFs (bucket != 'local') — fetch the cached object from storage.
        if (($asset['bucket'] ?? 'local') !== 'local') {
            return service('documentStorage')->getBytes((string) $asset['object_key']);
        }
        $base = realpath(WRITEPATH . 'uploads');
        $path = realpath(WRITEPATH . 'uploads/' . $asset['object_key']);
        if ($base === false || $path === false || ! str_starts_with($path, $base) || ! is_file($path)) {
            return null;
        }
        $bytes = file_get_contents($path);

        return $bytes === false ? null : $bytes;
    }

    private function filename(string $docNo): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '-', $docNo) . '.pdf';
    }
}
