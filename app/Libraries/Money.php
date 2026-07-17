<?php

declare(strict_types=1);

namespace App\Libraries;

use InvalidArgumentException;

/**
 * Money — immutable, integer-backed monetary value (scale 4, i.e. value * 10_000),
 * matching DECIMAL(15,4) at rest. Never uses float, so no drift. Half-up rounding.
 *
 * @see docs/database/00-DATABASE-DESIGN.md §1.3 (money = DECIMAL(15,4), never float)
 */
final class Money
{
    private const SCALE  = 4;
    private const FACTOR = 10000; // 10 ** SCALE

    private function __construct(private readonly int $units) {}

    /** Build from an integer (rupees) or a decimal string ("47.6190"). */
    public static function of(int|string $value): self
    {
        return new self(self::parseUnits($value));
    }

    private static function fromUnits(int $units): self
    {
        return new self($units);
    }

    private static function parseUnits(int|string $value): int
    {
        if (is_int($value)) {
            return $value * self::FACTOR;
        }

        $value = trim($value);
        if ($value === '' || ! preg_match('/^[+-]?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Invalid money value: '{$value}'");
        }

        $sign = 1;
        if ($value[0] === '+' || $value[0] === '-') {
            $sign  = $value[0] === '-' ? -1 : 1;
            $value = substr($value, 1);
        }

        [$whole, $frac] = array_pad(explode('.', $value, 2), 2, '');
        $frac = substr(str_pad($frac, self::SCALE, '0'), 0, self::SCALE);

        return $sign * (((int) $whole) * self::FACTOR + (int) $frac);
    }

    /** Half-up integer division that preserves sign. */
    private static function roundDiv(int $a, int $b): int
    {
        $sign = ($a < 0) ? -1 : 1;
        $a    = abs($a);
        $q    = intdiv($a, $b);
        if (($a % $b) * 2 >= $b) {
            $q++;
        }

        return $sign * $q;
    }

    public function add(self $other): self
    {
        return self::fromUnits($this->units + $other->units);
    }

    public function sub(self $other): self
    {
        return self::fromUnits($this->units - $other->units);
    }

    /** Multiply by a decimal quantity ("2.5", "0.250"); result rounded half-up to scale 4. */
    public function mulQty(string $qty): self
    {
        $qtyUnits = self::parseUnits($qty); // scale 4
        return self::fromUnits(self::roundDiv($this->units * $qtyUnits, self::FACTOR));
    }

    /** Round to $dp decimal places (0..4), half-up, returned at scale 4. */
    public function roundTo(int $dp): self
    {
        if ($dp < 0 || $dp > self::SCALE) {
            throw new InvalidArgumentException("Decimals out of range: {$dp}");
        }
        $step = 10 ** (self::SCALE - $dp);

        return self::fromUnits(self::roundDiv($this->units, $step) * $step);
    }

    public function equals(self $other): bool
    {
        return $this->units === $other->units;
    }

    /** Canonical 4-decimal string ("47.6190"). */
    public function amount(): string
    {
        $sign  = $this->units < 0 ? '-' : '';
        $u     = abs($this->units);
        $whole = intdiv($u, self::FACTOR);
        $frac  = $u % self::FACTOR;

        return sprintf('%s%d.%04d', $sign, $whole, $frac);
    }

    public function __toString(): string
    {
        return $this->amount();
    }
}
