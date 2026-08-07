<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** T4 — web POS: screen renders, search returns JSON, sale wires to the server-side writer. */
final class VendorPosTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'vendor.shiplore.in');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array { return [['permissions' => ['pos.sell', 'pos.discount.apply', 'pos.credit.sale', 'pos.return.process'], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1, 'display_name' => 'Sole Mate']; }
            public function findStaffVendor(int $u): ?array { return null; }
            public function shopIdsForVendor(int $v): array { return [2]; }   // owner: every shop of the vendor
            public function shopIdsForStaff(int $vs): array { return []; }
        });
        Services::injectMock('vendorShopRepository', new class {
            public function list(int $v): array { return [['id' => 2, 'name' => 'Andheri Store']]; }
            public function findById(int $id, int $v): ?array { return $id === 2 ? ['id' => 2, 'name' => 'Andheri Store'] : null; }
        });
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function sess(): array
    {
        return service('session')->get() + ['isLoggedIn' => true, 'user_id' => 5, 'user_name' => 'Cashier', 'principal_type' => 'vendor'];
    }

    private function decode($r): array
    {
        $b = (string) $r->getBody();
        if (str_contains($b, '<') && str_contains($b, '{')) {
            $b = substr($b, (int) strpos($b, '{'), (int) strrpos($b, '}') - (int) strpos($b, '{') + 1);
        }

        return json_decode($b, true) ?: [];
    }

    public function testPosScreenRenders(): void
    {
        Services::injectMock('posSaleRepository', new class {
            public function search(int $v, int $s, string $q, int $l = 20): array { return []; }
        });
        $r = $this->withSession($this->sess())->get('vendor/pos');
        $r->assertStatus(200);
        $this->assertStringContainsString('Point of Sale', (string) $r->getBody());
        $this->assertStringContainsString('posSearch', (string) $r->getBody());
    }

    public function testSearchReturnsItems(): void
    {
        Services::injectMock('posSaleRepository', new class {
            public function search(int $v, int $s, string $q, int $l = 20): array { return [['id' => 9, 'sku' => 'SK1', 'title' => 'Tee', 'unit_price' => '499', 'tax_rate' => '12', 'available' => '7']]; }
        });
        $r = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->sess())->get('vendor/pos/search?shop_id=2&q=tee');
        $json = $this->decode($r);
        $this->assertTrue($json['ok']);
        $this->assertSame('Tee', $json['items'][0]['title']);
    }

    public function testSaleResolvesServerSideAndWrites(): void
    {
        $spy = new class {
            public array $got = [];
            public function resolveLines(int $v, array $cart): array { $this->got['cart'] = $cart; return [['variant_id' => 9, 'qty' => 2.0, 'unit_price' => 499.0, 'tax_rate' => 12.0, 'title' => 'Tee', 'sku' => 'SK1', 'hsn' => '']]; }
            public function createSale(array $ctx, array $lines, array $payments, array $opts = []): array { $this->got['ctx'] = $ctx; $this->got['payments'] = $payments; return ['ok' => true, 'message' => 'ok', 'sale_id' => 77, 'invoice_no' => 'INV-1', 'grand_total' => 998.0, 'paid' => 998.0, 'change' => 0.0]; }
        };
        Services::injectMock('posSaleRepository', $spy);
        $r = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->sess())->post('vendor/pos/sale', [
            csrf_token() => csrf_hash(), 'shop_id' => 2,
            'cart' => [['variant_id' => 9, 'qty' => 2]],
            'payments' => [['tender_type' => 'cash', 'amount' => 998]],
        ]);
        $json = $this->decode($r);
        $this->assertTrue($json['ok']);
        $this->assertSame(77, $json['sale_id']);
        $this->assertSame([9 => 2.0], $spy->got['cart']);                 // server re-resolves prices
        $this->assertSame(1, (int) $spy->got['ctx']['vendor_id']);        // vendor forced from session (1)
        $this->assertSame('cash', $spy->got['payments'][0]['tender_type']);
    }

    public function testCreditSaleResolvesCustomerAndSetsMode(): void
    {
        $spy = new class {
            public array $got = [];
            public function resolveLines(int $v, array $cart): array { return [['variant_id' => 9, 'qty' => 1.0, 'unit_price' => 300.0, 'tax_rate' => 0.0, 'title' => 'X', 'sku' => 'S', 'hsn' => '']]; }
            public function createSale(array $ctx, array $lines, array $payments, array $opts = []): array { $this->got['opts'] = $opts; return ['ok' => true, 'sale_id' => 5, 'invoice_no' => 'I', 'grand_total' => 300.0, 'paid' => 100.0, 'change' => 0.0]; }
        };
        Services::injectMock('posSaleRepository', $spy);
        Services::injectMock('creditRepository', new class {
            public function findOrCreateCustomer(string $n, string $m, string $e = '', ?int $a = null): ?int { return $n !== '' && $m !== '' ? 42 : null; }
        });
        $r = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->sess())->post('vendor/pos/sale', [
            csrf_token() => csrf_hash(), 'shop_id' => 2, 'mode' => 'credit', 'customer_name' => 'Ramesh', 'customer_mobile' => '9876500000',
            'cart' => [['variant_id' => 9, 'qty' => 1]], 'payments' => [['tender_type' => 'cash', 'amount' => 100]],
        ]);
        $this->assertTrue($this->decode($r)['ok']);
        $this->assertSame('credit', $spy->got['opts']['mode']);
        $this->assertSame(42, $spy->got['opts']['customer_id']);   // customer resolved server-side
    }

    public function testCreditSaleWithoutCustomerRejected(): void
    {
        Services::injectMock('posSaleRepository', new class {
            public function resolveLines(int $v, array $cart): array { return [['variant_id' => 9, 'qty' => 1.0, 'unit_price' => 300.0, 'tax_rate' => 0.0, 'title' => 'X', 'sku' => 'S', 'hsn' => '']]; }
            public function createSale(array $ctx, array $lines, array $payments, array $opts = []): array { return ['ok' => true, 'sale_id' => 1]; }
        });
        Services::injectMock('creditRepository', new class {
            public function findOrCreateCustomer(string $n, string $m, string $e = '', ?int $a = null): ?int { return null; }  // missing name/mobile
        });
        $r = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->sess())->post('vendor/pos/sale', [csrf_token() => csrf_hash(), 'shop_id' => 2, 'mode' => 'credit', 'cart' => [['variant_id' => 9, 'qty' => 1]]]);
        $r->assertStatus(422);
    }

    public function testRepayWiresToRepo(): void
    {
        $spy = new class {
            public array $calls = [];
            public function repay(int $id, int $v, float $amt, string $m = 'cash', string $r = '', ?int $a = null): array { $this->calls[] = [$id, $v, $amt, $m]; return ['ok' => true, 'message' => 'done']; }
        };
        Services::injectMock('creditRepository', $spy);
        $r = $this->withSession($this->sess())->post('vendor/pos/credits/8/repay', [csrf_token() => csrf_hash(), 'amount' => '150', 'method' => 'upi']);
        $r->assertRedirect();
        $this->assertSame([8, 1, 150.0, 'upi'], $spy->calls[0]);   // vendor scope forced (1)
    }

    private function grantOnly(array $perms): void
    {
        Services::injectMock('capabilityRepository', new class ($perms) {
            public function __construct(private array $perms) {}
            public function loadAssignments(int $u): array { return [['permissions' => $this->perms, 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => []]]; }
        });
        Services::injectMock('posSaleRepository', new class {
            public function resolveLines(int $v, array $cart): array { return [['variant_id' => 9, 'qty' => 1.0, 'unit_price' => 100.0, 'tax_rate' => 0.0, 'title' => 'X', 'sku' => 'S', 'hsn' => '']]; }
            public function createSale(array $ctx, array $lines, array $payments, array $opts = []): array { return ['ok' => true, 'sale_id' => 1, 'invoice_no' => 'I', 'grand_total' => 100.0, 'paid' => 100.0, 'change' => 0.0]; }
        });
    }

    public function testSaleDeniedWithoutPosSell(): void
    {
        $this->grantOnly(['product.view']);   // no pos.sell
        $r = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->sess())->post('vendor/pos/sale', [csrf_token() => csrf_hash(), 'shop_id' => 2, 'cart' => [['variant_id' => 9, 'qty' => 1]], 'payments' => [['tender_type' => 'cash', 'amount' => 100]]]);
        $r->assertStatus(403);
    }

    public function testDiscountDeniedWithoutPermission(): void
    {
        $this->grantOnly(['pos.sell']);   // can sell, cannot discount
        $r = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->sess())->post('vendor/pos/sale', [csrf_token() => csrf_hash(), 'shop_id' => 2, 'bill_discount' => '50', 'cart' => [['variant_id' => 9, 'qty' => 1]], 'payments' => [['tender_type' => 'cash', 'amount' => 50]]]);
        $r->assertStatus(403);
    }

    public function testCreditDeniedWithoutPermission(): void
    {
        $this->grantOnly(['pos.sell']);   // can sell, cannot sell on credit
        Services::injectMock('creditRepository', new class {
            public function findOrCreateCustomer(string $n, string $m, string $e = '', ?int $a = null): ?int { return 1; }
        });
        $r = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->sess())->post('vendor/pos/sale', [csrf_token() => csrf_hash(), 'shop_id' => 2, 'mode' => 'credit', 'customer_name' => 'R', 'customer_mobile' => '9990001112', 'cart' => [['variant_id' => 9, 'qty' => 1]], 'payments' => [['tender_type' => 'cash', 'amount' => 10]]]);
        $r->assertStatus(403);
    }

    public function testScanReturnsMultipleForBarcodeConflict(): void
    {
        Services::injectMock('productBarcodeRepository', new class {
            public function resolveAll(string $code, ?int $v = null): array { return [['resolved_variant_id' => 9, 'vendor_id' => 1], ['resolved_variant_id' => 10, 'vendor_id' => 1]]; }
        });
        Services::injectMock('posSaleRepository', new class {
            public function resolveLines(int $v, array $cart): array { return [['variant_id' => 9, 'qty' => 1.0, 'unit_price' => 20.0, 'tax_rate' => 0.0, 'title' => 'Cola single', 'sku' => 'A', 'hsn' => ''], ['variant_id' => 10, 'qty' => 1.0, 'unit_price' => 110.0, 'tax_rate' => 0.0, 'title' => 'Cola sixpack', 'sku' => 'B', 'hsn' => '']]; }
        });
        $r = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->sess())->get('vendor/pos/scan/PACK123');
        $json = $this->decode($r);
        $this->assertTrue($json['ok']);
        $this->assertTrue($json['multiple']);          // conflict -> picker
        $this->assertCount(2, $json['items']);
    }

    public function testProcessReturnWiresWithVendorScope(): void
    {
        $spy = new class {
            public array $got = [];
            public function createReturn(int $saleId, int $v, array $items, string $reason, string $method, ?int $a = null): array { $this->got = compact('saleId', 'v', 'items', 'method'); return ['ok' => true, 'message' => 'done', 'refund' => 200.0, 'return_id' => 1]; }
        };
        Services::injectMock('posReturnRepository', $spy);
        $r = $this->withSession($this->sess())->post('vendor/pos/returns', [csrf_token() => csrf_hash(), 'sale_id' => 12, 'reason' => 'damaged', 'refund_method' => 'cash', 'qty' => [55 => '2', 56 => '0']]);
        $r->assertRedirect();
        $this->assertSame(12, $spy->got['saleId']);
        $this->assertSame(1, $spy->got['v']);                              // vendor forced (1)
        $this->assertSame([['pos_sale_item_id' => 55, 'qty' => 2.0]], $spy->got['items']); // zero-qty line dropped
    }

    public function testReturnsDeniedWithoutPermission(): void
    {
        $this->grantOnly(['pos.sell']);   // no pos.return.process
        Services::injectMock('posReturnRepository', new class {
            public function createReturn(int $s, int $v, array $i, string $r, string $m, ?int $a = null): array { return ['ok' => true]; }
        });
        $r = $this->withSession($this->sess())->post('vendor/pos/returns', [csrf_token() => csrf_hash(), 'sale_id' => 12, 'qty' => [55 => '1']]);
        $r->assertRedirect();
        $this->assertStringContainsString('vendor/pos', (string) $r->getRedirectUrl());
    }

    public function testReportsPageRenders(): void
    {
        Services::injectMock('posReportRepository', new class {
            public function summary(int $v, ?int $s, string $f, string $t): array { return ['bills' => 3, 'sales' => 1500.0, 'tax' => 120.0, 'discount' => 0.0]; }
            public function byMethod(int $v, ?int $s, string $f, string $t): array { return [['tender_type' => 'cash', 'txns' => 2, 'amount' => 1000.0]]; }
            public function byDay(int $v, ?int $s, string $f, string $t): array { return []; }
            public function topProducts(int $v, ?int $s, string $f, string $t, int $l = 10): array { return [['title' => 'Tee', 'sku' => 'SK1', 'qty' => 5.0, 'amount' => 1000.0]]; }
        });
        Services::injectMock('creditRepository', new class {
            public function summary(int $v): array { return ['outstanding' => 250.0, 'count' => 1]; }
        });
        $r = $this->withSession($this->sess())->get('vendor/pos/reports');
        $r->assertStatus(200);
        $this->assertStringContainsString('POS Reports', (string) $r->getBody());
        $this->assertStringContainsString('Tee', (string) $r->getBody());
    }

    public function testDeliverySaleCreatesDeliveryWithFee(): void
    {
        $saleSpy = new class {
            public array $opts = [];
            public function resolveLines(int $v, array $cart): array { return [['variant_id' => 9, 'qty' => 1.0, 'unit_price' => 100.0, 'tax_rate' => 0.0, 'title' => 'X', 'sku' => 'S', 'hsn' => '']]; }
            public function createSale(array $ctx, array $lines, array $payments, array $opts = []): array { $this->opts = $opts; return ['ok' => true, 'sale_id' => 31, 'invoice_no' => 'I', 'grand_total' => 140.0, 'paid' => 140.0, 'change' => 0.0]; }
        };
        Services::injectMock('posSaleRepository', $saleSpy);
        $delSpy = new class {
            public array $got = [];
            public function createForSale(int $saleId, int $shopId, array $recipient, float $fee, string $eta = '', ?int $a = null): ?int { $this->got = compact('saleId', 'shopId', 'recipient', 'fee'); return 7; }
        };
        Services::injectMock('posDeliveryRepository', $delSpy);
        $r = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->sess())->post('vendor/pos/sale', [
            csrf_token() => csrf_hash(), 'shop_id' => 2, 'deliver' => '1', 'delivery_fee' => '40',
            'deliver_name' => 'Asha', 'deliver_phone' => '9812345678', 'deliver_address' => '12 MG Road',
            'cart' => [['variant_id' => 9, 'qty' => 1]], 'payments' => [['tender_type' => 'cash', 'amount' => 140]],
        ]);
        $json = $this->decode($r);
        $this->assertTrue($json['ok']);
        $this->assertSame(7, $json['delivery_id']);
        $this->assertSame(40.0, $saleSpy->opts['delivery_fee']);     // fee passed into the sale total
        $this->assertSame('delivery', $saleSpy->opts['order_type']);  // order type recorded
        $this->assertSame(31, $delSpy->got['saleId']);               // delivery linked to the sale
        $this->assertSame('Asha', $delSpy->got['recipient']['name']);
    }

    public function testDeliverySaleWithoutCustomerDetailsRejected(): void
    {
        Services::injectMock('posSaleRepository', new class {
            public function resolveLines(int $v, array $cart): array { return [['variant_id' => 9, 'qty' => 1.0, 'unit_price' => 100.0, 'tax_rate' => 0.0, 'title' => 'X', 'sku' => 'S', 'hsn' => '']]; }
            public function createSale(array $ctx, array $lines, array $payments, array $opts = []): array { return ['ok' => true, 'sale_id' => 1, 'invoice_no' => 'I', 'grand_total' => 100.0, 'paid' => 100.0, 'change' => 0.0]; }
        });
        // delivery selected but no name/phone/address → blocked before the sale is written
        $r = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->sess())->post('vendor/pos/sale', [
            csrf_token() => csrf_hash(), 'shop_id' => 2, 'deliver' => '1',
            'cart' => [['variant_id' => 9, 'qty' => 1]], 'payments' => [['tender_type' => 'cash', 'amount' => 100]],
        ]);
        $json = $this->decode($r);
        $this->assertFalse($json['ok']);
        $this->assertStringContainsString('name, mobile and address', (string) $json['message']);
    }

    private function withCap(float $cap): void
    {
        Services::injectMock('capabilityRepository', new class ($cap) {
            public function __construct(private float $cap) {}
            public function loadAssignments(int $u): array { return [['permissions' => ['pos.sell', 'pos.discount.apply'], 'scope_type' => 'vendor', 'scope_id' => 1, 'attributes' => ['discount_cap' => $this->cap]]]; }
        });
        Services::injectMock('posSaleRepository', new class {
            public function resolveLines(int $v, array $cart): array { return [['variant_id' => 9, 'qty' => 1.0, 'unit_price' => 100.0, 'tax_rate' => 0.0, 'title' => 'X', 'sku' => 'S', 'hsn' => '']]; }
            public function createSale(array $ctx, array $lines, array $payments, array $opts = []): array { return ['ok' => true, 'sale_id' => 1, 'invoice_no' => 'I', 'grand_total' => 90.0, 'paid' => 90.0, 'change' => 0.0]; }
        });
    }

    public function testDiscountWithinCapAllowed(): void
    {
        $this->withCap(10);   // ₹100 subtotal, ₹5 discount = 5% <= 10%
        $r = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->sess())->post('vendor/pos/sale', [csrf_token() => csrf_hash(), 'shop_id' => 2, 'bill_discount' => '5', 'cart' => [['variant_id' => 9, 'qty' => 1]], 'payments' => [['tender_type' => 'cash', 'amount' => 95]]]);
        $this->assertTrue($this->decode($r)['ok']);
    }

    public function testDiscountOverCapRejected(): void
    {
        $this->withCap(10);   // ₹100 subtotal, ₹20 discount = 20% > 10%
        $r = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->sess())->post('vendor/pos/sale', [csrf_token() => csrf_hash(), 'shop_id' => 2, 'bill_discount' => '20', 'cart' => [['variant_id' => 9, 'qty' => 1]], 'payments' => [['tender_type' => 'cash', 'amount' => 80]]]);
        $r->assertStatus(403);
        $this->assertStringContainsString('cap', strtolower((string) $r->getBody()));
    }

    public function testSaleRejectsForeignShop(): void
    {
        Services::injectMock('posSaleRepository', new class {
            public function resolveLines(int $v, array $cart): array { return []; }
            public function createSale(array $ctx, array $lines, array $payments, array $opts = []): array { return ['ok' => true, 'sale_id' => 1]; }
        });
        $r = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->withSession($this->sess())->post('vendor/pos/sale', [csrf_token() => csrf_hash(), 'shop_id' => 999, 'cart' => [['variant_id' => 9, 'qty' => 1]]]);
        $r->assertStatus(422);
    }
}
