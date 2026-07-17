<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Tasks/CronSchedule.php';

use App\Libraries\Tasks\CronSchedule;

/** X0 — cron next-run calculator for the scheduled_tasks runner. */
final class CronScheduleTest extends TestCase
{
    private CronSchedule $cron;

    protected function setUp(): void
    {
        $this->cron = new CronSchedule();
    }

    private function next(string $expr, string $after): string
    {
        return $this->cron->nextRunAt($expr, new DateTimeImmutable($after))->format('Y-m-d H:i');
    }

    public function testEveryFiveMinutes(): void
    {
        $this->assertSame('2026-06-12 10:05', $this->next('*/5 * * * *', '2026-06-12 10:02:30'));
    }

    public function testStrictlyAfterWhenAlreadyOnBoundary(): void
    {
        $this->assertSame('2026-06-12 10:10', $this->next('*/5 * * * *', '2026-06-12 10:05:00'));
    }

    public function testDailyAtThreeRollsToTomorrow(): void
    {
        $this->assertSame('2026-06-13 03:00', $this->next('0 3 * * *', '2026-06-12 04:00:00'));
    }

    public function testWeekday(): void
    {
        // 2026-06-12 is a Friday; next Monday is 2026-06-15
        $this->assertSame('2026-06-15 09:30', $this->next('30 9 * * 1', '2026-06-12 12:00:00'));
    }

    public function testSevenAcceptedAsSunday(): void
    {
        // next Sunday after Fri 2026-06-12 is 2026-06-14
        $this->assertSame('2026-06-14 00:00', $this->next('0 0 * * 7', '2026-06-12 12:00:00'));
    }

    public function testMonthlyFirst(): void
    {
        $this->assertSame('2026-07-01 00:00', $this->next('0 0 1 * *', '2026-06-15 08:00:00'));
    }

    public function testYearlyJumpsMonths(): void
    {
        $this->assertSame('2027-01-01 14:15', $this->next('15 14 1 1 *', '2026-02-02 00:00:00'));
    }

    public function testDomDowOrSemantics(): void
    {
        // 13th OR Friday: from Wed 2026-06-10, the first match is Fri 2026-06-12
        $this->assertSame('2026-06-12 00:00', $this->next('0 0 13 * 5', '2026-06-10 12:00:00'));
        // ...and from Sat 2026-06-13 01:00 (the 13th, but past 00:00) → next is Fri 2026-06-19? No: 13th matched at 00:00 already past; next DOM/DOW match is Fri 19th
        $this->assertSame('2026-06-19 00:00', $this->next('0 0 13 * 5', '2026-06-13 01:00:00'));
    }

    public function testMacros(): void
    {
        $this->assertSame('2026-06-12 11:00', $this->next('@hourly', '2026-06-12 10:59:59'));
        $this->assertSame('2026-06-13 00:00', $this->next('@daily', '2026-06-12 10:00:00'));
        $this->assertSame('2026-07-01 00:00', $this->next('@monthly', '2026-06-12 10:00:00'));
    }

    public function testRangesAndLists(): void
    {
        $this->assertSame('2026-06-12 10:45', $this->next('15,45 * * * *', '2026-06-12 10:20:00'));
        $this->assertSame('2026-06-12 18:00', $this->next('0 9-18 * * *', '2026-06-12 17:30:00'));
        $this->assertSame('2026-06-13 09:00', $this->next('0 9-18 * * *', '2026-06-12 18:30:00'));
    }

    public function testRejectsMalformed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cron->nextRunAt('99 * * * *', new DateTimeImmutable('2026-06-12'));
    }

    public function testRejectsWrongFieldCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cron->nextRunAt('* * *', new DateTimeImmutable('2026-06-12'));
    }
}
