<?php

declare(strict_types=1);

use App\Models\CommissionRuleRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

final class CommissionRuleRepositoryGmvTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSubOrdersTable();

        $db = Database::connect();
        $db->table('sub_orders')->where('vendor_id', 9001)->delete();
    }

    public function testSumsGrandTotalWithinTrailingWindow(): void
    {
        $db = Database::connect();
        $recent = date('Y-m-d H:i:s', strtotime('-5 days'));
        $old    = date('Y-m-d H:i:s', strtotime('-40 days'));
        $db->table('sub_orders')->insertBatch([
            ['order_id' => 1, 'vendor_id' => 9001, 'shop_id' => 1, 'grand_total' => '1000', 'place_of_supply' => 'MH', 'status' => 'delivered', 'created_at' => $recent],
            ['order_id' => 2, 'vendor_id' => 9001, 'shop_id' => 1, 'grand_total' => '500', 'place_of_supply' => 'MH', 'status' => 'delivered', 'created_at' => $recent],
            // outside the 30-day window — must not count
            ['order_id' => 3, 'vendor_id' => 9001, 'shop_id' => 1, 'grand_total' => '9999', 'place_of_supply' => 'MH', 'status' => 'delivered', 'created_at' => $old],
        ]);

        $repo = new CommissionRuleRepository();
        $this->assertSame(1500.0, $repo->trailingGmv(9001));
    }

    public function testExcludesCancelledAndReturnedOrders(): void
    {
        $db = Database::connect();
        $recent = date('Y-m-d H:i:s', strtotime('-5 days'));
        $db->table('sub_orders')->insertBatch([
            ['order_id' => 4, 'vendor_id' => 9001, 'shop_id' => 1, 'grand_total' => '1000', 'place_of_supply' => 'MH', 'status' => 'delivered', 'created_at' => $recent],
            ['order_id' => 5, 'vendor_id' => 9001, 'shop_id' => 1, 'grand_total' => '2000', 'place_of_supply' => 'MH', 'status' => 'cancelled', 'created_at' => $recent],
            ['order_id' => 6, 'vendor_id' => 9001, 'shop_id' => 1, 'grand_total' => '3000', 'place_of_supply' => 'MH', 'status' => 'returned', 'created_at' => $recent],
        ]);

        $repo = new CommissionRuleRepository();
        $this->assertSame(1000.0, $repo->trailingGmv(9001));
    }

    public function testZeroForVendorWithNoOrders(): void
    {
        $repo = new CommissionRuleRepository();
        $this->assertSame(0.0, $repo->trailingGmv(999999));
    }
}
