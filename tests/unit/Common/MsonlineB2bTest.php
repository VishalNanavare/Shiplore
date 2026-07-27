<?php

declare(strict_types=1);

use App\Libraries\Catalog\PurchaseRules;
use App\Libraries\Workflow\StatusMachine;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * msonline B2B marketplace invariants.
 *
 * The three that would cost real money or leak commercially sensitive data if broken:
 *
 *   1. Prices are never rendered to a visitor who is not a resolved buyer.
 *   2. `making_price` — the manufacturer's production cost — never reaches msonline.
 *   3. Order totals are computed from DB prices, never from client input.
 */
final class MsonlineB2bTest extends CIUnitTestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

    // ---------------------------------------------------------------- pricing gate

    /**
     * The catalogue repository must default to withholding prices. A caller that
     * forgets the flag gets rows with no price key at all — absent, not zero, so a
     * template cannot accidentally render one.
     */
    public function testCatalogRepositoryWithholdsPricesByDefault(): void
    {
        $src = $this->read('Models/MsonlineCatalogRepository.php');

        $this->assertMatchesRegularExpression(
            '/function products\(array \$opts = \[\], bool \$withPrices = false\)/',
            $src,
            'products() must default to NO prices',
        );
        $this->assertMatchesRegularExpression(
            '/function findBySlug\(string \$slug, bool \$withPrices = false\)/',
            $src,
            'findBySlug() must default to NO prices',
        );
        // base_price may only be added inside the opt-in branch.
        $this->assertMatchesRegularExpression(
            '/if \(\$withPrices\) \{\s*\n.*\n?\s*\$cols \.= .*base_price/',
            $src,
            'base_price must only be selected when withPrices is true',
        );
    }

    /** The controller must derive that flag from a resolved buyer, not a bare login. */
    public function testControllerOptsIntoPricesOnlyForAResolvedBuyer(): void
    {
        $ctrl = $this->read('Controllers/Msonline/CatalogController.php');
        $base = $this->read('Controllers/Msonline/BaseMsonlineController.php');

        $this->assertStringContainsString('$withPrices = $this->isBuyer();', $ctrl);

        // isBuyer() must require BOTH a vendor principal and a resolvable vendor row.
        // isLoggedIn alone would let a customer or rider session see wholesale pricing.
        $this->assertStringContainsString("principal_type') === 'vendor'", $base);
        $this->assertStringContainsString('buyerVendorId() !== null', $base);
    }

    /** No msonline view may print a price outside a showPrices guard. */
    public function testViewsGatePriceOutputOnShowPrices(): void
    {
        foreach (['browse', 'product'] as $view) {
            $src = (string) file_get_contents(APPPATH . "Views/msonline/{$view}.php");

            $this->assertStringContainsString(
                'showPrices',
                $src,
                "msonline/{$view}.php prints prices without checking showPrices",
            );
            // Every base_price echo must sit after a showPrices check in the same file.
            $guard = strpos($src, 'showPrices');
            $price = strpos($src, 'base_price');
            if ($price !== false) {
                $this->assertLessThan($price, $guard, "msonline/{$view}.php echoes a price before the guard");
            }
        }
    }

    // ------------------------------------------------------- making price containment

    /** making_price must appear nowhere in the msonline surface — controllers or views. */
    public function testMakingPriceNeverReachesMsonline(): void
    {
        $files = array_merge(
            glob(APPPATH . 'Controllers/Msonline/*.php') ?: [],
            glob(APPPATH . 'Views/msonline/*.php') ?: [],
            [APPPATH . 'Models/MsonlineCatalogRepository.php'],
        );

        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            // The word may appear in a comment explaining the omission; a SELECT of it
            // may not. Assert on the column reference shapes that would actually fetch it.
            foreach (['pv.making_price', "'making_price'", '$r[\'making_price\']'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $src,
                    basename($file) . ' fetches making_price — that is the manufacturer\'s '
                    . 'internal production cost and must never reach a buyer',
                );
            }
        }
    }

    /** The PO line stores the SELLING price. making_price must not be persisted either. */
    public function testPurchaseOrderLinesStoreSellingPriceOnly(): void
    {
        $src = $this->read('Models/PurchaseOrderRepository.php');

        $this->assertStringContainsString("'unit_price'             => \$unit->amount()", $src);
        $this->assertStringNotContainsString('pv.making_price', $src);
        $this->assertStringNotContainsString("'making_price'", $src);
    }

    // ----------------------------------------------------------- price integrity

    /**
     * Prices must come from the database at placement. If the repository ever read a
     * price from the cart or the request, a buyer could set their own.
     */
    public function testPricesComeFromTheDatabaseNotTheClient(): void
    {
        $src = $this->read('Models/PurchaseOrderRepository.php');

        $this->assertStringContainsString('pv.base_price', $src, 'the unit price must be read from the DB');
        $this->assertStringNotContainsString('getPost', $src, 'the repository must not read request input');
        $this->assertStringNotContainsString('$_POST', $src);

        // The cart is variantId => qty only; no price travels in it.
        $cart = $this->read('Libraries/Msonline/MsonlineCart.php');
        $this->assertStringNotContainsString('price', $cart, 'the cart must carry quantities only, never prices');
    }

    /** Only manufacturers may be sold on msonline. */
    public function testOnlyManufacturerProductsAreSellable(): void
    {
        $this->assertStringContainsString(
            "where('v.party_type', 'manufacturer')",
            $this->read('Models/PurchaseOrderRepository.php'),
            'placement must reject a non-manufacturer variant',
        );
        $this->assertStringContainsString(
            "where('v.party_type', 'manufacturer')",
            $this->read('Models/MsonlineCatalogRepository.php'),
            'the catalogue must show manufacturers only',
        );
    }

    // -------------------------------------------------------------- cart isolation

    /**
     * The B2B cart must NOT reuse the consumer cart's session key. The cookie is
     * domain-wide, so one browser shares a session between shiplore.in and
     * msonline.shiplore.in — the same key would merge a shopper's basket with a
     * vendor's wholesale order.
     */
    public function testB2bCartUsesItsOwnSessionKey(): void
    {
        $b2b      = $this->read('Libraries/Msonline/MsonlineCart.php');
        $consumer = $this->read('Libraries/Store/CartService.php');

        // Anchor to the real declaration (line-start + indentation), not a docblock
        // mention — MsonlineCart's docblock quotes 'store_cart' while explaining why it
        // is deliberately NOT reused, and an unanchored match grabs that instead.
        preg_match("/^\s+private const KEY = '([a-z_]+)'/m", $b2b, $mB2b);
        preg_match("/^\s+private const KEY = '([a-z_]+)'/m", $consumer, $mCon);

        $this->assertNotEmpty($mB2b, 'MsonlineCart has no KEY constant');
        $this->assertNotEmpty($mCon, 'CartService has no KEY constant');
        $this->assertNotSame(
            $mCon[1],
            $mB2b[1],
            'the B2B cart shares the consumer cart session key — baskets would merge',
        );
    }

    // ------------------------------------------------------------ MOQ + lifecycle

    /** MOQ is enforced with the existing shared validator, on real rule shapes. */
    public function testMinimumOrderQuantityIsEnforced(): void
    {
        $rules = ['min_purchase_qty' => 50, 'max_purchase_qty' => null, 'qty_step' => 10];

        $this->assertFalse(PurchaseRules::validate(10, $rules)['ok'], 'below MOQ must be rejected');
        $this->assertFalse(PurchaseRules::validate(49, $rules)['ok']);
        $this->assertTrue(PurchaseRules::validate(50, $rules)['ok'], 'exactly MOQ is fine');
        $this->assertTrue(PurchaseRules::validate(60, $rules)['ok']);
        $this->assertFalse(PurchaseRules::validate(55, $rules)['ok'], 'must respect the pack multiple');
        $this->assertFalse(PurchaseRules::validate(0, $rules)['ok']);
        $this->assertFalse(PurchaseRules::validate(-5, $rules)['ok']);

        // And it is re-checked server-side at placement, not just in the controller.
        $this->assertStringContainsString(
            'PurchaseRules::validate',
            $this->read('Models/PurchaseOrderRepository.php'),
            'MOQ must be re-validated at placement — the session cart can go stale',
        );
    }

    /** The PO lifecycle must reject illegal jumps. */
    public function testPurchaseOrderLifecycleRejectsIllegalTransitions(): void
    {
        // The happy path.
        $this->assertTrue(StatusMachine::canPurchaseOrder('draft', 'placed'));
        $this->assertTrue(StatusMachine::canPurchaseOrder('placed', 'accepted'));
        $this->assertTrue(StatusMachine::canPurchaseOrder('accepted', 'packed'));
        $this->assertTrue(StatusMachine::canPurchaseOrder('packed', 'dispatched'));
        $this->assertTrue(StatusMachine::canPurchaseOrder('dispatched', 'received'));
        $this->assertTrue(StatusMachine::canPurchaseOrder('received', 'closed'));

        // Skipping dispatch would let a buyer take stock that never shipped.
        $this->assertFalse(StatusMachine::canPurchaseOrder('placed', 'received'));
        $this->assertFalse(StatusMachine::canPurchaseOrder('accepted', 'received'));
        // Reopening a settled order.
        $this->assertFalse(StatusMachine::canPurchaseOrder('received', 'placed'));
        $this->assertFalse(StatusMachine::canPurchaseOrder('closed', 'dispatched'));
        $this->assertFalse(StatusMachine::canPurchaseOrder('cancelled', 'accepted'));
        $this->assertFalse(StatusMachine::canPurchaseOrder('rejected', 'accepted'));
        // Cancelling after dispatch — the goods are already gone.
        $this->assertFalse(StatusMachine::canPurchaseOrder('dispatched', 'cancelled'));
    }

    /** Receipt must go through the state machine and raise stock via InventoryService. */
    public function testReceiptRaisesStockThroughInventoryService(): void
    {
        $body = $this->methodBody($this->read('Models/PurchaseOrderRepository.php'), 'receive');

        $this->assertStringContainsString('canPurchaseOrder', $body, 'receipt must validate the transition');
        $this->assertStringContainsString("service('inventoryService')", $body);
        $this->assertStringContainsString("'ref_type' => 'mfg_purchase_order'", $body, 'the ledger row must reference the PO');
        $this->assertStringContainsString('buyer_shop_id', $body, 'stock must land at the destination shop');
        $this->assertStringContainsString('transBegin', $body, 'stock-in and status must move together');
    }

    /** A buyer may only route stock into a shop they are allowed to act on. */
    public function testDestinationShopIsCheckedAgainstTheBuyersAllowedShops(): void
    {
        $body = $this->methodBody($this->read('Controllers/Msonline/OrderController.php'), 'place');

        $this->assertStringContainsString(
            'in_array($shopId, $this->buyerShopIds(), true)',
            $body,
            'a branch manager could otherwise route stock into a sibling branch',
        );
    }

    /** Every ordering action requires a buyer; browsing is the only anonymous surface. */
    public function testEveryOrderingActionRequiresABuyer(): void
    {
        $src = $this->read('Controllers/Msonline/OrderController.php');
        preg_match_all('/public function (\w+)\s*\(/', $src, $m);

        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $method) {
            $this->assertStringContainsString(
                'requireBuyer',
                $this->methodBody($src, $method),
                "Msonline\\OrderController::{$method}() does not require a buyer",
            );
        }
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
