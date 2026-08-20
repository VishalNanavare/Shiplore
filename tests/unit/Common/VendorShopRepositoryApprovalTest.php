<?php

declare(strict_types=1);

use App\Models\VendorShopRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Shop approval, phase 3: VendorShopRepository::create() gates every shop but the
 * FIRST one per vendor.
 *
 * A vendor's first shop is created automatically at registration
 * (RegistrationRepository::createVendorWithShop(), status='active', no gate) — that
 * path is untouched by this phase. This is the OTHER path: a vendor adding a shop
 * themselves from the vendor panel (Vendor\ShopController::create()). The first shop
 * added through EITHER path keeps today's behaviour (status='active',
 * approval_status='not_required'); the second and every one after gets
 * status='inactive', approval_status='pending' — invisible everywhere until an admin
 * approves it, with zero changes needed to the storefront/POS/vendor-status-gate work
 * already shipped, since every consumer there already treats status='inactive' as
 * not-live.
 */
final class VendorShopRepositoryApprovalTest extends CIUnitTestCase
{
    use MinimalSchema;

    private const VENDOR = 92001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureShopsTable();
        Database::connect()->table('shops')->where('vendor_id', self::VENDOR)->delete();
    }

    protected function tearDown(): void
    {
        Database::connect()->table('shops')->where('vendor_id', self::VENDOR)->delete();
        Services::reset();
        parent::tearDown();
    }

    private function row(int $id): array
    {
        return Database::connect()->table('shops')->where('id', $id)->get()->getRowArray();
    }

    public function testTheFirstShopIsActiveAndNotRequiringApproval(): void
    {
        $repo = new VendorShopRepository();

        $id = $repo->create(self::VENDOR, ['name' => 'Shop One', 'address' => 'x', 'city' => 'x', 'pincode' => '400001']);

        $row = $this->row((int) $id);
        $this->assertSame('active', $row['status']);
        $this->assertSame('not_required', $row['approval_status']);
    }

    public function testTheSecondShopIsInactiveAndPending(): void
    {
        $repo = new VendorShopRepository();
        $repo->create(self::VENDOR, ['name' => 'Shop One', 'address' => 'x', 'city' => 'x', 'pincode' => '400001']);

        $id2 = $repo->create(self::VENDOR, ['name' => 'Shop Two', 'address' => 'x', 'city' => 'x', 'pincode' => '400002']);

        $row = $this->row((int) $id2);
        $this->assertSame('inactive', $row['status'], 'a 2nd+ shop must not be live until approved');
        $this->assertSame('pending', $row['approval_status']);
    }

    public function testTheThirdShopIsAlsoPendingNotJustTheSecond(): void
    {
        $repo = new VendorShopRepository();
        $repo->create(self::VENDOR, ['name' => 'Shop One', 'address' => 'x', 'city' => 'x', 'pincode' => '400001']);
        $repo->create(self::VENDOR, ['name' => 'Shop Two', 'address' => 'x', 'city' => 'x', 'pincode' => '400002']);

        $id3 = $repo->create(self::VENDOR, ['name' => 'Shop Three', 'address' => 'x', 'city' => 'x', 'pincode' => '400003']);

        $this->assertSame('pending', $this->row((int) $id3)['approval_status']);
    }

    /** A soft-deleted first shop must not count as "the vendor already has a shop". */
    public function testASoftDeletedShopDoesNotCountTowardTheFirstShop(): void
    {
        $repo = new VendorShopRepository();
        $id1  = $repo->create(self::VENDOR, ['name' => 'Shop One', 'address' => 'x', 'city' => 'x', 'pincode' => '400001']);
        Database::connect()->table('shops')->where('id', $id1)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $id2 = $repo->create(self::VENDOR, ['name' => 'Shop Two', 'address' => 'x', 'city' => 'x', 'pincode' => '400002']);

        $this->assertSame('not_required', $this->row((int) $id2)['approval_status'], 'the deleted shop must not count — this is effectively the vendor\'s first live shop');
    }

    /** A different vendor's shops must not count toward this vendor's "first shop" check. */
    public function testAnotherVendorsShopsDoNotCount(): void
    {
        $other = self::VENDOR + 1;
        $repo  = new VendorShopRepository();
        $repo->create($other, ['name' => 'Other Vendor Shop', 'address' => 'x', 'city' => 'x', 'pincode' => '400009']);

        $id = $repo->create(self::VENDOR, ['name' => 'Shop One', 'address' => 'x', 'city' => 'x', 'pincode' => '400001']);

        $this->assertSame('not_required', $this->row((int) $id)['approval_status']);

        Database::connect()->table('shops')->where('vendor_id', $other)->delete();
    }
}
