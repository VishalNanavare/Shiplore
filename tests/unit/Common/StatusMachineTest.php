<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Workflow/StatusMachine.php';

use App\Libraries\Workflow\StatusMachine;

/**
 * Status-transition guards (Batch 3 hardening) — illegal moves are rejected.
 * @see docs/architecture/41-SECURITY-PERFORMANCE.md
 */
final class StatusMachineTest extends TestCase
{
    public function testSubOrderForwardAllowed(): void
    {
        $this->assertTrue(StatusMachine::canSubOrder('confirmed', 'accepted'));
        $this->assertTrue(StatusMachine::canSubOrder('ready', 'out_for_delivery'));
    }

    public function testSubOrderBackwardRejected(): void
    {
        $this->assertFalse(StatusMachine::canSubOrder('delivered', 'confirmed'));
        $this->assertFalse(StatusMachine::canSubOrder('cancelled', 'accepted'));
    }

    public function testSamestateIsNoop(): void
    {
        $this->assertTrue(StatusMachine::canSubOrder('packed', 'packed'));
    }

    public function testDeliveryTransitions(): void
    {
        $this->assertTrue(StatusMachine::canDelivery('assigned', 'picked_up'));
        $this->assertTrue(StatusMachine::canDelivery('failed', 'assigned'));   // retry
        $this->assertFalse(StatusMachine::canDelivery('delivered', 'assigned'));
    }

    /**
     * M27: DeliveryRepository::FLOW (admin panel) and StatusMachine::DELIVERY
     * (rider app) disagreed on 5 transitions — whichever door a request came
     * through decided if it was legal. The fix reconciles DELIVERY to the union:
     * every one of the 5 previously one-door-only moves must now be legal from
     * both, and the phantom 'reassigned' state (a delivery_assignments.status
     * value, not a deliveries.status one) must be gone.
     */
    public function testDeliveryUnionIncludesTransitionsThatOnlyOneDoorAllowedBefore(): void
    {
        // Previously admin-only (DeliveryRepository::FLOW), now must also hold via StatusMachine.
        $this->assertTrue(StatusMachine::canDelivery('pending', 'out_for_delivery'));
        $this->assertTrue(StatusMachine::canDelivery('assigned', 'arrived'));
        // Previously rider-only (StatusMachine::DELIVERY), now must also hold via the union.
        $this->assertTrue(StatusMachine::canDelivery('picked_up', 'returned'));
        $this->assertTrue(StatusMachine::canDelivery('arrived', 'returned'));
    }

    /** The phantom 'reassigned' state described a delivery_assignments row, not a deliveries.status value. */
    public function testReassignedIsNotAValidDeliveryFromState(): void
    {
        $this->assertFalse(StatusMachine::canDelivery('reassigned', 'assigned'));
    }

    /** Truly illegal moves must stay rejected after reconciliation — the union widens, it doesn't legalise everything. */
    public function testDeliveryStillRejectsGenuinelyIllegalMoves(): void
    {
        $this->assertFalse(StatusMachine::canDelivery('delivered', 'pending'));
        $this->assertFalse(StatusMachine::canDelivery('returned', 'assigned'));
    }

    /** The admin "what can this move to next" UI hint must now read from StatusMachine, the single owner. */
    public function testAllowedNextDeliveryMirrorsTheReconciledMap(): void
    {
        $this->assertSame(['assigned', 'out_for_delivery', 'failed'], StatusMachine::allowedNextDelivery('pending'));
        $this->assertSame([], StatusMachine::allowedNextDelivery('delivered'));
    }

    public function testRefundTransitions(): void
    {
        $this->assertTrue(StatusMachine::canRefund('initiated', 'completed'));
        $this->assertFalse(StatusMachine::canRefund('completed', 'failed'));
    }

    public function testOrderTransitions(): void
    {
        $this->assertTrue(StatusMachine::canOrder('created', 'confirmed'));
        $this->assertFalse(StatusMachine::canOrder('completed', 'cancelled'));
    }

    public function testTransferTransitions(): void
    {
        $this->assertTrue(StatusMachine::canTransfer('requested', 'approved'));
        $this->assertTrue(StatusMachine::canTransfer('approved', 'dispatched'));
        $this->assertTrue(StatusMachine::canTransfer('approved', 'packed'));
        $this->assertTrue(StatusMachine::canTransfer('dispatched', 'received'));
        $this->assertTrue(StatusMachine::canTransfer('dispatched', 'partially_received'));
        $this->assertTrue(StatusMachine::canTransfer('partially_received', 'closed'));
        $this->assertTrue(StatusMachine::canTransfer('requested', 'cancelled'));
        // illegal
        $this->assertFalse(StatusMachine::canTransfer('requested', 'dispatched')); // must approve first
        $this->assertFalse(StatusMachine::canTransfer('dispatched', 'cancelled')); // shipped already
        $this->assertFalse(StatusMachine::canTransfer('received', 'approved'));
        $this->assertFalse(StatusMachine::canTransfer('cancelled', 'approved'));
    }

    public function testProductApprovalTransitions(): void
    {
        // the happy path
        $this->assertTrue(StatusMachine::canProduct('draft', 'submitted'));
        $this->assertTrue(StatusMachine::canProduct('submitted', 'approved'));
        $this->assertTrue(StatusMachine::canProduct('approved', 'published'));
        $this->assertTrue(StatusMachine::canProduct('published', 'unpublished'));
        $this->assertTrue(StatusMachine::canProduct('rejected', 'submitted'));      // resubmit
        $this->assertTrue(StatusMachine::canProduct('submitted', 'draft'));         // request changes
        // illegal jumps are rejected
        $this->assertFalse(StatusMachine::canProduct('draft', 'approved'));         // can't skip review
        $this->assertFalse(StatusMachine::canProduct('draft', 'published'));        // can't skip everything
        $this->assertFalse(StatusMachine::canProduct('published', 'draft'));
    }
}
