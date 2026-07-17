<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * POS-2 — the POS Credit Note: a numbered refund document (its own bill no.),
 * issued against a sale or as a standalone walk-in, printable as 80mm.
 */
final class ShopPosCreditNoteTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $returns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->returns = new class {
            public array $standalone = [];
            public function recent(int $v, int $l = 100): array { return [['id' => 5, 'credit_note_no' => 'CN-26-000005', 'customer_name' => 'Asha', 'server_invoice_no' => null, 'refund_amount' => '250.00', 'refund_method' => 'cash', 'reason' => 'damaged', 'shop' => 'Andheri', 'created_at' => '2026-06-12 10:00:00']]; }
            public function createStandalone(int $v, int $s, array $lines, array $cust, string $reason, string $method, ?int $a = null): array { $this->standalone[] = [$lines, $cust]; return ['ok' => true, 'message' => 'Credit Note CN-26-000006 for ₹250.00 created.', 'refund' => 250.0, 'return_id' => 6, 'credit_note_no' => 'CN-26-000006']; }
            public function findCreditNote(int $id, int $v): ?array { return ['id' => $id, 'shop_id' => 1, 'credit_note_no' => 'CN-26-000006', 'created_at' => '2026-06-12 10:00:00', 'customer_name' => 'Asha', 'customer_phone' => '98120', 'reason' => 'damaged', 'against_invoice' => null, 'taxable_value' => '238.10', 'cgst' => '5.95', 'sgst' => '5.95', 'igst' => '0.00', 'refund_amount' => '250.00', 'refund_method' => 'cash', 'items' => [['title' => 'Shoe', 'sku' => 'SK1', 'qty' => '1.000', 'amount' => '250.00']]]; }
        };
        Services::injectMock('posReturnRepository', $this->returns);
        Services::injectMock('shopBillSettingsRepository', new class {
            public function forShop(int $s): array { return ['shop_id' => $s, 'shop_name' => 'Sole Mate Andheri', 'gstin' => '27ABCDE1234F1Z5', 'phone' => '9800000000', 'pincode' => '400058', 'address' => 'MG Road', 'settings' => \App\Models\ShopBillSettingsRepository::defaults()]; }
        });
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        parent::tearDown();
    }

    private function asCashier(array $perms = ['pos.return.process']): void
    {
        Services::injectMock('capabilityRepository', new class ($perms) {
            public function __construct(private array $perms) {}
            public function loadAssignments(int $u): array { return [['permissions' => $this->perms, 'scope_type' => 'shop', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return null; }
            public function findStaffVendor(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate', 'vendor_staff_id' => 7, 'staff_type' => 'cashier']; }
            public function shopIdsForStaff(int $vs): array { return [1]; }
            public function shopIdsForVendor(int $v): array { return [1]; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 1, 'name' => 'Andheri']]; }
        });
    }

    private function sess(): array
    {
        return service('session')->get() + ['isLoggedIn' => true, 'user_id' => 110, 'user_name' => 'Cashier', 'principal_type' => 'vendor'];
    }

    public function testCreditNoteScreenRenders(): void
    {
        $this->asCashier();

        $r = $this->withSession($this->sess())->get('vendor/pos/credit-note');

        $r->assertStatus(200);
        $r->assertSee('CN-26-000005');           // recent CN listed
        $this->assertStringContainsString('Issue credit note', (string) $r->getBody());
    }

    public function testStandaloneCreditNotePostsAndRedirectsToReceipt(): void
    {
        $this->asCashier();

        $r = $this->withSession(service('session')->get() + $this->sess())->post('vendor/pos/credit-note', [
            csrf_token() => csrf_hash(), 'shop_id' => 1, 'customer_name' => 'Asha', 'customer_phone' => '98120',
            'line_title' => ['Shoe', ''], 'line_variant' => ['9', ''], 'line_qty' => ['1', ''], 'line_price' => ['250', ''], 'line_tax' => ['5', ''],
            'reason' => 'damaged', 'refund_method' => 'cash',
        ]);

        $r->assertRedirectTo('vendor/pos/credit-note/6');
        $this->assertCount(1, $this->returns->standalone);
        $this->assertSame('Shoe', $this->returns->standalone[0][0][0]['title']);
        $this->assertSame('Asha', $this->returns->standalone[0][1]['name']);
    }

    public function testCreditNoteReceiptPrints(): void
    {
        $this->asCashier();

        $body = (string) $this->withSession($this->sess())->get('vendor/pos/credit-note/6')->getBody();

        $this->assertStringContainsString('CREDIT NOTE', $body);
        $this->assertStringContainsString('CN-26-000006', $body);
        $this->assertStringContainsString('Sole Mate Andheri', $body);
        $this->assertStringContainsString('REFUND', $body);
    }

    public function testCreditNoteDeniedWithoutPermission(): void
    {
        $this->asCashier([]); // no pos.return.process

        $this->withSession($this->sess())->get('vendor/pos/credit-note')->assertRedirect();
    }
}
