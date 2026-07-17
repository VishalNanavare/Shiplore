<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Sync/ConflictResolver.php';
require_once __DIR__ . '/../../../app/Libraries/Sync/RetryPolicy.php';

use App\Libraries\Sync\ConflictResolver;
use App\Libraries\Sync\RetryPolicy;

/**
 * Sync engine core — optimistic-concurrency conflict resolution + retry backoff.
 * @see docs/architecture/35-SYNC-ENGINE.md
 */
final class SyncEngineCoreTest extends TestCase
{
    public function testNewRowApplies(): void
    {
        $d = (new ConflictResolver())->decide(1, null);
        $this->assertSame('apply', $d['action']);
    }

    public function testMatchingVersionApplies(): void
    {
        $d = (new ConflictResolver())->decide(4, 4);
        $this->assertSame('apply', $d['action']);
    }

    public function testClientAheadApplies(): void
    {
        $d = (new ConflictResolver())->decide(6, 4);
        $this->assertSame('apply', $d['action']);
    }

    public function testStaleClientConflictsServerWins(): void
    {
        $d = (new ConflictResolver())->decide(3, 5, 'server_wins');
        $this->assertSame('conflict', $d['action']);
        $this->assertSame('server_wins', $d['resolution']);
    }

    public function testStaleClientClientWinsApplies(): void
    {
        $d = (new ConflictResolver())->decide(3, 5, 'client_wins');
        $this->assertSame('apply', $d['action']);
        $this->assertSame('client_wins', $d['resolution']);
    }

    public function testRetryBackoffDoubles(): void
    {
        $p = new RetryPolicy();
        $this->assertSame(10, $p->nextDelaySeconds(1));
        $this->assertSame(20, $p->nextDelaySeconds(2));
        $this->assertSame(40, $p->nextDelaySeconds(3));
    }

    public function testRetryBackoffIsCapped(): void
    {
        $this->assertSame(3600, (new RetryPolicy())->nextDelaySeconds(20));
    }

    public function testExhaustionAtMax(): void
    {
        $p = new RetryPolicy();
        $this->assertFalse($p->isExhausted(4));
        $this->assertTrue($p->isExhausted(5));
    }
}
