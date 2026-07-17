<?php

declare(strict_types=1);

namespace App\Controllers\Rider;

use App\Models\RiderDocumentRepository;
use CodeIgniter\HTTP\RedirectResponse;

/** Rider\DocumentController — rider uploads/views their own KYC documents. */
final class DocumentController extends BaseRiderController
{
    public function index()
    {
        if ($d = $this->requireRider()) {
            return $d;
        }

        return view('rider/documents', [
            'docs'  => service('riderDocumentRepository')->forRider((int) $this->riderUserId()),
            'types' => RiderDocumentRepository::TYPES,
        ]);
    }

    public function upload(): RedirectResponse
    {
        if ($d = $this->requireRider()) {
            return $d;
        }
        $uid     = (int) $this->riderUserId();
        $docType = (string) $this->request->getPost('doc_type');
        $expires = (string) $this->request->getPost('expires_at');
        $file    = $this->request->getFile('document');
        if ($file === null || ! $file->isValid()) {
            return redirect()->to('rider/documents')->with('error', 'Choose a file to upload.');
        }
        $kind = str_starts_with((string) $file->getMimeType(), 'image/') ? 'image' : 'document';
        $res  = service('mediaService')->store($file, 'rider', $uid, $uid, 'private', $kind);
        if (empty($res['ok'])) {
            return redirect()->to('rider/documents')->with('error', $res['reason'] ?? 'Upload failed.');
        }
        service('riderDocumentRepository')->add($uid, $docType, (int) $res['id'], $expires ?: null, $uid);

        return redirect()->to('rider/documents')->with('success', 'Document uploaded — pending verification.');
    }

    public function remove(int $docId): RedirectResponse
    {
        if ($d = $this->requireRider()) {
            return $d;
        }
        service('riderDocumentRepository')->delete($docId, (int) $this->riderUserId(), (int) $this->riderUserId());

        return redirect()->to('rider/documents')->with('success', 'Document removed.');
    }
}
