<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use App\Controllers\Concerns\ServesStoredFiles;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Manufacturer\MediaController — the manufacturer's media library over media_assets.
 *
 * One deliberate difference from the vendor library: it is FLAT, owned by the
 * manufacturer, with no per-unit folders. The vendor version keys shop folders as
 * `vendors/{id}/shops/{shopId}/media/`, and DocumentStorage::presignMedia() writes
 * that path segment literally. Reusing it for an mshop id would put manufacturing
 * units under a path segment that means "shop" and, worse, would let an mshop id
 * collide with a real shop id in the same namespace. Units get their own media
 * grouping the day there is a key scheme that names them honestly.
 *
 * Assets are owned as owner_type 'vendor' with the manufacturer's own id, matching
 * how ProfileController stores the logo — a manufacturer IS a vendors row.
 *
 * @see \App\Controllers\Vendor\MediaController — the vendor counterpart
 */
final class MediaController extends BaseManufacturerController
{
    use ServesStoredFiles;

    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.media.view')) {
            return $denied;
        }

        $repo = service('mediaLibraryRepository');

        return $this->render('manufacturer/media/index', 'media', 'Media Library', [
            'files'     => $repo->listForOwner('vendor', (int) $this->manufacturerId()),
            'fileCount' => $repo->countForOwner('vendor', (int) $this->manufacturerId()),
            'canUpload' => $this->can('mfg.media.upload'),
        ]);
    }

    /** Issue an upload target for one file (JSON). */
    public function presign(): ResponseInterface
    {
        if ($this->requireManufacturer() || ! $this->can('mfg.media.upload')) {
            return $this->deny();
        }

        // null shop id => the tenant-level `vendors/{id}/media/` prefix.
        $res = service('documentStorage')->presignMedia(
            (int) $this->manufacturerId(),
            null,
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
        if ($this->requireManufacturer() || ! $this->can('mfg.media.upload')) {
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

    /** Record the uploaded object as a media asset (JSON). */
    public function confirm(): ResponseInterface
    {
        if ($this->requireManufacturer() || ! $this->can('mfg.media.upload')) {
            return $this->deny();
        }

        $key  = (string) $this->request->getPost('key');
        $mime = (string) $this->request->getPost('content_type');
        $name = trim((string) $this->request->getPost('filename'));
        $size = (int) $this->request->getPost('size');

        if (! $this->ownsKey($key) || service('documentStorage')->extForMedia($mime) === null) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'message' => 'Invalid upload.', 'csrf' => csrf_hash()]);
        }

        service('mediaLibraryRepository')->insertAsset([
            'uuid'          => $this->uuid(),
            'bucket'        => service('documentStorage')->bucket(),
            'object_key'    => $key,
            'owner_type'    => 'vendor',
            'owner_id'      => (int) $this->manufacturerId(),
            'mime'          => $mime,
            'original_name' => $name !== '' ? mb_substr($name, 0, 255) : null,
            'size_bytes'    => $size > 0 ? $size : null,
            'visibility'    => 'private',
            'status'        => 'active',
            'created_by'    => (int) session()->get('user_id'),
        ]);

        return $this->response->setJSON(['ok' => true, 'csrf' => csrf_hash()]);
    }

    /** Open a file (redirect to a presigned GET URL, or the dummy serve). */
    public function view(int $id)
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.media.view')) {
            return $denied;
        }

        $m = service('mediaLibraryRepository')->findById($id);
        if ($m === null || ! $this->ownsAsset($m)) {
            return redirect()->to('manufacturer/media')->with('error', 'File not found.');
        }

        return redirect()->to(service('documentStorage')->presignGet(
            (string) $m['object_key'],
            'manufacturer/media/file',
        ));
    }

    /** Local dummy file serve (only used when S3 isn't configured). */
    public function file(): ResponseInterface
    {
        if ($this->requireManufacturer() || ! $this->can('mfg.media.view')) {
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
        if ($denied = $this->guard('mfg.media.upload')) {
            return $denied;
        }

        $repo = service('mediaLibraryRepository');
        $m    = $repo->findById($id);
        if ($m !== null && $this->ownsAsset($m)) {
            service('documentStorage')->delete((string) $m['object_key']);
            $repo->markDeleted($id, (int) session()->get('user_id'));

            return redirect()->to('manufacturer/media')->with('success', 'File removed.');
        }

        return redirect()->to('manufacturer/media')->with('error', 'File not found.');
    }

    // ---- internals ---------------------------------------------------------

    /**
     * Ownership by the stored row, not by the key. findById() is not tenant-scoped, so
     * without this any manufacturer could open any asset by guessing an id.
     *
     * @param array<string,mixed> $m
     */
    private function ownsAsset(array $m): bool
    {
        return (string) ($m['owner_type'] ?? '') === 'vendor'
            && (int) ($m['owner_id'] ?? 0) === (int) $this->manufacturerId();
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
