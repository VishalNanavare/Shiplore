<?php

declare(strict_types=1);

use App\Libraries\Catalog\Barcode;
use CodeIgniter\Test\CIUnitTestCase as TestCase;

/** RB1 — pure barcode validation (GTIN check digits + symbology formats). */
final class BarcodeTest extends TestCase
{
    public function testValidEan13(): void
    {
        $r = Barcode::validate('EAN13', '4006381333931');
        $this->assertTrue($r['ok']);
        $this->assertSame('4006381333931', $r['value']);
    }

    public function testEan13BadCheckDigitRejected(): void
    {
        $this->assertFalse(Barcode::validate('EAN13', '4006381333930')['ok']);
    }

    public function testEan13WrongLengthRejected(): void
    {
        $this->assertFalse(Barcode::validate('EAN13', '400638133393')['ok']);   // 12 digits
    }

    public function testValidUpcA(): void
    {
        $this->assertTrue(Barcode::validate('UPC', '036000291452')['ok']);
    }

    public function testUpcBadCheckRejected(): void
    {
        $this->assertFalse(Barcode::validate('UPC', '036000291451')['ok']);
    }

    public function testCheckDigitComputation(): void
    {
        $this->assertSame(1, Barcode::checkDigit('400638133393'));   // EAN13 payload
        $this->assertSame(2, Barcode::checkDigit('03600029145'));    // UPC payload
    }

    public function testCustomAndCode128AcceptFreeText(): void
    {
        $this->assertTrue(Barcode::validate('CUSTOM', 'SKU-RED-M')['ok']);
        $this->assertTrue(Barcode::validate('CODE128', 'ABC-123/x')['ok']);
    }

    public function testCode39RejectsLowercaseSymbols(): void
    {
        $this->assertTrue(Barcode::validate('CODE39', 'ABC 123-X')['ok']);
        $this->assertFalse(Barcode::validate('CODE39', 'abc@123')['ok']);
    }

    public function testEmptyAndOverlongRejected(): void
    {
        $this->assertFalse(Barcode::validate('CUSTOM', '   ')['ok']);
        $this->assertFalse(Barcode::validate('CUSTOM', str_repeat('A', 65))['ok']);
    }

    public function testUnknownTypeFallsBackToCustom(): void
    {
        $this->assertTrue(Barcode::validate('BOGUS', 'anything')['ok']);
    }
}
