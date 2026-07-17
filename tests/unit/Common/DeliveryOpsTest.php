<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Store/LocationService.php';
require_once __DIR__ . '/../../../app/Libraries/Delivery/DeliveryAssignmentService.php';
require_once __DIR__ . '/../../../app/Libraries/Delivery/ContactMasker.php';
require_once __DIR__ . '/../../../app/Libraries/Delivery/SlaTracker.php';

use App\Libraries\Delivery\ContactMasker;
use App\Libraries\Delivery\DeliveryAssignmentService;
use App\Libraries\Delivery\SlaTracker;

/**
 * Delivery operations core — auto-assignment scoring, contact masking, SLA.
 * @see docs/architecture/36-DELIVERY-OPERATIONS.md
 */
final class DeliveryOpsTest extends TestCase
{
    private function rider(array $o): array
    {
        return array_merge(['user_id' => 1, 'availability' => 'available', 'active' => 0, 'max' => 5, 'lat' => 19.12, 'lng' => 72.85], $o);
    }

    public function testUnavailableRiderIneligible(): void
    {
        $r = (new DeliveryAssignmentService())->score($this->rider(['availability' => 'offline']), 19.12, 72.85);
        $this->assertFalse($r['eligible']);
    }

    public function testAtCapacityIneligible(): void
    {
        $r = (new DeliveryAssignmentService())->score($this->rider(['active' => 5, 'max' => 5]), 19.12, 72.85);
        $this->assertFalse($r['eligible']);
    }

    public function testEligibleRiderScored(): void
    {
        $r = (new DeliveryAssignmentService())->score($this->rider([]), 19.12, 72.85);
        $this->assertTrue($r['eligible']);
        $this->assertLessThan(1.0, $r['distance_km']);
    }

    public function testPickBestChoosesNearestEligible(): void
    {
        $svc   = new DeliveryAssignmentService();
        $near  = $this->rider(['user_id' => 7, 'lat' => 19.121, 'lng' => 72.851]);
        $far   = $this->rider(['user_id' => 8, 'lat' => 19.30, 'lng' => 73.10]);
        $busy  = $this->rider(['user_id' => 9, 'lat' => 19.12, 'lng' => 72.85, 'active' => 5, 'max' => 5]);
        $best  = $svc->pickBest([$far, $busy, $near], 19.12, 72.85);
        $this->assertSame(7, $best['user_id']);
    }

    public function testPickBestReturnsNullWhenNoneEligible(): void
    {
        $svc  = new DeliveryAssignmentService();
        $busy = $this->rider(['active' => 5, 'max' => 5]);
        $this->assertNull($svc->pickBest([$busy], 19.12, 72.85));
    }

    public function testContactMasking(): void
    {
        $this->assertSame('98XXXXXX78', ContactMasker::mask('9812345678'));
        $this->assertSame('', ContactMasker::mask(''));
        $this->assertSame('1XX4', ContactMasker::mask('1234'));
    }

    public function testSlaOnTimeAtRiskBreached(): void
    {
        $base = 1_700_000_000; // fixed reference
        $this->assertSame('on_time', SlaTracker::status(date('Y-m-d H:i:s', $base + 3600), null, $base));
        $this->assertSame('at_risk', SlaTracker::status(date('Y-m-d H:i:s', $base + 300), null, $base));
        $this->assertSame('breached', SlaTracker::status(date('Y-m-d H:i:s', $base - 60), null, $base));
    }

    public function testSlaDeliveredComparesToDeliveredAt(): void
    {
        $base = 1_700_000_000;
        $eta  = date('Y-m-d H:i:s', $base + 600);
        $this->assertSame('on_time', SlaTracker::status($eta, date('Y-m-d H:i:s', $base + 300), $base));
        $this->assertSame('breached', SlaTracker::status($eta, date('Y-m-d H:i:s', $base + 900), $base));
    }
}
