<?php

declare(strict_types=1);

use App\Controllers\Concerns\ServesStoredFiles;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * The shared dummy-file serve, tested against REAL bytes on disk.
 *
 * This logic used to be copy-pasted into Vendor\MediaController::file() and
 * Vendor\DocumentUploadController::file(), untested in both, and the manufacturer
 * panel needed two more copies. It is the last line of defence for a genuinely nasty
 * case: nothing inspects uploaded bytes on the way in — presign and confirm see only
 * the CLIENT-declared content type, and the PUT receiver streams php://input straight
 * to disk — so a key ending ".jpg" can contain HTML. Serving that inline would execute
 * it in our own origin.
 *
 * Real temp files are used rather than a mocked mime lookup, because the property
 * under test IS "what does the server conclude the bytes are", and a mock of
 * mime_content_type() would assert nothing about that.
 */
final class ServesStoredFilesTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->tmp = [];
        Services::reset();
        parent::tearDown();
    }

    /** Write $bytes to a temp file with the given extension and return its path. */
    private function tempFile(string $ext, string $bytes): string
    {
        $path = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'sst_' . bin2hex(random_bytes(6)) . '.' . $ext;
        file_put_contents($path, $bytes);
        $this->tmp[] = $path;

        return $path;
    }

    /** Serve $path through the trait and hand back the response. */
    private function serve(string $path): Response
    {
        Services::injectMock('documentStorage', new class ($path) {
            public function __construct(private string $path) {}

            public function dummyPath(string $key): string { return $this->path; }
        });

        $host = new class {
            use ServesStoredFiles;

            public $response;

            public function __construct()
            {
                $this->response = Services::response(null, false);
            }

            public function call(string $key)
            {
                return $this->serveStoredFile($key);
            }
        };

        return $host->call('vendors/1/media/whatever');
    }

    /** A real PNG renders inline — no attachment disposition. */
    public function testAnImageIsServedInline(): void
    {
        // Smallest valid PNG (1x1, transparent).
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        $res = $this->serve($this->tempFile('png', (string) $png));

        $this->assertSame('image/png', $res->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $res->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('', $res->getHeaderLine('Content-Disposition'), 'a real image should render inline');
    }

    /**
     * The whole point. HTML stored under a .jpg key must be forced to download —
     * if it rendered inline it would run script in our origin.
     */
    public function testHtmlDisguisedAsAnImageIsForcedToDownload(): void
    {
        $res = $this->serve($this->tempFile('jpg', '<html><body><script>alert(document.domain)</script></body></html>'));

        $this->assertStringContainsString(
            'attachment',
            $res->getHeaderLine('Content-Disposition'),
            'HTML must never be served inline, whatever the key extension claims',
        );
        $this->assertStringNotContainsString('image/jpeg', $res->getHeaderLine('Content-Type'));
    }

    /** SVG stays viewable but inert, under a sandboxing CSP. */
    public function testSvgIsServedUnderASandboxingCsp(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>';
        $res = $this->serve($this->tempFile('svg', $svg));

        if ($res->getHeaderLine('Content-Type') !== 'image/svg+xml') {
            $this->markTestSkipped('this platform does not detect image/svg+xml; the CSP branch cannot be exercised');
        }

        $csp = $res->getHeaderLine('Content-Security-Policy');
        $this->assertStringContainsString('sandbox', $csp);
        $this->assertStringContainsString("default-src 'none'", $csp);
    }

    /** Traversal that survived the caller's prefix check must 403, not 500. */
    public function testTraversalRejectedByDummyPathBecomesA403(): void
    {
        Services::injectMock('documentStorage', new class {
            public function dummyPath(string $key): string
            {
                throw new InvalidArgumentException('traversal');
            }
        });

        $host = new class {
            use ServesStoredFiles;

            public $response;

            public function __construct() { $this->response = Services::response(null, false); }

            public function call(string $key) { return $this->serveStoredFile($key); }
        };

        $this->assertSame(403, $host->call('vendors/1/../../etc/passwd')->getStatusCode());
    }

    public function testMissingFileIs404(): void
    {
        $res = $this->serve(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sst_definitely_missing_' . bin2hex(random_bytes(6)));

        $this->assertSame(404, $res->getStatusCode());
    }

    /**
     * All four controllers must use the shared implementation. An inline copy would
     * drift from the tests above without ever failing them.
     */
    public function testEveryFileServingControllerUsesTheSharedTrait(): void
    {
        $controllers = [
            'Controllers/Vendor/MediaController.php',
            'Controllers/Vendor/DocumentUploadController.php',
            'Controllers/Manufacturer/MediaController.php',
            'Controllers/Manufacturer/DocumentUploadController.php',
        ];

        foreach ($controllers as $rel) {
            $src = (string) file_get_contents(APPPATH . $rel);
            $this->assertStringContainsString('use ServesStoredFiles;', $src, "{$rel} must use the shared serve");
            $this->assertStringNotContainsString(
                'mime_content_type(',
                $src,
                "{$rel} still has its own inline content-type handling; it must delegate to the trait",
            );
        }
    }
}
