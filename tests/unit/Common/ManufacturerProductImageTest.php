<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Manufacturer product create/edit had no image handling anywhere — confirmed by
 * grep during investigation: zero references to getFile/image/media in either the
 * controller or repository. A B2B catalogue with no product photo is close to
 * useless to a buying vendor.
 *
 * maybeImage() is copied verbatim from Vendor\ProductController — it is tenant-
 * agnostic (works off $productId/$uid only, no vendor-specific logic inside it),
 * so reuse rather than reinvent. Vendor's full gallery (drag-reorder, primary-star
 * toggle, remove, media-library modal) is deliberately NOT replicated — that is a
 * much larger subsystem than "products need photos" calls for; the manufacturer
 * view gets a plain upload input and a read-only thumbnail strip.
 */
final class ManufacturerProductImageTest extends CIUnitTestCase
{
    private const REL = 'Controllers/Manufacturer/ProductController.php';

    private function code(): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . self::REL)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }

        return $out;
    }

    private function methodBody(string $method): string
    {
        $src = $this->code();
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

    public function testMaybeImageUsesTheSharedMediaServices(): void
    {
        $body = $this->methodBody('maybeImage');
        $this->assertNotSame('', $body, 'maybeImage() not found');

        $this->assertStringContainsString("service('mediaService')->store(", $body);
        $this->assertStringContainsString("service('mediaRepository')->attachToProduct(", $body);
        // The first successfully attached image becomes primary automatically —
        // same rule vendor's version follows.
        $this->assertStringContainsString('hasPrimary(', $body);
    }

    /** store() must upload images only AFTER the product exists — there's no id before that. */
    public function testStoreUploadsImagesAfterCreateSucceeds(): void
    {
        $body = $this->methodBody('store');
        $create = strpos($body, "service('manufacturerProductRepository')->create(");
        $upload = strpos($body, 'maybeImage(');
        $this->assertNotFalse($create);
        $this->assertNotFalse($upload, 'store() must call maybeImage() after a successful create');
        $this->assertGreaterThan($create, $upload);
    }

    public function testUpdateUploadsImages(): void
    {
        $body = $this->methodBody('update');
        $this->assertStringContainsString('maybeImage(', $body, 'update() must be able to add images to an existing product too');
    }

    /** The edit form needs to know what's already attached. */
    public function testFormPassesExistingImagesToTheView(): void
    {
        $body = $this->methodBody('form');
        $this->assertMatchesRegularExpression(
            "/'images'\\s*=>.*service\\('mediaRepository'\\)->forProduct\\(/s",
            $body,
            'form() must load existing images for an existing product',
        );
    }

    /**
     * The shell plus the partial it includes. The upload control moved into
     * partials/_product_form_body when the manufacturer form was rebuilt to render
     * the same shared shell as vendor and admin; the markup asserted below is
     * unchanged, and now also gets the gallery (reorder / primary / remove) that the
     * bespoke form never had.
     */
    private function view(): string
    {
        return (string) file_get_contents(APPPATH . 'Views/manufacturer/products/form.php')
            . (string) file_get_contents(APPPATH . 'Views/partials/_product_form_body.php');
    }

    public function testFormHasAMultipartFileInputNamedForServerSideMultiUpload(): void
    {
        $src = $this->view();

        // enctype is what actually makes a file upload work — a form without it
        // silently sends the filename as plain text instead of the file's bytes.
        $this->assertStringContainsString('enctype="multipart/form-data"', $src);
        // image[] (not image) is what getFileMultiple('image') in the controller reads.
        $this->assertStringContainsString('name="image[]"', $src);
        $this->assertStringContainsString('type="file"', $src);
    }

    public function testExistingImagesAreRenderedFromTheRealMediaUrl(): void
    {
        $src = $this->view();

        $this->assertStringContainsString("foreach (\$images as \$img)", $src);
        $this->assertStringContainsString("site_url('media/' . \$img['uuid'])", $src, 'must use the real servable media URL, not a guessed path');
    }
}
