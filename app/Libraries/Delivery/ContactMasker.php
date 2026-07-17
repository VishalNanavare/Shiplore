<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

/**
 * ContactMasker — masks a customer phone number shown to riders (privacy). Keeps
 * the first two and last two digits; masks the middle. Pure.
 *
 * @see docs/architecture/36-DELIVERY-OPERATIONS.md
 */
final class ContactMasker
{
    public static function mask(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        $len    = strlen($digits);
        if ($len === 0) {
            return '';
        }
        if ($len <= 4) {
            return $digits[0] . str_repeat('X', $len - 2) . $digits[$len - 1];
        }

        return substr($digits, 0, 2) . str_repeat('X', $len - 4) . substr($digits, -2);
    }
}
