<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Audit/AuditWriter.php';

use App\Libraries\Audit\AuditWriter;

/** X1 — tamper-evident hash chain math (pure part of the audit writer). */
final class AuditWriterTest extends TestCase
{
    public function testHashIsDeterministicAndKeyOrderInsensitive(): void
    {
        $a = AuditWriter::hashRow('prev', ['action' => 'x', 'entity_type' => 'y', 'entity_id' => 1]);
        $b = AuditWriter::hashRow('prev', ['entity_id' => 1, 'entity_type' => 'y', 'action' => 'x']);

        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a));
    }

    public function testChainLinksOnPrevHash(): void
    {
        $row = ['action' => 'x', 'entity_type' => 'y'];

        $this->assertNotSame(AuditWriter::hashRow(null, $row), AuditWriter::hashRow('other', $row));
    }

    public function testHashIgnoresExistingHashColumns(): void
    {
        $clean = AuditWriter::hashRow('p', ['action' => 'x']);
        $dirty = AuditWriter::hashRow('p', ['action' => 'x', 'prev_hash' => 'zzz', 'row_hash' => 'zzz']);

        $this->assertSame($clean, $dirty);
    }

    public function testAnyFieldChangeChangesHash(): void
    {
        $base = AuditWriter::hashRow('p', ['action' => 'x', 'reason' => 'a']);
        $mut  = AuditWriter::hashRow('p', ['action' => 'x', 'reason' => 'b']);

        $this->assertNotSame($base, $mut);
    }
}
