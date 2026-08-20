<?php

declare(strict_types=1);

use App\Models\ShopRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * ShopRepository::list()/countList() filtering by vendor_id — sub-project C of the
 * vendor/shop panel UX overhaul. Before this, admin/shops had no way to scope to one
 * vendor's shops; reaching a specific vendor's shop took two hops (vendor detail ->
 * click a shop -> shop detail). Mirrors the array-status extension already done for
 * VendorRepository::applyVendorFilters().
 */
final class ShopRepositoryVendorFilterTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureShopsTable();
        $this->ensureVendorsTable();
        $db = Database::connect();
        $db->table('shops')->insert(['id' => 95001, 'vendor_id' => 701, 'name' => 'Vendor A Shop 1', 'status' => 'active']);
        $db->table('shops')->insert(['id' => 95002, 'vendor_id' => 701, 'name' => 'Vendor A Shop 2', 'status' => 'active']);
        $db->table('shops')->insert(['id' => 95003, 'vendor_id' => 702, 'name' => 'Vendor B Shop 1', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Database::connect()->table('shops')->whereIn('id', [95001, 95002, 95003])->delete();
        parent::tearDown();
    }

    public function testListFiltersToOneVendorsShopsOnly(): void
    {
        $names = array_column((new ShopRepository())->list(['vendor_id' => 701]), 'name');

        $this->assertContains('Vendor A Shop 1', $names);
        $this->assertContains('Vendor A Shop 2', $names);
        $this->assertNotContains('Vendor B Shop 1', $names);
    }

    public function testCountListAlsoFiltersByVendor(): void
    {
        $this->assertSame(2, (new ShopRepository())->countList(['vendor_id' => 701]));
        $this->assertSame(1, (new ShopRepository())->countList(['vendor_id' => 702]));
    }

    public function testWithoutTheFilterShopsFromBothVendorsAppear(): void
    {
        $names = array_column((new ShopRepository())->list([]), 'name');

        $this->assertContains('Vendor A Shop 1', $names);
        $this->assertContains('Vendor B Shop 1', $names);
    }
}
