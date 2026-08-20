<?php

declare(strict_types=1);

use App\Models\ShopRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * ShopRepository::list()/countList() actually filter by approval_status — exercised
 * against a real query, not a mock. AdminShopApprovalTest mocks shopRepository
 * entirely to test the controller's wiring, so it cannot see whether
 * applyFilters()'s WHERE clause is real; a mutation run confirmed that removing the
 * clause left every controller-level test green.
 */
final class ShopRepositoryApprovalStatusFilterTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureShopsTable();
        $this->ensureVendorsTable();
        $db = Database::connect();
        $db->table('shops')->insert(['id' => 94001, 'vendor_id' => 1, 'name' => 'Pending Shop', 'status' => 'inactive', 'approval_status' => 'pending']);
        $db->table('shops')->insert(['id' => 94002, 'vendor_id' => 1, 'name' => 'Live Shop', 'status' => 'active', 'approval_status' => 'not_required']);
    }

    protected function tearDown(): void
    {
        Database::connect()->table('shops')->whereIn('id', [94001, 94002])->delete();
        Services::reset();
        parent::tearDown();
    }

    public function testListFiltersToTheRequestedApprovalStatus(): void
    {
        $names = array_column((new ShopRepository())->list(['approval_status' => 'pending']), 'name');

        $this->assertContains('Pending Shop', $names);
        $this->assertNotContains('Live Shop', $names);
    }

    public function testCountListAlsoFilters(): void
    {
        $repo   = new ShopRepository();
        $before = $repo->countList(['approval_status' => 'pending']);

        Database::connect()->table('shops')->insert(['id' => 94003, 'vendor_id' => 1, 'name' => 'Another Pending', 'status' => 'inactive', 'approval_status' => 'pending']);

        $this->assertSame($before + 1, $repo->countList(['approval_status' => 'pending']));

        Database::connect()->table('shops')->where('id', 94003)->delete();
    }

    /** Without the filter, both pending and non-pending shops are returned — proves the filter is doing something. */
    public function testWithoutTheFilterBothShopsAppear(): void
    {
        $names = array_column((new ShopRepository())->list([]), 'name');

        $this->assertContains('Pending Shop', $names);
        $this->assertContains('Live Shop', $names);
    }
}
