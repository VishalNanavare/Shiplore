<?php

declare(strict_types=1);

namespace App\Libraries\Catalog;

/**
 * Barcode — pure validation + normalization for product barcodes. Verifies
 * symbology format and the GTIN check digit for EAN-13 / UPC-A / ISBN-13, and
 * applies light format rules for CODE128 / CODE39 / QR / CUSTOM. No DB →
 * unit-testable; ProductBarcodeRepository calls validate() before persisting.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md (product module — barcodes)
 */
final class Barcode
{
    public const TYPES = ['EAN13', 'UPC', 'CODE128', 'CODE39', 'QR', 'ISBN', 'CUSTOM'];

    /** @return array{ok:bool, message:string, value:string} normalized value on success */
    public static function validate(string $type, string $value): array
    {
        $type  = in_array($type, self::TYPES, true) ? $type : 'CUSTOM';
        $value = strtoupper(trim($value));
        if ($value === '') {
            return ['ok' => false, 'message' => 'Barcode value is required.', 'value' => ''];
        }
        if (mb_strlen($value) > 64) {
            return ['ok' => false, 'message' => 'Barcode is too long (max 64).', 'value' => ''];
        }

        return match ($type) {
            'EAN13', 'ISBN' => self::gtin($value, 13),
            'UPC'           => self::gtin($value, 12),
            'CODE39'        => self::pattern($value, '/^[A-Z0-9 .$\/+%-]+$/', 'CODE39 allows A-Z 0-9 and - . $ / + % space.'),
            default         => ['ok' => true, 'message' => '', 'value' => $value], // CODE128 / QR / CUSTOM
        };
    }

    /** Compute the GTIN check digit for the first $len-1 digits. */
    public static function checkDigit(string $digits): int
    {
        $sum = 0;
        // weight alternates 3,1 from the RIGHT of the payload (excludes the check digit)
        $rev = strrev($digits);
        for ($i = 0, $n = strlen($rev); $i < $n; $i++) {
            $d = (int) $rev[$i];
            $sum += ($i % 2 === 0) ? $d * 3 : $d;
        }

        return (10 - ($sum % 10)) % 10;
    }

    /** @return array{ok:bool,message:string,value:string} */
    private static function gtin(string $value, int $len): array
    {
        if (! preg_match('/^[0-9]{' . $len . '}$/', $value)) {
            return ['ok' => false, 'message' => "Must be exactly {$len} digits.", 'value' => ''];
        }
        $payload = substr($value, 0, $len - 1);
        $check   = (int) substr($value, $len - 1);
        if (self::checkDigit($payload) !== $check) {
            return ['ok' => false, 'message' => 'Invalid check digit.', 'value' => ''];
        }

        return ['ok' => true, 'message' => '', 'value' => $value];
    }

    /** @return array{ok:bool,message:string,value:string} */
    private static function pattern(string $value, string $regex, string $msg): array
    {
        return preg_match($regex, $value) ? ['ok' => true, 'message' => '', 'value' => $value] : ['ok' => false, 'message' => $msg, 'value' => ''];
    }
}
