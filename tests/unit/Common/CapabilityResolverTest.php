<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/PolicyEngine.php';
require_once __DIR__ . '/../../../app/Libraries/CapabilityResolver.php';

use App\Libraries\CapabilityResolver;
use App\Libraries\PolicyEngine;

/**
 * Phase 6 — builds the PolicyEngine context (actor_id, permissions, scopes,
 * attributes) from an actor's role assignments. DB-agnostic: assignments are
 * passed in (repository feeds them from user_roles/role_permissions/staff).
 */
final class CapabilityResolverTest extends TestCase
{
    public function testUnionsAndDedupsPermissionsSorted(): void
    {
        $assignments = [
            ['permissions' => ['order.view.own', 'pos.sell'], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []],
            ['permissions' => ['pos.sell', 'inventory.view'], 'scope_type' => 'shop', 'scope_id' => 10, 'attributes' => []],
        ];
        $ctx = (new CapabilityResolver())->resolve(3, $assignments);

        $this->assertSame(3, $ctx['actor_id']);
        $this->assertSame(['inventory.view', 'order.view.own', 'pos.sell'], $ctx['permissions']);
        $this->assertEqualsCanonicalizing(
            [['type' => 'vendor', 'id' => 1], ['type' => 'shop', 'id' => 10]],
            $ctx['scopes']
        );
    }

    public function testMergesAttributesTakingMaxNumericCap(): void
    {
        $assignments = [
            ['permissions' => [], 'scope_type' => 'shop', 'scope_id' => 10, 'attributes' => ['discount_cap' => 5]],
            ['permissions' => [], 'scope_type' => 'shop', 'scope_id' => 10, 'attributes' => ['discount_cap' => 10, 'refund_cap' => 2000]],
        ];
        $ctx = (new CapabilityResolver())->resolve(3, $assignments);

        $this->assertSame(10, $ctx['attributes']['discount_cap']);
        $this->assertSame(2000, $ctx['attributes']['refund_cap']);
        $this->assertCount(1, $ctx['scopes']); // same shop scope deduped
    }

    public function testEmptyAssignments(): void
    {
        $ctx = (new CapabilityResolver())->resolve(9, []);
        $this->assertSame(9, $ctx['actor_id']);
        $this->assertSame([], $ctx['permissions']);
        $this->assertSame([], $ctx['scopes']);
    }

    public function testComposesWithPolicyEngineForTenantIsolation(): void
    {
        $ctx = (new CapabilityResolver())->resolve(2, [
            ['permissions' => ['order.view.own'], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []],
        ]);
        $pe = new PolicyEngine();

        $this->assertTrue($pe->authorize($ctx, 'order.view.own', ['vendor_id' => 1]));
        $this->assertFalse($pe->authorize($ctx, 'order.view.own', ['vendor_id' => 2]));
    }
}
