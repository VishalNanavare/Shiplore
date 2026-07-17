<?php

declare(strict_types=1);

namespace App\Libraries\Tasks;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * CronSchedule — pure next-run calculator for standard 5-field cron expressions
 * (minute hour day-of-month month day-of-week) plus the common @macros. Powers
 * the `scheduled_tasks` runner (X0). No I/O; fully unit-tested.
 *
 * Field syntax: `*`, `n`, `a-b`, `*\/n`, `a-b/n`, comma lists. Day-of-month and
 * day-of-week follow classic cron OR semantics when BOTH are restricted.
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §8 C5
 */
final class CronSchedule
{
    private const MACROS = [
        '@hourly'       => '0 * * * *',
        '@daily'        => '0 0 * * *',
        '@midnight'     => '0 0 * * *',
        '@weekly'       => '0 0 * * 0',
        '@monthly'      => '0 0 1 * *',
        '@yearly'       => '0 0 1 1 *',
        '@annually'     => '0 0 1 1 *',
        '@every_minute' => '* * * * *',
    ];

    /** [min, max] bounds per field position. */
    private const BOUNDS = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 6]];

    /** Next matching minute STRICTLY AFTER $after (seconds truncated). */
    public function nextRunAt(string $expr, DateTimeImmutable $after): DateTimeImmutable
    {
        $f = $this->parse($expr);

        // truncate to the minute, then step forward — "strictly after"
        $t     = $after->setTime((int) $after->format('G'), (int) $after->format('i'))->modify('+1 minute');
        $limit = $after->modify('+5 years');

        while ($t <= $limit) {
            if (! in_array((int) $t->format('n'), $f['mon'], true)) {
                $t = $t->modify('first day of next month')->setTime(0, 0);
                continue;
            }
            if (! $this->dayMatches($t, $f)) {
                $t = $t->modify('+1 day')->setTime(0, 0);
                continue;
            }
            if (! in_array((int) $t->format('G'), $f['hour'], true)) {
                $t = $t->setTime((int) $t->format('G') + 1, 0); // rolls into next day at 24:00
                continue;
            }
            if (! in_array((int) $t->format('i'), $f['min'], true)) {
                $t = $t->modify('+1 minute');
                continue;
            }

            return $t;
        }

        throw new InvalidArgumentException("Cron '{$expr}' never matches within 5 years.");
    }

    /** Classic cron: if both DOM and DOW are restricted, match when EITHER does. */
    private function dayMatches(DateTimeImmutable $t, array $f): bool
    {
        $domOk = in_array((int) $t->format('j'), $f['dom'], true);
        $dowOk = in_array((int) $t->format('w'), $f['dow'], true);

        if (! $f['domStar'] && ! $f['dowStar']) {
            return $domOk || $dowOk;
        }

        return $domOk && $dowOk;
    }

    /** @return array{min:list<int>,hour:list<int>,dom:list<int>,mon:list<int>,dow:list<int>,domStar:bool,dowStar:bool} */
    private function parse(string $expr): array
    {
        $expr = trim($expr);
        $expr = self::MACROS[strtolower($expr)] ?? $expr;

        $parts = preg_split('/\s+/', $expr);
        if ($parts === false || count($parts) !== 5) {
            throw new InvalidArgumentException("Cron '{$expr}' must have 5 fields.");
        }

        $sets = [];
        foreach ($parts as $i => $field) {
            $sets[$i] = $this->parseField($field, self::BOUNDS[$i][0], self::BOUNDS[$i][1], $i === 4);
        }

        return [
            'min' => $sets[0], 'hour' => $sets[1], 'dom' => $sets[2], 'mon' => $sets[3], 'dow' => $sets[4],
            'domStar' => $parts[2] === '*', 'dowStar' => $parts[4] === '*',
        ];
    }

    /** @return list<int> sorted unique values the field allows */
    private function parseField(string $field, int $min, int $max, bool $isDow): array
    {
        $out = [];
        foreach (explode(',', $field) as $part) {
            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $stepStr] = explode('/', $part, 2);
                if (! ctype_digit($stepStr) || (int) $stepStr < 1) {
                    throw new InvalidArgumentException("Bad cron step '{$stepStr}'.");
                }
                $step = (int) $stepStr;
            }

            if ($part === '*' || $part === '') {
                [$lo, $hi] = [$min, $max];
            } elseif (str_contains($part, '-')) {
                [$a, $b] = explode('-', $part, 2);
                [$lo, $hi] = [$this->num($a, $isDow), $this->num($b, $isDow)];
            } else {
                $lo = $hi = $this->num($part, $isDow);
            }

            if ($lo < $min || $hi > $max || $lo > $hi) {
                throw new InvalidArgumentException("Cron value '{$part}' out of range {$min}-{$max}.");
            }
            for ($v = $lo; $v <= $hi; $v += $step) {
                $out[] = $v;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    private function num(string $s, bool $isDow): int
    {
        if (! ctype_digit($s)) {
            throw new InvalidArgumentException("Cron value '{$s}' is not numeric.");
        }
        $n = (int) $s;

        return ($isDow && $n === 7) ? 0 : $n; // accept 7 as Sunday
    }
}
