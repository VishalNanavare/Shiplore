<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Settlement/SettlementService.php';

use App\Libraries\Settlement\SettlementService;

/** Settlement net-payable math (Batch 4). */
final class SettlementServiceTest extends TestCase
{
    public function testNetPayable(): void
    {
        // gross 10000 − commission 1200 − refunds 500 − fees 50 = 8250
        $this->assertSame('8250.00', SettlementService::netPayable(10000, 1200, 500, 50));
    }

    public function testNetPayableNoDeductions(): void
    {
        $this->assertSame('3918.88', SettlementService::netPayable(3918.88, 0, 0, 0));
    }

    public function testNetPayableRounds(): void
    {
        $this->assertSame('99.99', SettlementService::netPayable(100.001, 0.011, 0, 0));
    }
}
