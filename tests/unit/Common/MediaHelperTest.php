<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * media_helper — icon mapping, human file sizes, and display-name fallback.
 */
final class MediaHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('media');
    }

    public function testIconByMime(): void
    {
        $this->assertSame('file-earmark-pdf', media_icon('application/pdf')[0]);
        $this->assertSame('file-earmark-image', media_icon('image/png')[0]);
        $this->assertSame('file-earmark-play', media_icon('video/mp4')[0]);
        $this->assertSame('file-earmark-excel', media_icon('text/csv')[0]);
        $this->assertSame('file-earmark', media_icon('application/octet-stream')[0]);
    }

    public function testHumanSize(): void
    {
        $this->assertSame('—', media_size(0));
        $this->assertSame('512 B', media_size(512));
        $this->assertSame('1.0 KB', media_size(1024));
        $this->assertSame('1.5 MB', media_size((int) (1.5 * 1024 * 1024)));
    }

    public function testNameFallsBackToBasename(): void
    {
        $this->assertSame('photo.jpg', media_name(['original_name' => 'photo.jpg', 'object_key' => 'vendors/1/media/x/abc.jpg']));
        $this->assertSame('abc.jpg', media_name(['original_name' => '', 'object_key' => 'vendors/1/media/x/abc.jpg']));
    }
}
