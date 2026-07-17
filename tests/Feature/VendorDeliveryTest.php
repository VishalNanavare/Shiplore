<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Phase 12 — vendor delivery operations: branch-scoped list (SLA + masked
 * contact), manual/auto/reassign, mark-failed. Repos mocked; tenant resolved
 * from the session vendor.
 */
final class VendorDeliveryTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => [], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]];
            }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $uid): ?array { return ['id' => 1, 'display_name' => 'Sole Mate']; }
            public function findStaffVendor(int $uid): ?array { return null; }
            public function shopIdsForVendor(int $v): array { return [1, 2]; }
            public function shopIdsForStaff(int $vs): array { return [1]; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 1, 'name' => 'Andheri'], ['id' => 2, 'name' => 'Bandra']]; }
        });
        $this->repo = new class {
            public bool $assigned = false;
            public bool $failed = false;
            public ?int $auto = 7;
            public function deliveries(int $v, ?string $s = null, ?int $shop = null): array { return [['id' => 1, 'order_no' => 'ORD-1', 'sub_order_no' => 'ORD-1-1', 'shop' => 'Andheri', 'shop_id' => 1, 'mode' => 'pool', 'status' => 'assigned', 'eta_at' => '2026-06-10 12:00', 'sla_status' => 'at_risk', 'rider' => 'Ravi', 'customer' => 'Aarav', 'customer_phone_masked' => '98XXXXXX78', 'cod_amount' => '999.00', 'delivery_fee' => '40.00']]; }
            public function reports(int $v): array { return ['total' => 1, 'by_status' => ['assigned' => 1], 'sla_breaches' => 0, 'cod_due' => '999.00']; }
            public function find(int $id, int $v): ?array { return $id === 1 ? ['id' => 1, 'order_no' => 'ORD-1', 'sub_order_no' => 'ORD-1-1', 'shop' => 'Andheri', 'mode' => 'pool', 'status' => 'assigned', 'eta_at' => '2026-06-10 12:00', 'sla_status' => 'at_risk', 'rider' => 'Ravi', 'customer' => 'Aarav', 'customer_phone_masked' => '98XXXXXX78', 'cod_amount' => '999.00'] : null; }
            public function riders(int $v): array { return [['user_id' => 5, 'name' => 'Ravi', 'availability' => 'available', 'active' => 1, 'max' => 5, 'lat' => 19.1, 'lng' => 72.8]]; }
            public function history(int $id, int $v): array { return []; }
            public function assign(int $id, int $r, int $v, ?int $a, string $m = 'pool', ?string $n = null): bool { $this->assigned = true; return true; }
            public function autoAssign(int $id, int $v, ?int $a): ?int { return $this->auto; }
            public function reassign(int $id, int $r, int $v, string $reason, ?int $a): bool { return true; }
            public function markFailed(int $id, int $v, string $reason, ?int $a): bool { $this->failed = true; return true; }
        };
        Services::injectMock('vendorDeliveryRepository', $this->repo);
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 5, 'user_name' => 'Vendor', 'principal_type' => 'vendor'];
    }

    private function postSess(): array
    {
        return service('session')->get() + $this->sess();
    }

    public function testListRendersWithSlaAndMaskedContact(): void
    {
        $r = $this->withSession($this->sess())->get('vendor/deliveries');
        $r->assertStatus(200);
        $body = (string) $r->getBody();
        $this->assertStringContainsString('98XXXXXX78', $body);
        $this->assertStringContainsString('at risk', $body);
    }

    public function testDetailRenders(): void
    {
        $this->withSession($this->sess())->get('vendor/deliveries/1')->assertStatus(200);
    }

    public function testManualAssign(): void
    {
        $r = $this->withSession($this->postSess())->post('vendor/deliveries/1/assign', [csrf_token() => csrf_hash(), 'rider_user_id' => '5']);
        $r->assertRedirect();
        $this->assertTrue($this->repo->assigned);
    }

    public function testAutoAssign(): void
    {
        $r = $this->withSession($this->postSess())->post('vendor/deliveries/1/auto-assign', [csrf_token() => csrf_hash()]);
        $r->assertRedirect();
        $this->assertStringContainsString('vendor/deliveries/1', $r->getRedirectUrl());
    }

    public function testAutoAssignNoRider(): void
    {
        $this->repo->auto = null;
        $r = $this->withSession($this->postSess())->post('vendor/deliveries/1/auto-assign', [csrf_token() => csrf_hash()]);
        $r->assertRedirect(); // flashes "no available rider"
    }

    public function testMarkFailed(): void
    {
        $r = $this->withSession($this->postSess())->post('vendor/deliveries/1/fail', [csrf_token() => csrf_hash(), 'reason' => 'customer_unreachable']);
        $r->assertRedirect();
        $this->assertTrue($this->repo->failed);
    }

    public function testForeignVendorBlocked(): void
    {
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $uid): ?array { return null; }
            public function findStaffVendor(int $uid): ?array { return null; }
        });
        $this->withSession($this->sess())->get('vendor/deliveries')->assertRedirect();
    }
}
