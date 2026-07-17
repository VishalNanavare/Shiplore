<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * MediaController — streams stored media by uuid. Files live privately under
 * writable/uploads (outside the web root); this is the only way to read them.
 * `public` assets serve to anyone; `private` assets require a logged-in session.
 * The object_key is sanitised so a crafted uuid can never escape the uploads dir.
 *
 * @see docs/architecture/41-SECURITY-PERFORMANCE.md
 */
final class MediaController extends BaseController
{
    public function serve(string $uuid): ResponseInterface
    {
        // uuid format only — blocks path traversal at the door
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
            throw PageNotFoundException::forPageNotFound('Media not found');
        }

        $asset = service('mediaRepository')->findByUuid($uuid);
        if ($asset === null || $asset['status'] !== 'active') {
            throw PageNotFoundException::forPageNotFound('Media not found');
        }
        if ($asset['visibility'] === 'private' && ! session()->get('isLoggedIn') && ! session()->get('customer_id')) {
            throw PageNotFoundException::forPageNotFound('Media not found');
        }

        // S3-backed assets (bucket != 'local') — hand off to a short-lived presigned GET.
        if (($asset['bucket'] ?? 'local') !== 'local') {
            return redirect()->to(service('documentStorage')->presignGet((string) $asset['object_key']));
        }

        // Resolve safely inside WRITEPATH/uploads; reject anything that escapes.
        $base = realpath(WRITEPATH . 'uploads');
        $path = realpath(WRITEPATH . 'uploads/' . $asset['object_key']);
        if ($base === false || $path === false || ! str_starts_with($path, $base) || ! is_file($path)) {
            throw PageNotFoundException::forPageNotFound('Media not found');
        }

        return $this->response
            ->setHeader('Content-Type', (string) $asset['mime'])
            ->setHeader('Cache-Control', 'public, max-age=86400')
            ->setBody((string) file_get_contents($path));
    }
}
