<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Upload paths that write into a directory the web server will execute.
 *
 * The rule these tests exist to hold: a stored file's extension must be derived from
 * the SERVER-detected mime type, never from the client-supplied filename. The root
 * .htaccess serves any path that exists on disk directly and declares a PHP handler
 * for the whole tree, so an attacker-named file under public/ is executed.
 */
final class UploadHardeningTest extends CIUnitTestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

    /**
     * The brand-logo upload wrote `logo_<time>.<client extension>` into
     * public/assets/images/ with overwrite enabled and no mime check at all — a file
     * named "s.php" became an executable PHP shell in the web root.
     */
    public function testBrandLogoExtensionComesFromTheServerNotTheClient(): void
    {
        $src = $this->read('Controllers/Admin/SettingsController.php');

        // Anchor to the real method CALL (`->getClientExtension()`), not the bare word:
        // the fix's own comment names it while explaining why it is gone, and an
        // unanchored match would grep that comment and never fail.
        $this->assertStringNotContainsString(
            '->getClientExtension()',
            $src,
            'the stored extension must never come from the client filename — that is an RCE',
        );
        $this->assertStringContainsString(
            '->getMimeType()',
            $src,
            'the extension must be derived from the server-detected mime type',
        );
    }

    /** The mime→extension map must be a closed allow-list, not a deny-list. */
    public function testBrandLogoUsesAClosedExtensionAllowList(): void
    {
        $src = $this->read('Controllers/Admin/SettingsController.php');

        foreach (['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'] as $mime) {
            $this->assertStringContainsString("'{$mime}'", $src, "the allow-list must still accept {$mime}");
        }
        // An unrecognised type must be refused outright, not silently defaulted.
        $this->assertMatchesRegularExpression(
            '/\$ext\s*===?\s*null/',
            $src,
            'an unrecognised mime type must be rejected, not given a fallback extension',
        );
    }

    /**
     * SVG stays accepted (removing it would be a behaviour change), so script inside it
     * must be neutralised at the web-server layer instead.
     */
    public function testSvgIsServedWithASandboxingContentSecurityPolicy(): void
    {
        foreach ([ROOTPATH . '.htaccess', FCPATH . '.htaccess'] as $file) {
            $this->assertFileExists($file);
            $htaccess = (string) file_get_contents($file);

            $this->assertMatchesRegularExpression(
                '/FilesMatch\s+"\\\\\.svg\$"/i',
                $htaccess,
                basename(dirname($file)) . '/.htaccess must neutralise script in uploaded SVGs',
            );
            $this->assertStringContainsString('sandbox', $htaccess);
        }
    }
}
