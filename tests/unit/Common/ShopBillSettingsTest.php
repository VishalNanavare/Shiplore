<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Models/ShopBillSettingsRepository.php';

use App\Models\ShopBillSettingsRepository;

/** POS bill settings: defaults + merge (the pure part the receipt relies on). */
final class ShopBillSettingsTest extends TestCase
{
    public function testDefaultsArePresent(): void
    {
        $d = ShopBillSettingsRepository::defaults();

        $this->assertArrayHasKey('header_note', $d);
        $this->assertArrayHasKey('terms', $d);
        $this->assertArrayHasKey('footer_note', $d);
        $this->assertTrue($d['show_savings']);
    }

    public function testMergeOverlaysStoredOverDefaults(): void
    {
        $merged = ShopBillSettingsRepository::merge(['terms' => 'No returns.', 'show_savings' => false]);

        $this->assertSame('No returns.', $merged['terms']);
        $this->assertFalse($merged['show_savings']);
        // untouched keys keep their defaults
        $this->assertSame(ShopBillSettingsRepository::defaults()['footer_note'], $merged['footer_note']);
    }

    public function testMergeCoercesTypes(): void
    {
        $merged = ShopBillSettingsRepository::merge(['show_savings' => '1', 'header_note' => 12345]);

        $this->assertIsBool($merged['show_savings']);
        $this->assertTrue($merged['show_savings']);
        $this->assertSame('12345', $merged['header_note']);
    }

    public function testMergeIgnoresUnknownKeys(): void
    {
        $merged = ShopBillSettingsRepository::merge(['evil' => 'x']);

        $this->assertArrayNotHasKey('evil', $merged);
    }
}
