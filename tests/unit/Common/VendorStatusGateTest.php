<?php

declare(strict_types=1);

use App\Libraries\VendorStatusGate;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * VendorStatusGate — Phase 3 of the vendor status/lifecycle build (phases 1-2 shipped:
 * schema fix + admin Activate/Deactivate, writing vendors.status only).
 *
 * The single decision point every enforcement site (phases 4-6: storefront, both apps,
 * vendor/staff login, rider login) calls into, so log-only vs. enforce is ONE switch,
 * not six independently-drifting ones. Nothing calls this yet — this phase is pure new
 * code with zero behaviour change anywhere.
 *
 * Mirrors the rollout mechanics WebAuthFilter::checkPrincipal() already established for
 * auth.enforcePrincipalType: env-backed flag, 'notice' level while log-only ("would
 * block"), 'warning' level once enforcing ("BLOCKED"), so an operator can grep one
 * tag and get an honest answer regardless of the flag's current position.
 */
final class VendorStatusGateTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic starting point — a previous test elsewhere in the suite must
        // not leave this flag set for this file.
        putenv('vendor.enforceStatusGate');
    }

    protected function tearDown(): void
    {
        putenv('vendor.enforceStatusGate');
        parent::tearDown();
    }

    // ------------------------------------------------------- pure predicates

    public function testAnActiveVendorIsOperational(): void
    {
        $this->assertTrue((new VendorStatusGate())->isVendorActive(['id' => 1, 'status' => 'active']));
    }

    /** @dataProvider inactiveVendorStatuses */
    public function testANonActiveVendorIsNotOperational(string $status): void
    {
        $this->assertFalse((new VendorStatusGate())->isVendorActive(['id' => 1, 'status' => $status]));
    }

    public static function inactiveVendorStatuses(): array
    {
        return [['draft'], ['submitted'], ['under_review'], ['approved'], ['suspended'], ['terminated'], ['rejected'], ['']];
    }

    public function testAMissingVendorRowIsNotOperational(): void
    {
        $this->assertFalse((new VendorStatusGate())->isVendorActive(null));
    }

    /** A shop needs BOTH its own status and its vendor's — either alone is not enough. */
    public function testAShopIsOperationalOnlyWhenBothItAndItsVendorAreActive(): void
    {
        $gate = new VendorStatusGate();

        $this->assertTrue($gate->isShopActive(['status' => 'active'], ['status' => 'active']));
        $this->assertFalse($gate->isShopActive(['status' => 'inactive'], ['status' => 'active']), 'shop itself off');
        $this->assertFalse($gate->isShopActive(['status' => 'closed_temp'], ['status' => 'active']), 'the vendor-side temporary-close state');
        $this->assertFalse($gate->isShopActive(['status' => 'active'], ['status' => 'suspended']), 'vendor off, shop otherwise fine');
        $this->assertFalse($gate->isShopActive(null, ['status' => 'active']));
        $this->assertFalse($gate->isShopActive(['status' => 'active'], null));
    }

    // ------------------------------------------------------- the staged decision

    public function testAnActiveVendorIsNeverBlockedRegardlessOfTheFlag(): void
    {
        $gate = new VendorStatusGate();

        $this->assertFalse($gate->shouldBlockForVendorStatus(['id' => 1, 'status' => 'active'], 'unit test'));
        putenv('vendor.enforceStatusGate=true');
        $this->assertFalse($gate->shouldBlockForVendorStatus(['id' => 1, 'status' => 'active'], 'unit test'));
    }

    public function testByDefaultAnInactiveVendorIsLoggedButNotBlocked(): void
    {
        $gate = new VendorStatusGate();

        $blocked = $gate->shouldBlockForVendorStatus(['id' => 7, 'status' => 'suspended'], 'unit test');

        $this->assertFalse($blocked, 'log-only is the default — nothing may be blocked before the operator opts in');
    }

    public function testWithTheFlagSetAnInactiveVendorIsBlocked(): void
    {
        putenv('vendor.enforceStatusGate=true');
        $gate = new VendorStatusGate();

        $this->assertTrue($gate->shouldBlockForVendorStatus(['id' => 7, 'status' => 'suspended'], 'unit test'));
    }

    /** A missing vendor row is treated as inactive, not as "nothing to check". */
    public function testAMissingVendorIsTreatedAsInactive(): void
    {
        putenv('vendor.enforceStatusGate=true');

        $this->assertTrue((new VendorStatusGate())->shouldBlockForVendorStatus(null, 'unit test'));
    }

    public function testIsEnforcingReflectsTheFlag(): void
    {
        $gate = new VendorStatusGate();
        $this->assertFalse($gate->isEnforcing());

        putenv('vendor.enforceStatusGate=true');
        $this->assertTrue($gate->isEnforcing());

        putenv('vendor.enforceStatusGate=false');
        $this->assertFalse($gate->isEnforcing(), "'false' the string must not parse as truthy");
    }

    /**
     * The context string reaches the log line — an operator grepping "vendor-status
     * gate" needs to see WHICH call site fired, not just that one did.
     */
    public function testTheContextLabelAppearsInTheLogLine(): void
    {
        $log = ROOTPATH . 'writable/logs/log-' . date('Y-m-d') . '.log';
        $before = is_file($log) ? filesize($log) : 0;

        (new VendorStatusGate())->shouldBlockForVendorStatus(['id' => 42, 'status' => 'suspended'], 'UNIQUE-MARKER-storefront-listing');

        $this->assertTrue(is_file($log), 'a log call must have produced a log file');
        $tail = substr((string) file_get_contents($log), $before);
        $this->assertStringContainsString('UNIQUE-MARKER-storefront-listing', $tail);
        $this->assertStringContainsString('would block', $tail, 'log-only must say so, not "BLOCKED"');
        // The actual PSR log LEVEL, not just the free-text tag above — CI4 writes it as
        // a literal prefix ("NOTICE - ..."). A mutant that swapped notice<->warning
        // while leaving the free-text tags alone survived until this was added.
        $this->assertStringContainsString('NOTICE -', $tail, 'log-only must use notice level, not warning');
    }

    public function testTheEnforcingLogLineSaysBlockedNotWouldBlock(): void
    {
        putenv('vendor.enforceStatusGate=true');
        $log = ROOTPATH . 'writable/logs/log-' . date('Y-m-d') . '.log';
        $before = is_file($log) ? filesize($log) : 0;

        (new VendorStatusGate())->shouldBlockForVendorStatus(['id' => 43, 'status' => 'suspended'], 'UNIQUE-MARKER-enforcing-path');

        $tail = substr((string) file_get_contents($log), $before);
        $this->assertStringContainsString('UNIQUE-MARKER-enforcing-path', $tail);
        $this->assertStringContainsString('BLOCKED', $tail);
        $this->assertStringContainsString('WARNING -', $tail, 'enforcing must use warning level, not notice');
    }

    // ------------------------------------------------------- the shop-level staged decision

    /**
     * shouldBlockForShopStatus() covers BOTH halves in one call — for call sites (a
     * direct shop page, the location-scoped nearby list) that need the combined answer
     * rather than composing isShopActive()/shouldBlockForVendorStatus() themselves.
     * Same flag, same log tags — one gate, not two.
     */
    public function testAFullyActiveShopIsNeverBlocked(): void
    {
        $gate = new VendorStatusGate();
        putenv('vendor.enforceStatusGate=true');

        $this->assertFalse($gate->shouldBlockForShopStatus(['id' => 1, 'status' => 'active'], ['id' => 9, 'status' => 'active'], 'unit test'));
    }

    public function testByDefaultAnInactiveShopIsLoggedButNotBlocked(): void
    {
        $gate = new VendorStatusGate();

        $this->assertFalse($gate->shouldBlockForShopStatus(['id' => 1, 'status' => 'inactive'], ['id' => 9, 'status' => 'active'], 'unit test'));
    }

    public function testWithTheFlagSetAnInactiveShopIsBlocked(): void
    {
        putenv('vendor.enforceStatusGate=true');

        $this->assertTrue((new VendorStatusGate())->shouldBlockForShopStatus(['id' => 1, 'status' => 'inactive'], ['id' => 9, 'status' => 'active'], 'unit test'));
    }

    /** The shop can be perfectly active and still blocked, purely on its vendor. */
    public function testAnActiveShopIsStillBlockedWhenItsVendorIsNot(): void
    {
        putenv('vendor.enforceStatusGate=true');

        $this->assertTrue((new VendorStatusGate())->shouldBlockForShopStatus(['id' => 1, 'status' => 'active'], ['id' => 9, 'status' => 'suspended'], 'unit test'));
    }

    public function testTheShopContextLabelAppearsInTheLogLine(): void
    {
        $log = ROOTPATH . 'writable/logs/log-' . date('Y-m-d') . '.log';
        $before = is_file($log) ? filesize($log) : 0;

        (new VendorStatusGate())->shouldBlockForShopStatus(['id' => 55, 'status' => 'inactive'], ['id' => 9, 'status' => 'active'], 'UNIQUE-MARKER-shop-page');

        $tail = substr((string) file_get_contents($log), $before);
        $this->assertStringContainsString('UNIQUE-MARKER-shop-page', $tail);
        $this->assertStringContainsString('shop #55', $tail, 'must identify WHICH shop, not just the vendor');
    }

    // ------------------------------------------------------- bulk filtering

    /**
     * filterByVendorStatus() is for LISTING call sites — nearby() can return up to 500
     * rows per call, the catalog far more. Logging per row there would flood the log
     * file on a routine storefront page view, so this logs ONCE per call, aggregated,
     * not once per excluded row.
     */
    public function testAnEmptyListReturnsEmptyAndLogsNothing(): void
    {
        $log = ROOTPATH . 'writable/logs/log-' . date('Y-m-d') . '.log';
        $before = is_file($log) ? filesize($log) : 0;

        $out = (new VendorStatusGate())->filterByVendorStatus([], static fn ($r) => null, 'UNIQUE-MARKER-empty');

        $this->assertSame([], $out);
        $tail = is_file($log) ? substr((string) file_get_contents($log), $before) : '';
        $this->assertStringNotContainsString('UNIQUE-MARKER-empty', $tail);
    }

    public function testAllRowsFromActiveVendorsPassThroughUnchangedAndLogNothing(): void
    {
        $log = ROOTPATH . 'writable/logs/log-' . date('Y-m-d') . '.log';
        $before = is_file($log) ? filesize($log) : 0;
        $rows = [['v' => 'active'], ['v' => 'active']];

        $out = (new VendorStatusGate())->filterByVendorStatus($rows, static fn ($r) => ['status' => $r['v']], 'UNIQUE-MARKER-all-active');

        $this->assertSame($rows, $out);
        $tail = is_file($log) ? substr((string) file_get_contents($log), $before) : '';
        $this->assertStringNotContainsString('UNIQUE-MARKER-all-active', $tail, 'nothing excluded, nothing to log');
    }

    public function testByDefaultInactiveVendorRowsAreKeptButLogged(): void
    {
        $rows = [['id' => 1, 'v' => 'active'], ['id' => 2, 'v' => 'suspended'], ['id' => 3, 'v' => 'suspended']];

        $out = (new VendorStatusGate())->filterByVendorStatus($rows, static fn ($r) => ['status' => $r['v']], 'unit test');

        $this->assertSame($rows, $out, 'log-only must not remove anything');
    }

    public function testWithTheFlagSetInactiveVendorRowsAreRemoved(): void
    {
        putenv('vendor.enforceStatusGate=true');
        $rows = [['id' => 1, 'v' => 'active'], ['id' => 2, 'v' => 'suspended'], ['id' => 3, 'v' => 'active']];

        $out = (new VendorStatusGate())->filterByVendorStatus($rows, static fn ($r) => ['status' => $r['v']], 'unit test');

        $this->assertSame([['id' => 1, 'v' => 'active'], ['id' => 3, 'v' => 'active']], array_values($out));
    }

    /** ONE log line for the whole batch, carrying the count — not one per excluded row. */
    public function testExcludedRowsProduceExactlyOneAggregateLogLine(): void
    {
        $log = ROOTPATH . 'writable/logs/log-' . date('Y-m-d') . '.log';
        $before = is_file($log) ? filesize($log) : 0;
        $rows = array_fill(0, 50, ['v' => 'suspended']);

        (new VendorStatusGate())->filterByVendorStatus($rows, static fn ($r) => ['status' => $r['v']], 'UNIQUE-MARKER-bulk-50');

        $tail = substr((string) file_get_contents($log), $before);
        $this->assertSame(1, substr_count($tail, 'UNIQUE-MARKER-bulk-50'), '50 excluded rows must produce exactly one log line, not 50');
        $this->assertStringContainsString('50', $tail, 'the count itself must be in the line');
    }

    /** An operational vendor logs nothing at all — the log must stay signal, not noise. */
    public function testAnOperationalVendorProducesNoLogLine(): void
    {
        $log = ROOTPATH . 'writable/logs/log-' . date('Y-m-d') . '.log';
        $before = is_file($log) ? filesize($log) : 0;

        (new VendorStatusGate())->shouldBlockForVendorStatus(['id' => 44, 'status' => 'active'], 'UNIQUE-MARKER-should-never-appear');

        $tail = is_file($log) ? substr((string) file_get_contents($log), $before) : '';
        $this->assertStringNotContainsString('UNIQUE-MARKER-should-never-appear', $tail);
    }
}
