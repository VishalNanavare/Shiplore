<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Reports/ReportExportService.php';

use App\Libraries\Reports\ReportExportService;

/** X5 — async export engine: RFC-4180 CSV builder. */
final class ReportExportCsvTest extends TestCase
{
    public function testHeaderAndRows(): void
    {
        $csv = ReportExportService::toCsv(['a', 'b'], [['1', '2'], ['3', '4']]);

        $this->assertSame("a,b\r\n1,2\r\n3,4\r\n", $csv);
    }

    public function testEscapesQuotesCommasNewlines(): void
    {
        $csv = ReportExportService::toCsv(['name', 'note'], [['Fresh "Foods", Ltd', "line1\nline2"]]);

        $this->assertSame("name,note\r\n\"Fresh \"\"Foods\"\", Ltd\",\"line1\nline2\"\r\n", $csv);
    }

    public function testNullsBecomeEmptyCells(): void
    {
        $csv = ReportExportService::toCsv(['x', 'y'], [[null, 'ok']]);

        $this->assertSame("x,y\r\n,ok\r\n", $csv);
    }
}
