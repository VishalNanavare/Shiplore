<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * Vendor\DocumentUploadController — KYC / vendor document upload via presigned
 * URLs. The dropzone asks presign() for an upload target per file, PUTs the bytes
 * straight there (real S3, or the local dummy endpoint), then confirm() records
 * the media_asset + vendor_documents row. PDF/images only, 10 MB each.
 *
 * @see App\Libraries\Storage\DocumentStorage
 */
final class DocumentUploadController extends BaseVendorController
{
    private const TYPES = ['gst', 'pan', 'bank', 'fssai', 'address', 'other'];

    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        return $this->render('vendor/documents/upload', 'documents', 'Documents', [
            'docs'  => $this->listDocs(),
            'types' => self::TYPES,
        ]);
    }

    /** Issue an upload target for one file (JSON). */
    public function presign(): ResponseInterface
    {
        if ($this->requireVendor()) {
            return $this->deny();
        }
        $res = service('documentStorage')->presign(
            $this->vendorId(),
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
        if ($this->requireVendor()) {
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

    /** Record the uploaded object as a vendor document (JSON). */
    public function confirm(): ResponseInterface
    {
        if ($this->requireVendor()) {
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
            'owner_type' => 'vendor',
            'owner_id'   => $this->vendorId(),
            'mime'       => $mime,
            'visibility' => 'private',
            'status'     => 'active',
            'created_by' => $userId,
        ]);
        $mediaId = (int) $db->insertID();

        $db->table('vendor_documents')->insert([
            'vendor_id'  => $this->vendorId(),
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
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $doc = $this->docById($id);
        if ($doc === null || ! $this->ownsKey((string) ($doc['object_key'] ?? ''))) {
            return redirect()->to('vendor/kyc')->with('error', 'Document not found.');
        }

        return redirect()->to(service('documentStorage')->presignGet((string) $doc['object_key']));
    }

    /** Local dummy file serve (only used when S3 isn't configured). */
    public function file(): ResponseInterface
    {
        if ($this->requireVendor()) {
            return $this->deny();
        }
        $key = (string) $this->request->getGet('key');
        if (! $this->ownsKey($key)) {
            return $this->response->setStatusCode(403)->setBody('');
        }
        $path = service('documentStorage')->dummyPath($key);
        if (! is_file($path)) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        return $this->response
            ->setHeader('Content-Type', (string) (mime_content_type($path) ?: 'application/octet-stream'))
            ->setBody((string) file_get_contents($path));
    }

    public function delete(int $id): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
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

        return redirect()->to('vendor/kyc')->with('success', 'Document removed.');
    }

    /** @return array<string,mixed>|null */
    private function docById(int $id): ?array
    {
        $row = Database::connect()->table('vendor_documents vd')
            ->select('vd.id, vd.media_id, ma.object_key, ma.mime')
            ->join('media_assets ma', 'ma.id = vd.media_id', 'left')
            ->where('vd.id', $id)->where('vd.vendor_id', $this->vendorId())->where('vd.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    private function listDocs(): array
    {
        return Database::connect()->table('vendor_documents vd')
            ->select('vd.id, vd.doc_type, vd.status, vd.created_at, ma.object_key, ma.mime')
            ->join('media_assets ma', 'ma.id = vd.media_id', 'left')
            ->where('vd.vendor_id', $this->vendorId())->where('vd.deleted_at', null)
            ->orderBy('vd.id', 'DESC')->limit(100)->get()->getResultArray();
    }

    private function ownsKey(string $key): bool
    {
        return $key !== '' && str_starts_with($key, 'vendors/' . $this->vendorId() . '/');
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
