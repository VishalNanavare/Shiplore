<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The manufacturer panel must be isolated from the vendor panel, in both directions.
 *
 * Manufacturers and vendors share the `vendors` table, discriminated by `party_type`.
 * That makes tenant resolution the whole ballgame: if the manufacturer repositories
 * forgot the party_type constraint, a vendor owner reaching /manufacturer/* would
 * resolve a real tenant and be let straight in — and vice versa.
 *
 * The route group's `webAuth:manufacturer` pin is NOT sufficient on its own: it is
 * log-only until auth.enforcePrincipalType is enabled, so these repository-level
 * constraints are the actual gate.
 */
final class ManufacturerPanelIsolationTest extends CIUnitTestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

    /** Every tenant lookup must be constrained by party_type. */
    public function testAccountRepositoryConstrainsPartyType(): void
    {
        $src = $this->read('Models/ManufacturerAccountRepository.php');

        // Both entry points — owner and staff — must carry the constraint.
        $this->assertMatchesRegularExpression(
            "/findByOwnerUserId.*?where\('party_type', self::PARTY_TYPE\)/s",
            $src,
            'findByOwnerUserId() must not resolve a plain vendor as a manufacturer',
        );
        $this->assertMatchesRegularExpression(
            "/findStaffManufacturer.*?where\('v\.party_type', self::PARTY_TYPE\)/s",
            $src,
            'findStaffManufacturer() must not resolve vendor staff as manufacturer staff',
        );
        $this->assertStringContainsString("PARTY_TYPE = 'manufacturer'", $src);
    }

    /**
     * UPDATE (audit M11) — this used to assert the opposite: that
     * VendorAccountRepository stayed untouched by party_type, on the reasoning that
     * the manufacturer work was a separate, one-directional addition. That reasoning
     * was the bug: it left the guard one-directional, so a manufacturer owner
     * reaching /vendor/* resolved their own `vendors` row and passed
     * requireVendor(). The "separate, deliberate change" this test's old message
     * asked for is exactly what M11 is — both directions must now hold.
     */
    public function testVendorAccountRepositoryAlsoConstrainsPartyType(): void
    {
        $src = $this->read('Models/VendorAccountRepository.php');

        $this->assertStringContainsString("PARTY_TYPE = 'vendor'", $src);
        $this->assertMatchesRegularExpression(
            "/findByOwnerUserId.*?where\('party_type', self::PARTY_TYPE\)/s",
            $src,
            'findByOwnerUserId() must not resolve a manufacturer as a plain vendor',
        );
        $this->assertMatchesRegularExpression(
            "/findStaffVendor.*?where\('v\.party_type', self::PARTY_TYPE\)/s",
            $src,
            'findStaffVendor() must not resolve manufacturer staff as vendor staff',
        );
    }

    /**
     * Unit access must be checked per action, not just at login. A store keeper assigned
     * to unit A must be blocked from unit B even though both belong to their employer.
     */
    public function testEveryUnitIdActionChecksUnitAccess(): void
    {
        $src = $this->read('Controllers/Manufacturer/UnitController.php');

        foreach (['edit', 'update', 'toggle'] as $method) {
            $body = $this->methodBody($src, $method);
            $this->assertNotSame('', $body, "UnitController::{$method}() not found");
            $this->assertStringContainsString(
                'requireMshopAccess',
                $body,
                "UnitController::{$method}() takes a unit id but does not call requireMshopAccess()",
            );
        }
    }

    /**
     * Every controller in the panel directory, discovered by enumeration.
     *
     * This used to be a hand-written list of four class names, and that is precisely
     * how PurchaseOrderController once shipped with no coverage at all: it was added to
     * the panel and simply never added to the list, so the sweep silently skipped it.
     * Enumerating the directory means a controller cannot opt out of these checks by
     * being forgotten — a new file is covered the moment it exists.
     *
     * @return list<array{0:string,1:string}> [class name, source]
     */
    private function panelControllers(): array
    {
        $files = glob(APPPATH . 'Controllers/Manufacturer/*.php') ?: [];
        $this->assertNotEmpty($files, 'no manufacturer controllers found — is the path right?');

        $out = [];
        foreach ($files as $path) {
            $name = basename($path, '.php');
            if ($name === 'BaseManufacturerController') {
                continue; // abstract base: it DEFINES the guards rather than calling them
            }
            $out[] = [$name, (string) file_get_contents($path)];
        }

        $this->assertNotEmpty($out, 'only the base controller was found');

        return $out;
    }

    /** Nothing in the panel may be reachable without resolving a manufacturer first. */
    public function testEveryPublicControllerMethodRequiresAManufacturer(): void
    {
        foreach ($this->panelControllers() as [$ctrl, $src]) {
            preg_match_all('/public function (\w+)\s*\(/', $src, $m);

            // A controller whose endpoints come from a shared trait (ProductLookups)
            // declares no public methods of its own. That is legitimate, but it must
            // still establish the tenant somewhere in this file — otherwise "no public
            // methods" would be an easy way to opt out of every check below.
            $this->assertStringContainsString(
                'requireManufacturer',
                $src,
                "Manufacturer\\{$ctrl} never mentions requireManufacturer(); if its endpoints "
                . 'come from a trait, the guard hook in this file must call it',
            );

            foreach ($m[1] as $method) {
                if ($method === '__construct' || $method === 'initController') {
                    continue;
                }
                $body = $this->methodBody($src, $method);
                $this->assertStringContainsString(
                    'requireManufacturer',
                    $body,
                    "Manufacturer\\{$ctrl}::{$method}() does not call requireManufacturer()",
                );
            }
        }
    }

    /**
     * Any action taking a unit id must additionally check unit access — owning the
     * manufacturer is not enough, a store keeper assigned to unit A must be blocked
     * from unit B. Enumerated for the same reason as the sweep above.
     *
     * Detection is deliberately narrow: a parameter literally named $mshopId. A method
     * that takes a unit id under some other name will not be caught here, so the
     * convention is the safeguard and is worth keeping uniform.
     */
    public function testEveryMshopIdActionChecksUnitAccess(): void
    {
        $checked = 0;

        foreach ($this->panelControllers() as [$ctrl, $src]) {
            preg_match_all('/public function (\w+)\s*\(([^)]*)\)/', $src, $m, PREG_SET_ORDER);

            foreach ($m as [$whole, $method, $params]) {
                if (! str_contains($params, '$mshopId')) {
                    continue;
                }
                $body = $this->methodBody($src, $method);
                $this->assertStringContainsString(
                    'requireMshopAccess',
                    $body,
                    "Manufacturer\\{$ctrl}::{$method}() takes a \$mshopId but never calls requireMshopAccess()",
                );
                $checked++;
            }
        }

        // Guard against the sweep silently matching nothing if the convention changes.
        $this->assertGreaterThan(0, $checked, 'no $mshopId actions found — has the parameter naming convention changed?');
    }

    /** The route group must be pinned, and must not accidentally reuse the vendor pin. */
    public function testRouteGroupIsPinnedToManufacturer(): void
    {
        $routes = $this->read('Config/Routes.php');

        $this->assertStringContainsString(
            "\$routes->group('manufacturer', ['filter' => 'webAuth:manufacturer', 'subdomain' => ['manufacturer', 'mshop']]",
            $routes,
            'the manufacturer group must be pinned to the manufacturer principal AND its own subdomain',
        );
    }

    /**
     * REVERSED, deliberately. This asserted that `mshops` carried no delivery columns,
     * so that "a manufacturer cannot set a delivery range" was enforced by schema
     * rather than by a rule someone could forget. The operator subsequently asked for
     * full parity with the vendor panel INCLUDING delivery, so 75_manufacturer_delivery
     * .sql adds them.
     *
     * The test is rewritten rather than deleted so the reversal is explicit in the
     * history, and it still holds the shape of the change: 70 must remain untouched
     * (a rebuilt database replays it), and the columns must arrive in 75 under the
     * same names `shops` uses, so any shared serviceability code reads both alike.
     */
    public function testDeliveryColumnsArriveIn75AndNotByEditing70(): void
    {
        $sql70 = (string) file_get_contents(ROOTPATH . 'database/sql/70_manufacturer.sql');
        $start = strpos($sql70, 'CREATE TABLE IF NOT EXISTS `mshops`');
        $this->assertNotFalse($start, 'the mshops table definition is missing');
        $table = substr($sql70, $start, (int) strpos($sql70, 'ENGINE=InnoDB', $start) - $start);

        foreach (['delivery_radius_km', 'pickup_enabled', 'prep_time_min', 'delivery_fee'] as $col) {
            $this->assertStringNotContainsString(
                $col,
                $table,
                "70_manufacturer.sql's mshops definition must stay as it shipped — {$col} belongs in 75",
            );
        }

        $sql75 = (string) file_get_contents(ROOTPATH . 'database/sql/75_manufacturer_delivery.sql');
        foreach (['delivery_enabled', 'delivery_radius_km', 'pickup_enabled', 'prep_time_min',
            'min_order_value', 'delivery_fee', 'free_delivery_above'] as $col) {
            $this->assertStringContainsString("'{$col}'", $sql75, "75 must add the {$col} column to mshops");
        }

        // Off by default: enabling delivery is a per-unit decision, not something a
        // migration switches on for every existing manufacturer.
        $this->assertStringContainsString(
            "'delivery_enabled', \"TINYINT(1) NOT NULL DEFAULT 0",
            $sql75,
            'delivery must default to OFF for existing units',
        );
    }

    /**
     * Manufacturer deliveries must NOT reuse the consumer `deliveries` table.
     *
     * deliveries.sub_order_id is NOT NULL with an FK to `sub_orders`, and a
     * manufacturer's orders live in mfg_purchase_orders — relaxing that FK would put a
     * B2B flow inside the live consumer checkout path, which is the same reasoning
     * 71_monline_b2b.sql gives for not reusing `orders`.
     */
    public function testManufacturerDeliveriesUseTheirOwnTable(): void
    {
        $sql75 = (string) file_get_contents(ROOTPATH . 'database/sql/75_manufacturer_delivery.sql');

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `mfg_deliveries`', $sql75);
        $this->assertStringContainsString('REFERENCES `mfg_purchase_orders`', $sql75, 'a manufacturer delivery hangs off a PO, not a sub-order');
        $this->assertStringNotContainsString('ALTER TABLE `deliveries`', $sql75, 'the consumer delivery table must not be touched');
        $this->assertStringNotContainsString('REFERENCES `sub_orders`', $sql75);
    }

    /**
     * The SIGNUP form still collects no delivery range.
     *
     * This previously asserted that no manufacturer screen anywhere offered delivery
     * fields. Units can now be made deliverable (see above), but registration is not
     * where that belongs: a manufacturer signing up has no units yet, and asking for a
     * delivery radius before the factory exists is a question about nothing. The
     * serviceability fields live on the unit edit screen, which is per-unit and
     * permission-gated.
     *
     * Assertions are on the posted FIELD, not the bare word — the file's docblock names
     * both while explaining the split, and that is worth keeping.
     */
    public function testFactoryLocationPartialStillHasNoDeliveryFields(): void
    {
        $partial = $this->read('Views/partials/_factory_location.php');

        $this->assertStringNotContainsString('name="delivery_radius"', $partial);
        $this->assertStringNotContainsString('name="delivery_enabled"', $partial);
        $this->assertStringNotContainsString('<input', substr($partial, 0, (int) strpos($partial, '?>')), 'no inputs in the docblock');
        // …while still collecting everything a unit genuinely needs.
        foreach (['name="address"', 'name="city"', 'name="state_code"', 'name="pincode"', 'name="latitude"'] as $field) {
            $this->assertStringContainsString($field, $partial);
        }
    }

    /** The shared vendor partial must be left alone — three vendor screens include it. */
    public function testSharedShopLocationPartialStillHasItsDeliveryFields(): void
    {
        $partial = $this->read('Views/partials/_shop_location.php');

        $this->assertStringContainsString(
            'delivery_radius',
            $partial,
            '_shop_location.php lost its delivery radius — that partial is included by '
            . '/register, admin/vendors/form and vendor/shops/new and must not be edited here',
        );
    }

    /** Manufacturer products are created B2B-only, independently of the storefront filter. */
    public function testManufacturerProductsAreCreatedOffTheStorefront(): void
    {
        $src = $this->read('Models/ManufacturerProductRepository.php');

        $this->assertStringContainsString("'is_online_enabled' => 0", $src);
        $this->assertStringContainsString("'visibility'        => 'vendor'", $src);
        $this->assertStringContainsString("'mrp'          => 0", $src, 'manufacturers have no MRP concept');
    }

    /** The price invariant must be enforced in the repository, not only the controller. */
    public function testRepositoryRevalidatesPrices(): void
    {
        $src = $this->read('Models/ManufacturerProductRepository.php');

        $this->assertStringContainsString('ManufacturerPricing::validate', $src);
        // update() must merge with stored values, or the invariant can be walked past
        // one field at a time by posting only the selling price.
        $this->assertMatchesRegularExpression(
            "/'making_price' => \\\$d\['making_price'\] \?\? \\\$existing\['making_price'\]/",
            $src,
            'update() must validate against the stored counterpart price',
        );
    }

    /** Crude brace-matching body extractor — enough to scope an assertion to one method. */
    private function methodBody(string $src, string $method): string
    {
        if (! preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $brace = strpos($src, '{', (int) $m[0][1]);
        if ($brace === false) {
            return '';
        }

        $depth = 0;
        for ($i = $brace, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }

        return '';
    }
}
