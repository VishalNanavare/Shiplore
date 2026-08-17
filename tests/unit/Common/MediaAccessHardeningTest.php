<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Private-media access, caching and storage-key safety (audit M1-M4, L6).
 *
 * The private corpus is rider and vendor KYC scans, PAN/GST/bank proofs, invoice and
 * credit-note PDFs and report exports. The uuid is the only capability protecting them,
 * so every layer that can widen where those bytes come to rest matters.
 */
final class MediaAccessHardeningTest extends CIUnitTestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

    /**
     * Brace-matching body extractor. Load-bearing here: asserting a symbol merely
     * EXISTS in a file passes even when its call site has been deleted, because the
     * method definition (or the const) is still there. Two of these tests were weak
     * for exactly that reason until mutation testing caught it.
     */
    private function methodBody(string $src, string $method): string
    {
        if (! preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $brace = strpos($src, '{', (int) $m[0][1]);
        if ($brace === false) {
            return '';
        }
        $depth = 0;
        for ($i = $brace, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }

        return '';
    }

    // ------------------------------------------------------------------ M1

    /**
     * The only gate on a private asset was "some session exists" — and storefront
     * customers self-register by phone OTP in seconds, so the effective ACL was
     * "anyone with a mobile number". The owner columns were not even selected.
     */
    public function testPrivateMediaIsCheckedAgainstItsOwner(): void
    {
        $repo = $this->read('Models/MediaRepository.php');
        $this->assertMatchesRegularExpression(
            '/select\(\'uuid[^\')]*owner_type/',
            $repo,
            'findByUuid() must select the owner columns or no ownership check is possible',
        );

        // Scoped to serve()'s own body: mayReadPrivate() being DEFINED somewhere in the
        // file proves nothing if serve() no longer calls it.
        $body = $this->methodBody($this->read('Controllers/MediaController.php'), 'serve');
        $this->assertStringContainsString(
            '$this->checkPrivateOwner(',
            $body,
            'serve() must actually reach the owner check for a private asset, not just define it',
        );
    }

    /**
     * Ships log-only first, per this project's rollout convention: legitimate readers
     * are spread across admin, rider and vendor views, and an unknown owner_type
     * combination would 404 a real document.
     */
    public function testPrivateMediaOwnerCheckIsStagedBehindAFlag(): void
    {
        $ctrl = $this->read('Controllers/MediaController.php');

        $this->assertStringContainsString("env('media.enforcePrivateOwner'", $ctrl);
        $this->assertStringContainsString('log_message(', $ctrl, 'log-only mode must record what it would have blocked');
    }

    // ------------------------------------------------------------------ M2

    /** `public` authorises any shared cache to replay the body to a different user. */
    public function testPrivateMediaIsNotPubliclyCacheable(): void
    {
        $ctrl = $this->read('Controllers/MediaController.php');

        $this->assertStringContainsString('no-store', $ctrl, 'private bytes must never be stored by a shared cache');
        // Public assets must keep the identical directive, or CDN behaviour changes.
        $this->assertStringContainsString("'public, max-age=86400'", $ctrl);
    }

    // ------------------------------------------------------------------ M3

    /**
     * Bytes were never inspected on the way in, so anything executable in our origin
     * must not render inline.
     *
     * UPDATED: this used to assert the handling sat inside each vendor controller,
     * because that is where it was — copy-pasted into both, and into no test. It now
     * lives once in App\Controllers\Concerns\ServesStoredFiles, which all four
     * file-serving controllers (vendor + manufacturer) delegate to, and is covered by
     * ServesStoredFilesTest against real bytes on disk rather than by grepping source.
     * The assertion follows the code; the property it protects is unchanged.
     */
    public function testUploadedBytesCannotRenderInlineUnlessAllowListed(): void
    {
        $src = $this->read('Controllers/Concerns/ServesStoredFiles.php');

        $this->assertStringContainsString(
            'Content-Disposition',
            $src,
            'sniffed user-uploaded bytes must not be served inline with no disposition',
        );
        $this->assertStringContainsString('nosniff', $src);
        $this->assertStringContainsString('sandbox', $src, 'SVG must be served with a sandboxing CSP');

        // ...and every controller that serves stored bytes must actually route through it.
        foreach ([
            'Controllers/Vendor/MediaController.php',
            'Controllers/Vendor/DocumentUploadController.php',
            'Controllers/Manufacturer/MediaController.php',
            'Controllers/Manufacturer/DocumentUploadController.php',
        ] as $rel) {
            $this->assertStringContainsString(
                'serveStoredFile(',
                $this->read($rel),
                $rel . ' must serve stored bytes through the shared, tested implementation',
            );
        }
    }

    // ------------------------------------------------------------------ M4

    /** safeKey() is the one choke point on both the PUT and the read paths. */
    public function testStorageKeysAreConstrainedToSafeExtensions(): void
    {
        $src = $this->read('Libraries/Storage/DocumentStorage.php');

        $this->assertStringContainsString('SAFE_EXT', $src, 'any [a-zA-Z0-9_.-] segment was writable, including shell.php');

        // Scoped to safeKey()'s own body: PATHINFO_EXTENSION appearing anywhere in the
        // file proves nothing if the choke point itself no longer checks it.
        $body = $this->methodBody($src, 'safeKey');
        $this->assertStringContainsString(
            'PATHINFO_EXTENSION',
            $body,
            'the extension must actually be extracted inside safeKey()',
        );
        $this->assertMatchesRegularExpression(
            '/in_array\(\s*\$ext\s*,\s*self::SAFE_EXT\s*,\s*true\s*\)/',
            $body,
            'safeKey() must actually reject an extension not on the allow-list, not just define one',
        );
    }

    /** The allow-list must cover every extension the app itself generates. */
    public function testSafeExtensionListCoversTheAppsOwnUploadTypes(): void
    {
        $src = $this->read('Libraries/Storage/DocumentStorage.php');

        foreach (['pdf', 'jpg', 'png', 'webp', 'gif', 'svg', 'mp4', 'webm', 'mov', 'mp3', 'wav',
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip'] as $ext) {
            $this->assertStringContainsString("'{$ext}'", $src, "SAFE_EXT is missing {$ext} — existing files would 404 on read");
        }
        // The whole point: executables must be absent.
        foreach (['php', 'phtml', 'php8', 'sh'] as $bad) {
            $this->assertStringNotContainsString("'{$bad}'", $src, "SAFE_EXT must not contain {$bad}");
        }
    }

    /** A PUT streams the real body; the declared size is not a limit. */
    public function testPresignedPutIsSizeCapped(): void
    {
        $src = $this->read('Libraries/Storage/DocumentStorage.php');

        $this->assertStringContainsString('MEDIA_MAX_BYTES', $src);
        $this->assertStringContainsString(
            'stream_copy_to_stream',
            $src,
            'the stream must be copied with an explicit cap, not written unbounded',
        );
    }

    // ------------------------------------------------------------------ L6

    /** The product-image picker must not offer private KYC scans as product art. */
    public function testImagePickerExcludesPrivateAssets(): void
    {
        $body = $this->read('Models/MediaRepository.php');
        $start = strpos($body, 'function vendorImages');
        $this->assertNotFalse($start);
        $slice = substr($body, $start, 900);

        $this->assertStringContainsString(
            "where('visibility', 'public')",
            $slice,
            'a KYC jpg matches every other predicate and appears alongside product photography',
        );
    }

    /**
     * Deliberately NOT changed: vendorOwnsImage() still ignores visibility, so a
     * private image already attached to a live product keeps rendering. Tightening
     * both at once would blank existing product pages.
     */
    public function testAlreadyAttachedPrivateImagesKeepRendering(): void
    {
        $body = $this->read('Models/MediaRepository.php');
        $start = strpos($body, 'function vendorOwnsImage');
        $this->assertNotFalse($start);
        $slice = substr($body, $start, 700);

        $this->assertStringNotContainsString(
            "where('visibility', 'public')",
            $slice,
            'gating ownership on visibility would break product pages using an already-attached asset',
        );
    }
}
