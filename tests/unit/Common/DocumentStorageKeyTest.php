<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Storage/DocumentStorage.php';

use App\Libraries\Storage\DocumentStorage;

/**
 * Path-traversal containment for local ("dummy") storage keys.
 *
 * Regression guard for the traversal in DocumentStorage::dummyPath(). The old
 * sanitiser was preg_replace('#[^a-zA-Z0-9_\-/.]#', '_', $key) — a character
 * class that whitelists both '.' and '/', so "vendors/7/../../../x" passed
 * through byte for byte. Combined with the prefix-only ownsKey() checks in the
 * vendor controllers, that was an arbitrary file read via
 * GET /vendor/{kyc,media}/file?key=... and an arbitrary file WRITE (RCE) via the
 * raw PUT upload endpoints, which reach saveDummy().
 *
 * Every case here must hold for BOTH the read and write paths, since they share
 * dummyPath() as their single choke point.
 */
final class DocumentStorageKeyTest extends TestCase
{
    private DocumentStorage $storage;
    private string $root;

    protected function setUp(): void
    {
        // dummyPath()/safeKey() are pure path logic and touch no config.
        $this->storage = new DocumentStorage([]);
        $this->root    = rtrim(WRITEPATH, '/\\') . '/uploads';
    }

    /** The shape the app actually generates must keep working, unchanged. */
    public function testLegitimateGeneratedKeyIsPreserved(): void
    {
        $key = 'vendors/7/media/2026/07/ab12cd34ef56.jpg';

        $this->assertSame($this->root . '/' . $key, $this->storage->dummyPath($key));
    }

    public function testLegitimateDocumentKeyIsPreserved(): void
    {
        $key = 'vendors/7/documents/2026/07/0123456789abcdef.pdf';

        $this->assertSame($this->root . '/' . $key, $this->storage->dummyPath($key));
    }

    /**
     * The exact payload from the audit: ownsKey() sees the "vendors/7/" prefix
     * and allows it, so containment has to be enforced here.
     *
     * @dataProvider traversalKeys
     */
    public function testTraversalIsRejected(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->storage->dummyPath($key);
    }

    public static function traversalKeys(): array
    {
        return [
            'escape to tracked s3 env' => ['vendors/7/../../../../s3_storage/.env'],
            'escape to app config'     => ['vendors/7/../../../../app/Config/Database.php'],
            'escape to session files'  => ['vendors/7/../../session/ci_session_abc123'],
            'write a shell to webroot' => ['vendors/7/../../../../public/shell.php'],
            'single parent'            => ['vendors/7/../x'],
            'bare parent'              => ['..'],
            'trailing parent'          => ['vendors/7/..'],
            'windows separators'       => ['vendors\\7\\..\\..\\..\\x'],
            'mixed separators'         => ['vendors/7\\../../x'],
            'parent after empty seg'   => ['vendors//../../x'],
        ];
    }

    /** A null byte truncates the path inside the underlying C calls. */
    public function testNullByteIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->storage->dummyPath("vendors/7/ok.jpg\0.php");
    }

    /** @dataProvider emptyKeys */
    public function testEmptyKeyIsRejected(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->storage->dummyPath($key);
    }

    public static function emptyKeys(): array
    {
        return ['empty' => [''], 'slash' => ['/'], 'dot' => ['.'], 'slashes' => ['///']];
    }

    /**
     * A leading slash must not make the result absolute.
     *
     * ".jpg" here is load-bearing, not decorative: since the M4 extension allow-list
     * landed, safeKey() rejects any key whose extension isn't on SAFE_EXT — a check
     * this test must satisfy to stay isolated to what it actually proves (leading-slash
     * normalisation), rather than incidentally failing on an unrelated gate.
     */
    public function testLeadingSlashDoesNotEscapeRoot(): void
    {
        $this->assertSame($this->root . '/etc/passwd.jpg', $this->storage->dummyPath('/etc/passwd.jpg'));
    }

    /** "." and empty segments are noise, not traversal — normalise, don't reject. */
    public function testRedundantSegmentsAreNormalised(): void
    {
        $this->assertSame(
            $this->root . '/vendors/7/media/a.jpg',
            $this->storage->dummyPath('vendors/./7//media/a.jpg'),
        );
    }

    /** Dotted names that are not traversal stay usable. */
    public function testDottedFilenamesAreNotConfusedWithTraversal(): void
    {
        $this->assertSame(
            $this->root . '/vendors/7/..hidden/x...y.jpg',
            $this->storage->dummyPath('vendors/7/..hidden/x...y.jpg'),
        );
    }

    /** Characters outside the allow-list are still neutralised, as before. */
    public function testDisallowedCharactersAreReplaced(): void
    {
        $this->assertSame(
            $this->root . '/vendors/7/a_b_c.jpg',
            $this->storage->dummyPath('vendors/7/a b;c.jpg'),
        );
    }

    /**
     * Belt and braces: whatever the input, the result never leaves the root.
     *
     * All keys carry a SAFE_EXT extension so this stays a pure traversal-safety
     * check, not an incidental exercise of the separate M4 extension gate.
     */
    public function testResolvedPathNeverEscapesRoot(): void
    {
        $keys = [
            'vendors/7/media/x.jpg', '/etc/passwd.jpg', 'vendors/./7/x.jpg', 'a/b/c.jpg',
            'vendors/7/..hidden/y.jpg', 'vendors/7/a b;c.jpg',
        ];

        foreach ($keys as $key) {
            $this->assertStringStartsWith(
                $this->root . '/',
                $this->storage->dummyPath($key),
                'key escaped the upload root: ' . $key,
            );
        }
    }
}
