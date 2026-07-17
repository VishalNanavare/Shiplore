<?php

declare(strict_types=1);

use App\Libraries\Storage\DocumentStorage;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Presigned document storage: only PDF/images are accepted, size is capped, and
 * with no S3 config it falls back to a local "dummy" PUT target under the
 * vendor's key prefix.
 */
final class DocumentStorageTest extends CIUnitTestCase
{
    private function store(array $cfg = []): DocumentStorage
    {
        return new DocumentStorage($cfg);
    }

    public function testRejectsNonPdfOrImage(): void
    {
        $this->assertFalse($this->store()->presign(1, 'notes.txt', 'text/plain', 100)['ok']);
    }

    public function testRejectsTooLarge(): void
    {
        $this->assertFalse($this->store()->presign(1, 'big.png', 'image/png', 20_000_000)['ok']);
    }

    public function testRejectsEmpty(): void
    {
        $this->assertFalse($this->store()->presign(1, 'empty.pdf', 'application/pdf', 0)['ok']);
    }

    public function testAcceptsPdfInDummyMode(): void
    {
        $res = $this->store()->presign(7, 'kyc.pdf', 'application/pdf', 2048);

        $this->assertTrue($res['ok']);
        $this->assertSame('dummy', $res['mode']);
        $this->assertStringStartsWith('vendors/7/', $res['key']);
        $this->assertStringEndsWith('.pdf', $res['key']);
        $this->assertStringContainsString('vendor/kyc/put', $res['url']);
        $this->assertSame('PUT', $res['method']);
    }

    public function testAcceptsImage(): void
    {
        $res = $this->store()->presign(3, 'shop.jpg', 'image/jpeg', 5000);
        $this->assertTrue($res['ok']);
        $this->assertStringEndsWith('.jpg', $res['key']);
    }

    public function testConfiguredReflectsS3Settings(): void
    {
        $this->assertFalse($this->store()->configured());
        $this->assertTrue($this->store(['endpoint' => 'https://s3.example', 'bucket' => 'docs'])->configured());
    }

    // ---- general media library --------------------------------------------

    public function testMediaAllowsBroaderTypes(): void
    {
        $s = $this->store();
        $this->assertSame('mp4', $s->extForMedia('video/mp4'));
        $this->assertSame('xlsx', $s->extForMedia('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        $this->assertSame('zip', $s->extForMedia('application/zip'));
        $this->assertNull($s->extForMedia('application/x-msdownload'));
    }

    public function testMediaVendorKeyPrefix(): void
    {
        $res = $this->store()->presignMedia(7, null, 'logo.png', 'image/png', 4096);
        $this->assertTrue($res['ok']);
        $this->assertStringStartsWith('vendors/7/media/', $res['key']);
        $this->assertStringContainsString('vendor/media/put', $res['url']);
    }

    public function testMediaShopKeyPrefix(): void
    {
        $res = $this->store()->presignMedia(7, 42, 'flyer.pdf', 'application/pdf', 4096);
        $this->assertTrue($res['ok']);
        $this->assertStringStartsWith('vendors/7/shops/42/media/', $res['key']);
        $this->assertStringEndsWith('.pdf', $res['key']);
    }

    public function testMediaRejectsOverSizeAndBadType(): void
    {
        $this->assertFalse($this->store()->presignMedia(1, null, 'huge.mp4', 'video/mp4', 300_000_000)['ok']);
        $this->assertFalse($this->store()->presignMedia(1, null, 'app.exe', 'application/x-msdownload', 1000)['ok']);
    }
}
