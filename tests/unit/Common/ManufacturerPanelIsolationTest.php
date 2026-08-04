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

    /** The vendor repository must stay untouched — it is not part of this feature. */
    public function testVendorAccountRepositoryIsUnchanged(): void
    {
        $src = $this->read('Models/VendorAccountRepository.php');

        $this->assertStringNotContainsString(
            'party_type',
            $src,
            'VendorAccountRepository was modified — the manufacturer work must not touch vendor code. '
            . 'If vendors ever need the constraint, that is a separate, deliberate change.',
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

    /** Nothing in the panel may be reachable without resolving a manufacturer first. */
    public function testEveryPublicControllerMethodRequiresAManufacturer(): void
    {
        foreach (['ManufacturerDashboardController', 'UnitController', 'ProductController', 'PurchaseOrderController'] as $ctrl) {
            $src = $this->read("Controllers/Manufacturer/{$ctrl}.php");
            preg_match_all('/public function (\w+)\s*\(/', $src, $m);

            $this->assertNotEmpty($m[1], "no public methods found in {$ctrl}");
            foreach ($m[1] as $method) {
                $body = $this->methodBody($src, $method);
                $this->assertStringContainsString(
                    'requireManufacturer',
                    $body,
                    "Manufacturer\\{$ctrl}::{$method}() does not call requireManufacturer()",
                );
            }
        }
    }

    /** The route group must be pinned, and must not accidentally reuse the vendor pin. */
    public function testRouteGroupIsPinnedToManufacturer(): void
    {
        $routes = $this->read('Config/Routes.php');

        $this->assertStringContainsString(
            "\$routes->group('manufacturer', ['filter' => 'webAuth:manufacturer']",
            $routes,
            'the manufacturer group must be pinned to the manufacturer principal',
        );
    }

    /**
     * Requirement 2, enforced by schema. `mshops` must carry no delivery columns, so a
     * manufacturer cannot express a delivery range even if a form field were added.
     */
    public function testMshopsHasNoDeliveryColumns(): void
    {
        $sql = (string) file_get_contents(ROOTPATH . 'database/sql/70_manufacturer.sql');

        $start = strpos($sql, 'CREATE TABLE IF NOT EXISTS `mshops`');
        $this->assertNotFalse($start, 'the mshops table definition is missing');
        $end   = strpos($sql, 'ENGINE=InnoDB', $start);
        $table = substr($sql, $start, $end - $start);

        foreach (['delivery_radius_km', 'delivery_polygon', 'pickup_enabled', 'prep_time_min', 'delivery_fee'] as $col) {
            $this->assertStringNotContainsString(
                $col,
                $table,
                "mshops must not have a {$col} column — manufacturers do not deliver",
            );
        }
    }

    /** The manufacturer registration/unit form must not offer a delivery-range field. */
    public function testFactoryLocationPartialHasNoDeliveryFields(): void
    {
        $partial = $this->read('Views/partials/_factory_location.php');

        // Assert on the posted FIELD, not the bare word — the file's docblock names both
        // while explaining what was deliberately left out, and that is worth keeping.
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
