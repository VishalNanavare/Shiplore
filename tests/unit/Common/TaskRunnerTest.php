<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Tasks/CronSchedule.php';
require_once __DIR__ . '/../../../app/Libraries/Tasks/ScheduledTaskStore.php';
require_once __DIR__ . '/../../../app/Libraries/Tasks/TaskRunner.php';

use App\Libraries\Tasks\ScheduledTaskStore;
use App\Libraries\Tasks\TaskRunner;

/** X0 — scheduled-task runner: dispatch, isolation, bad-cron pause. */
final class TaskRunnerTest extends TestCase
{
    /** @param list<array<string,mixed>> $due */
    private function store(array $due): ScheduledTaskStore
    {
        return new class ($due) implements ScheduledTaskStore {
            public array $marked = [];

            public function __construct(private array $dueRows) {}

            public function due(string $nowSql): array
            {
                return $this->dueRows;
            }

            public function markRun(int $id, string $ranAtSql, ?string $nextRunAtSql): void
            {
                $this->marked[$id] = [$ranAtSql, $nextRunAtSql];
            }

            public function ensure(string $code, string $cronExpr, string $handler, array $payload = []): int
            {
                return 1;
            }
        };
    }

    public function testDispatchesHandlerAndAdvancesNextRun(): void
    {
        $store  = $this->store([['id' => 7, 'code' => 'demo', 'handler' => 'demo.run', 'cron_expr' => '*/10 * * * *']]);
        $hit    = 0;
        $runner = new TaskRunner($store);
        $runner->register('demo.run', static function (array $task) use (&$hit): void { $hit++; });

        $out = $runner->runDue(new DateTimeImmutable('2026-06-12 10:02:00'));

        $this->assertSame(1, $hit);
        $this->assertSame('ok', $out[0]['result']);
        $this->assertSame(['2026-06-12 10:02:00', '2026-06-12 10:10:00'], $store->marked[7]);
    }

    public function testUnknownHandlerSkipsButStillReschedules(): void
    {
        $store  = $this->store([['id' => 1, 'code' => 'x', 'handler' => 'nope', 'cron_expr' => '@hourly']]);
        $out    = (new TaskRunner($store))->runDue(new DateTimeImmutable('2026-06-12 10:30:00'));

        $this->assertSame('skipped:unknown_handler', $out[0]['result']);
        $this->assertSame('2026-06-12 11:00:00', $store->marked[1][1]);
    }

    public function testFailingHandlerDoesNotBlockOthers(): void
    {
        $store = $this->store([
            ['id' => 1, 'code' => 'boom', 'handler' => 'boom', 'cron_expr' => '@hourly'],
            ['id' => 2, 'code' => 'fine', 'handler' => 'fine', 'cron_expr' => '@hourly'],
        ]);
        $runner = new TaskRunner($store);
        $runner->register('boom', static function (): void { throw new RuntimeException('kaput'); });
        $ok = 0;
        $runner->register('fine', static function () use (&$ok): void { $ok++; });

        $out = $runner->runDue(new DateTimeImmutable('2026-06-12 10:00:00'));

        $this->assertStringStartsWith('error:kaput', $out[0]['result']);
        $this->assertSame('ok', $out[1]['result']);
        $this->assertSame(1, $ok);
        $this->assertCount(2, $store->marked);
    }

    public function testBadCronPausesTask(): void
    {
        $store = $this->store([['id' => 9, 'code' => 'bad', 'handler' => 'fine', 'cron_expr' => 'not a cron']]);
        $runner = new TaskRunner($store);
        $runner->register('fine', static fn () => null);

        $out = $runner->runDue(new DateTimeImmutable('2026-06-12 10:00:00'));

        $this->assertSame('ok|bad_cron', $out[0]['result']);
        $this->assertNull($store->marked[9][1]); // repo pauses on NULL next
    }
}
