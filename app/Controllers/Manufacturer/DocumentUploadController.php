<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use App\Controllers\Concerns\ServesStoredFiles;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * Manufacturer\DocumentUploadController — KYC document upload for the manufacturer.
 *
 * The storage layer needs no manufacturer-specific work at all: DocumentStorage keys
 * objects under `vendors/{id}/documents/...` and `vendor_documents.vendor_id` points
 * at `vendors`, and a manufacturer IS a vendors row. So the same bucket, the same key
 * scheme and the same ownership prefix check all apply unchanged — only the tenant id
 * comes from manufacturerId() instead of vendorId(), and the dummy-serve route
 * differs because each panel serves from its own host.
 *
 * @see \App\Controllers\Vendor\DocumentUploadController — the vendor counterpart
 * @see \App\Libraries\Storage\DocumentStorage
 */
final class DocumentUploadController extends BaseManufacturerController
{
    use ServesStoredFiles;

    /** No 'fssai' — that is a food licence and does not apply to manufacturers here. */
    private const TYPES = ['gst', 'pan', 'bank', 'address', 'factory_licence', 'other'];

    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.document.view')) {
            return $denied;
        }

        return $this->render('manufacturer/documents/index', 'documents', 'Documents', [
            'docs'  => $this->listDocs(),
            'types' => self::TYPES,
        ]);
    }

    /** Issue an upload target for one file (JSON). */
    public function presign(): ResponseInterface
    {
        if ($this->requireManufacturer() || ! $this->can('mfg.document.upload')) {
            return $this->deny();
        }

        $res = service('documentStorage')->presign(
            (int) $this->manufacturerId(),
            (string) $this->request->getPost('filename'),
            (string) $this->request->getPost('content_type'),
            (int) $this->request->getPost('size'),
        );
        $res['csrf'] = csrf_hash();

        return $this->response->setStatusCode(($res['ok'] ?? false) ? 200 : 422)->setJSON($res);
    }

    /** Dummy receiver: store the raw PUT body locally (no CSRF — scoped by key + session). */
    public function put(): ResponseInterface
    {
        if ($this->requireManufacturer() || ! $this->can('mfg.document.upload')) {
            return $this->deny();
        }
        $key = (string) $this->request->getGet('key');
        if (! $this->ownsKey($key)) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        $body = fopen('php://input', 'rb');
        $ok   = is_resource($body) && service('documentStorage')->saveDummy($key, $body);
        if (is_resource($body)) {
            fclose($body);
        }

        return $this->response->setStatusCode($ok ? 200 : 500)->setJSON(['ok' => $ok]);
    }

    /** Record the uploaded object as a manufacturer document (JSON). */
    public function confirm(): ResponseInterface
    {
        if ($this->requireManufacturer() || ! $this->can('mfg.document.upload')) {
            return $this->deny();
        }

        $key  = (string) $this->request->getPost('key');
        $mime = (string) $this->request->getPost('content_type');
        $type = (string) $this->request->getPost('doc_type');
        $type = in_array($type, self::TYPES, true) ? $type : 'other';

        if (! $this->ownsKey($key) || service('documentStorage')->extFor($mime) === null) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'message' => 'Invalid upload.', 'csrf' => csrf_hash()]);
        }

        $db     = Database::connect();
        $userId = (int) session()->get('user_id');
        $db->table('media_assets')->insert([
            'uuid'       => $this->uuid(),
            'bucket'     => service('documentStorage')->bucket(),
            'object_key' => $key,
            // 'vendor', because media_assets keys owners by the vendors row this
            // manufacturer already is. A new owner_type would orphan the asset from
            // every existing lookup.
            'owner_type' => 'vendor',
            'owner_id'   => $this->manufacturerId(),
            'mime'       => $mime,
            'visibility' => 'private',
            'status'     => 'active',
            'created_by' => $userId,
        ]);
        $mediaId = (int) $db->insertID();

        $db->table('vendor_documents')->insert([
            'vendor_id'  => $this->manufacturerId(),
            'doc_type'   => $type,
            'media_id'   => $mediaId,
            'status'     => 'uploaded',
            'created_by' => $userId,
        ]);

        return $this->response->setJSON(['ok' => true, 'csrf' => csrf_hash()]);
    }

    /** Open a document (redirect to a presigned GET URL, or the dummy serve). */
    public function view(int $id)
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.document.view')) {
            return $denied;
        }

        $doc = $this->docById($id);
        if ($doc === null || ! $this->ownsKey((string) ($doc['object_key'] ?? ''))) {
            return redirect()->to('manufacturer/documents')->with('error', 'Document not found.');
        }

        return redirect()->to(service('documentStorage')->presignGet(
            (string) $doc['object_key'],
            'manufacturer/documents/file',
        ));
    }

    /** Local dummy file serve (only used when S3 isn't configured). */
    public function file(): ResponseInterface
    {
        if ($this->requireManufacturer() || ! $this->can('mfg.document.view')) {
            return $this->deny();
        }
        $key = (string) $this->request->getGet('key');
        if (! $this->ownsKey($key)) {
            return $this->response->setStatusCode(403)->setBody('');
        }

        return $this->serveStoredFile($key);
    }

    public function delete(int $id): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.document.upload')) {
            return $denied;
        }

        $db  = Database::connect();
        $doc = $this->docById($id);
        if ($doc !== null) {
            service('documentStorage')->delete((string) ($doc['object_key'] ?? ''));
            $db->table('vendor_documents')->where('id', $id)
                ->update(['deleted_at' => date('Y-m-d H:i:s'), 'updated_by' => (int) session()->get('user_id')]);
            if (! empty($doc['media_id'])) {
                $db->table('media_assets')->where('id', $doc['media_id'])
                    ->update(['status' => 'deleted', 'deleted_at' => date('Y-m-d H:i:s')]);
            }
        }

        return redirect()->to('manufacturer/documents')->with('success', 'Document removed.');
    }

    // ---- internals ---------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function docById(int $id): ?array
    {
        $row = Database::connect()->table('vendor_documents vd')
            ->select('vd.id, vd.media_id, ma.object_key, ma.mime')
            ->join('media_assets ma', 'ma.id = vd.media_id', 'left')
            ->where('vd.id', $id)->where('vd.vendor_id', $this->manufacturerId())->where('vd.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    private function listDocs(): array
    {
        return Database::connect()->table('vendor_documents vd')
            ->select('vd.id, vd.doc_type, vd.status, vd.created_at, ma.object_key, ma.mime')
            ->join('media_assets ma', 'ma.id = vd.media_id', 'left')
            ->where('vd.vendor_id', $this->manufacturerId())->where('vd.deleted_at', null)
            ->orderBy('vd.id', 'DESC')->limit(100)->get()->getResultArray();
    }

    /** Prefix check only — see ServesStoredFiles for why that alone is not enough. */
    private function ownsKey(string $key): bool
    {
        return $key !== '' && str_starts_with($key, 'vendors/' . $this->manufacturerId() . '/');
    }

    private function deny(): ResponseInterface
    {
        return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'message' => 'Not allowed.']);
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
