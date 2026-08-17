<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/**
 * Serving bytes back out of DocumentStorage's local dummy store, safely.
 *
 * This is the local-filesystem fallback used when S3 is not configured. It exists in
 * one place because it is security-critical and was previously copy-pasted: both
 * Vendor\MediaController::file() and Vendor\DocumentUploadController::file() carried
 * their own identical copy, and the manufacturer panel needed two more. Four
 * independently-maintained copies of an inline-content allow-list is how one of them
 * quietly stops matching the others.
 *
 * The three properties that must hold, and why:
 *
 *  1. TRAVERSAL. Callers gate on a prefix check ("key starts with vendors/{id}/"),
 *     which "vendors/7/../../.." satisfies byte for byte. DocumentStorage::dummyPath()
 *     is what actually rejects it; that rejection is an exception, and it must surface
 *     as 403 rather than a 500.
 *  2. CONTENT TYPE IS NOT TRUSTED. Nothing inspected the bytes on the way in — presign
 *     and confirm only ever saw the CLIENT-declared content type, and the PUT receiver
 *     streams php://input straight to disk. So a key ending ".jpg" can hold HTML.
 *     mime_content_type() reports what is actually there, and only a closed allow-list
 *     may render inline; everything else downloads.
 *  3. SVG. It is XML and can carry <script>, so it stays viewable but inert under a
 *     sandboxing CSP rather than being blocked outright (blocking would be a
 *     behaviour change).
 */
trait ServesStoredFiles
{
    /** Content types allowed to render in-origin. Everything else is forced to download. */
    private const INLINE_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf',
        'video/mp4', 'video/webm', 'video/quicktime', 'audio/mpeg', 'audio/wav',
    ];

    /**
     * Stream one stored object back to the browser.
     *
     * The caller is responsible for having already established that this session may
     * read this key — this method re-checks nothing about ownership, only about what
     * the bytes are allowed to do once delivered.
     */
    private function serveStoredFile(string $key): ResponseInterface
    {
        try {
            $path = service('documentStorage')->dummyPath($key);
        } catch (InvalidArgumentException) {
            // Traversal that survived the caller's prefix check.
            return $this->response->setStatusCode(403)->setBody('');
        }

        if (! is_file($path)) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        $mime = (string) (mime_content_type($path) ?: 'application/octet-stream');

        $res = $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('X-Content-Type-Options', 'nosniff');

        if ($mime === 'image/svg+xml') {
            $res->setHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        } elseif (! in_array($mime, self::INLINE_MIMES, true)) {
            $res->setHeader('Content-Disposition', 'attachment; filename="' . basename($path) . '"');
        }

        return $res->setBody((string) file_get_contents($path));
    }
}
