<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Audit M27 — StatusMachine must be the single owner of delivery transition
 * rules. DeliveryRepository used to carry its own private copy (`const FLOW`)
 * that disagreed with StatusMachine::DELIVERY in five places; the source of
 * truth must now be StatusMachine everywhere, including the admin "what can
 * this move to next" UI hint that used to read FLOW directly.
 */
final class DeliveryTransitionSourceTest extends CIUnitTestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

    public function testDeliveryRepositoryNoLongerDeclaresItsOwnFlow(): void
    {
        $src = $this->read('Models/DeliveryRepository.php');

        $this->assertStringNotContainsString(
            'const FLOW',
            $src,
            'DeliveryRepository must not carry a private transition table that can drift from StatusMachine again',
        );
        $this->assertStringContainsString(
            'StatusMachine::canDelivery(',
            $src,
            'updateStatus() must delegate to the single-owner StatusMachine',
        );
    }

    public function testDeliveryControllerReadsNextStatusesFromStatusMachine(): void
    {
        $src = $this->read('Controllers/Admin/DeliveryController.php');

        $this->assertStringNotContainsString(
            'DeliveryRepository::FLOW',
            $src,
            'the "next status" UI hint must not read the deleted FLOW constant',
        );
        $this->assertStringContainsString('StatusMachine::allowedNextDelivery(', $src);
    }
}
