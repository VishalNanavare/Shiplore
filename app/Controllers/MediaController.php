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
        if ($asset['visibility'] === 'private') {
            if (! session()->get('isLoggedIn') && ! session()->get('customer_id')) {
                throw PageNotFoundException::forPageNotFound('Media not found');
            }
            $this->checkPrivateOwner($asset);
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

        // Public product images stay CDN-cacheable. A private asset must never be stored
        // by a shared cache (CDN, corporate proxy, kiosk disk cache): a cached body is
        // replayed to the next requester without the request reaching the checks above.
        $cache = $asset['visibility'] === 'private'
            ? 'private, no-store, max-age=0, must-revalidate'
            : 'public, max-age=86400';

        return $this->response
            ->setHeader('Content-Type', (string) $asset['mime'])
            ->setHeader('Cache-Control', $cache)
            ->setBody((string) file_get_contents($path));
    }

    /**
     * Owner check for private assets.
     *
     * The session check above only proves *a* session exists, and storefront customers
     * self-register by phone OTP in seconds — so the effective ACL on rider/vendor KYC
     * scans, invoice PDFs and report exports was "anyone with a mobile number". The uuid
     * is unguessable, which caps the severity, but uuids are rendered into admin and
     * rider views and land in access logs, browser history and the database.
     *
     * ROLLOUT — log-only by default, following the same convention as
     * WebAuthFilter::checkPrincipal(). Legitimate readers are spread across the admin,
     * rider and vendor panels, and owner_type values this method does not enumerate
     * (invoice, credit_note, export…) would 404 a document somebody legitimately opens.
     * Read the 'private media access' notices for a full traffic day, widen the
     * predicate for every real combination, then set
     *
     *     media.enforcePrivateOwner = true
     *
     * in .env to enforce. Flipping and rolling back are env-only, no deploy.
     */
    private function checkPrivateOwner(array $asset): void
    {
        if ($this->mayReadPrivate($asset)) {
            return;
        }

        $enforcing = filter_var(env('media.enforcePrivateOwner', false), FILTER_VALIDATE_BOOLEAN);

        log_message(
            $enforcing ? 'warning' : 'notice',
            sprintf(
                'private media access [%s]: user %s / customer %s read asset %s owned by %s#%s',
                $enforcing ? 'BLOCKED' : 'would block',
                (string) (session()->get('user_id') ?: '-'),
                (string) (session()->get('customer_id') ?: '-'),
                (string) $asset['uuid'],
                (string) ($asset['owner_type'] ?? '-'),
                (string) ($asset['owner_id'] ?? '-'),
            ),
        );

        if ($enforcing) {
            throw PageNotFoundException::forPageNotFound('Media not found');
        }
    }

    /** @param array<string,mixed> $asset */
    private function mayReadPrivate(array $asset): bool
    {
        $ctx = service('scopeContext');

        // Platform staff holding media.view read anything — that is the admin media library.
        if ($ctx->isPlatform() && service('policyEngine')->can($ctx->all(), 'media.view')) {
            return true;
        }

        $ownerType = (string) ($asset['owner_type'] ?? '');
        $ownerId   = (int) ($asset['owner_id'] ?? 0);
        if ($ownerId <= 0) {
            return false;
        }

        // ScopeContext exposes vendorIds() but no shopIds(); derive shop scopes here
        // rather than widening its public surface for one caller.
        $shopIds = [];
        foreach ($ctx->scopes() as $s) {
            if (($s['type'] ?? null) === 'shop' && isset($s['id'])) {
                $shopIds[] = (int) $s['id'];
            }
        }

        return match ($ownerType) {
            'rider'  => $ownerId === (int) session()->get('rider_id'),
            'vendor' => in_array($ownerId, $ctx->vendorIds(), true),
            'shop'   => in_array($ownerId, $shopIds, true),
            default  => false,
        };
    }
}
