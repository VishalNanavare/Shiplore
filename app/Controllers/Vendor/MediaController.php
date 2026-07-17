<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Vendor\MediaController — general media library. A file-manager UI over
 * media_assets: folders are the vendor (business) and each of its shops. Uploads
 * go straight to S3 (test_s3) via presigned URLs; the chosen folder decides
 * ownership (owner_type vendor|shop). Staff are confined to their assigned shops.
 *
 * @see App\Libraries\Storage\DocumentStorage
 * @see App\Models\MediaLibraryRepository
 */
final class MediaController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $repo    = service('mediaLibraryRepository');
        $shops   = $this->shopOptions();               // [id => name], already access-scoped
        $shopIds = array_map('intval', array_keys($shops));

        $folder = (string) ($this->request->getGet('folder') ?? '');
        if ($folder === '') {
            $folder = $this->isOwner() ? 'vendor' : 'all';
        }
        [$files, $crumb] = $this->filesForFolder($folder, $shopIds, $repo);

        return $this->render('vendor/media/index', 'media', 'Media Library', [
            'shops'        => $shops,
            'folder'       => $folder,
            'files'        => $files,
            'crumb'        => $crumb,
            'isOwner'      => $this->isOwner(),
            'vendorCount'  => $this->isOwner() ? $repo->countForOwner('vendor', (int) $this->vendorId()) : 0,
            'allCount'     => $this->isOwner()
                ? $repo->countForOwner('vendor', (int) $this->vendorId()) + array_sum($repo->countByOwner('shop', $shopIds))
                : array_sum($repo->countByOwner('shop', $shopIds)),
            'shopCounts'   => $repo->countByOwner('shop', $shopIds),
        ]);
    }

    /** Issue an upload target for one file in the chosen folder (JSON). */
    public function presign(): ResponseInterface
    {
        if ($this->requireVendor()) {
            return $this->deny();
        }
        $owner = $this->ownerFromTarget((string) $this->request->getPost('target'));
        if ($owner === null) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'message' => 'You cannot upload there.', 'csrf' => csrf_hash()]);
        }
        [$type, $id] = $owner;
        $res = service('documentStorage')->presignMedia(
            (int) $this->vendorId(),
            $type === 'shop' ? $id : null,
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

    /** Record the uploaded object as a media asset owned by the chosen folder (JSON). */
    public function confirm(): ResponseInterface
    {
        if ($this->requireVendor()) {
            return $this->deny();
        }
        $owner = $this->ownerFromTarget((string) $this->request->getPost('target'));
        $key   = (string) $this->request->getPost('key');
        $mime  = (string) $this->request->getPost('content_type');
        $name  = trim((string) $this->request->getPost('filename'));
        $size  = (int) $this->request->getPost('size');

        if ($owner === null || ! $this->ownsKey($key) || service('documentStorage')->extForMedia($mime) === null) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'message' => 'Invalid upload.', 'csrf' => csrf_hash()]);
        }
        [$type, $id] = $owner;

        service('mediaLibraryRepository')->insertAsset([
            'uuid'          => $this->uuid(),
            'bucket'        => service('documentStorage')->bucket(),
            'object_key'    => $key,
            'owner_type'    => $type,
            'owner_id'      => $id,
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
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $m = service('mediaLibraryRepository')->findById($id);
        if ($m === null || ! $this->canAccessMedia($m)) {
            return redirect()->to('vendor/media')->with('error', 'File not found.');
        }

        return redirect()->to(service('documentStorage')->presignGet((string) $m['object_key'], 'vendor/media/file'));
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
        $repo = service('mediaLibraryRepository');
        $m    = $repo->findById($id);
        $back = 'vendor/media' . ($this->request->getGet('folder') ? '?folder=' . rawurlencode((string) $this->request->getGet('folder')) : '');
        if ($m !== null && $this->canAccessMedia($m)) {
            service('documentStorage')->delete((string) $m['object_key']);
            $repo->markDeleted($id, (int) session()->get('user_id'));

            return redirect()->to($back)->with('success', 'File removed.');
        }

        return redirect()->to($back)->with('error', 'File not found.');
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * Resolve a posted folder target ("vendor" | "shop:{id}") to an owner pair,
     * enforcing access. @return array{0:string,1:int}|null
     */
    private function ownerFromTarget(string $target): ?array
    {
        if ($target === 'vendor') {
            return $this->isOwner() ? ['vendor', (int) $this->vendorId()] : null;
        }
        if (str_starts_with($target, 'shop:')) {
            $id = (int) substr($target, 5);

            return in_array($id, $this->allowedShopIds(), true) ? ['shop', $id] : null;
        }

        return null;
    }

    /** @param array<string,mixed> $m */
    private function canAccessMedia(array $m): bool
    {
        $type = (string) ($m['owner_type'] ?? '');
        $id   = (int) ($m['owner_id'] ?? 0);
        if ($type === 'vendor') {
            return $id === (int) $this->vendorId() && $this->isOwner();
        }
        if ($type === 'shop') {
            return in_array($id, $this->allowedShopIds(), true);
        }

        return false;
    }

    /**
     * @param list<int> $shopIds
     * @return array{0:list<array<string,mixed>>,1:string}
     */
    private function filesForFolder(string $folder, array $shopIds, $repo): array
    {
        if ($folder === 'vendor' && $this->isOwner()) {
            return [$repo->listForOwner('vendor', (int) $this->vendorId()), 'Business files'];
        }
        if (str_starts_with($folder, 'shop:')) {
            $id = (int) substr($folder, 5);
            if (in_array($id, $shopIds, true)) {
                return [$repo->listForOwner('shop', $id), (string) ($this->shopOptions()[$id] ?? 'Shop')];
            }
        }
        // "all" — vendor business files (owner only) + every accessible shop's files
        $files = $this->isOwner() ? $repo->listForOwner('vendor', (int) $this->vendorId()) : [];
        $files = array_merge($files, $repo->listForOwners('shop', $shopIds));
        usort($files, static fn ($a, $b) => (int) $b['id'] <=> (int) $a['id']);

        return [$files, 'All files'];
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
