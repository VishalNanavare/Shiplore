<?php

declare(strict_types=1);

use App\Libraries\Catalog\PurchaseRules;
use App\Libraries\Workflow\StatusMachine;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * monline B2B marketplace invariants.
 *
 * The three that would cost real money or leak commercially sensitive data if broken:
 *
 *   1. Prices are never rendered to a visitor who is not a resolved buyer.
 *   2. `making_price` — the manufacturer's production cost — never reaches monline.
 *   3. Order totals are computed from DB prices, never from client input.
 */
final class MonlineB2bTest extends CIUnitTestCase
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
        $src = $this->read('Models/MonlineCatalogRepository.php');

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

    /**
     * The catalogue must not list a product whose default variant is discontinued.
     *
     * cartLines() filters variants on status and deleted_at; base() did not. The
     * result was a product listed and priced in the catalogue that the cart silently
     * purged on arrival — repeatable forever, with no way to tell which listings were
     * real. The conditions belong in the JOIN so a dead variant nulls the price rather
     * than dropping the product row.
     */
    public function testCatalogueJoinExcludesDiscontinuedDefaultVariants(): void
    {
        $body = $this->methodBody($this->read('Models/MonlineCatalogRepository.php'), 'base');

        $this->assertStringContainsString("pv.status = 'active'", $body);
        $this->assertStringContainsString('pv.deleted_at IS NULL', $body);
    }

    /**
     * A signed-in buyer must never be shown "Login to view price".
     *
     * With base() LEFT-JOINing, a product with no live variant yields base_price NULL,
     * and a two-way `priced ? price : login-gate` branch sends an already-authenticated
     * buyer to the login prompt. There must be a third, distinct state.
     */
    public function testPriceGateHasADistinctUnavailableStateForSignedInBuyers(): void
    {
        foreach (['_product_card', 'product'] as $view) {
            $src = (string) file_get_contents(APPPATH . "Views/monline/{$view}.php");

            $this->assertStringContainsString(
                'elseif (! empty($showPrices))',
                $src,
                "monline/{$view}.php sends a signed-in buyer to the login gate when a price is missing",
            );
            $this->assertStringContainsString('mo-gate-off', $src);
        }
    }

    // ------------------------------------------------------------ proximity sort

    /**
     * The distance calculation must be a real Haversine expression with the ACOS
     * domain clamp — without GREATEST(-1, LEAST(1, ...)), floating-point rounding on a
     * near-identical point can push ACOS's argument fractionally outside [-1,1] and
     * MySQL returns NULL for what should be the NEAREST result.
     */
    public function testDistanceCalculationClampsTheAcosDomain(): void
    {
        $src = $this->read('Models/MonlineCatalogRepository.php');

        $this->assertStringContainsString('ACOS', $src);
        $this->assertStringContainsString(
            'GREATEST(-1, LEAST(1,',
            $src,
            "ACOS's argument must be clamped to [-1,1] or a near-identical point can silently become NULL",
        );
    }

    /**
     * products() must only sort by distance when a point is actually supplied, and
     * must keep p.id as a tiebreaker — LIMIT/OFFSET pagination across rows tied on
     * distance is not stable without one (a product could repeat or be skipped
     * between page 1 and page 2).
     */
    public function testProductsSortsByDistanceOnlyWhenAPointIsSupplied(): void
    {
        $body = $this->methodBody($this->read('Models/MonlineCatalogRepository.php'), 'products');

        $this->assertStringContainsString("isset(\$opts['sort_lat'], \$opts['sort_lng'])", $body);
        $this->assertStringContainsString('distance_km', $body);
        $this->assertStringContainsString(
            "orderBy('p.id', 'DESC')",
            $body,
            'the no-point fallback order must be unchanged',
        );

        // Scoped to the $hasPoint branch specifically, not just "somewhere in the file"
        // — the NULL-last handling and p.id tiebreaker matter only there.
        $ifPos    = strpos($body, 'if ($hasPoint)');
        $elsePos  = strpos($body, '} else {');
        $this->assertNotFalse($ifPos);
        $this->assertNotFalse($elsePos);
        $pointBranch = substr($body, $ifPos, $elsePos - $ifPos);

        $this->assertStringContainsString(
            "orderBy('(distance_km IS NULL)', 'ASC', false)",
            $pointBranch,
            'NULL distance must sort last, or an unresolvable product would rank as "nearest"',
        );
        $this->assertStringContainsString(
            "orderBy('distance_km', 'ASC')",
            $pointBranch,
        );
        $this->assertStringContainsString(
            "orderBy('p.id', 'DESC')",
            $pointBranch,
            'a tiebreaker is required or LIMIT/OFFSET pagination is not stable across pages for rows tied on distance',
        );
    }

    /**
     * buyerPoint() must resolve: an explicit override first, else the buyer's own shop,
     * else null. Anonymous visitors must never resolve a point at all — decision:
     * no location sort before login.
     */
    public function testBuyerPointPrefersOverrideThenFallsBackToShop(): void
    {
        $body = $this->methodBody($this->read('Controllers/Monline/BaseMonlineController.php'), 'resolveBuyerPoint');

        $this->assertStringContainsString('isBuyer()', $body, 'an anonymous visitor must never resolve a point');
        $this->assertStringContainsString("service('monlineLocationService')->get()", $body, 'an explicit override must be checked first');
        $this->assertStringContainsString('buyerShopIds()', $body, 'falls back to the buyer\'s own shop when no override is set');
    }

    /** render() must expose the resolved point's label and whether an override is active, for the nav pill. */
    public function testRenderExposesTheLocationLabelAndOverrideFlag(): void
    {
        $body = $this->methodBody($this->read('Controllers/Monline/BaseMonlineController.php'), 'render');

        $this->assertStringContainsString("'nearLabel'", $body);
        $this->assertStringContainsString("'hasLocationOverride'", $body);
    }

    /** browse() and home() must both pass sort_lat/sort_lng through when a point resolves. */
    public function testBrowseAndHomePassTheResolvedPointToTheRepository(): void
    {
        $src = $this->read('Controllers/Monline/CatalogController.php');

        foreach (['browse', 'home'] as $method) {
            $body = $this->methodBody($src, $method);
            $this->assertStringContainsString('buyerPoint()', $body, "{$method}() must resolve the buyer's point");
            $this->assertStringContainsString("'sort_lat'", $body, "{$method}() must pass sort_lat through when a point resolves");
            $this->assertStringContainsString("'sort_lng'", $body, "{$method}() must pass sort_lng through when a point resolves");
        }
    }

    /** Setting or clearing the monline location override must require a resolved buyer. */
    public function testLocationOverrideActionsRequireABuyer(): void
    {
        $src = $this->read('Controllers/Monline/CatalogController.php');

        foreach (['setLocation', 'clearLocation'] as $method) {
            $body = $this->methodBody($src, $method);
            $this->assertNotSame('', $body, "CatalogController::{$method}() is missing");
        }

        $this->assertStringContainsString('isBuyer()', $this->methodBody($src, 'setLocation'));
    }

    /** setLocation() must reject an empty/zero point, mirroring StoreController::setLocation(). */
    public function testSetLocationRejectsAZeroPoint(): void
    {
        $body = $this->methodBody($this->read('Controllers/Monline/CatalogController.php'), 'setLocation');

        $this->assertStringContainsString('$lat === 0.0 && $lng === 0.0', $body);
    }

    /**
     * A location change must NEVER touch the cart — monline sorts, it does not filter.
     * The consumer storefront's setLocation() drops undeliverable cart items on a
     * location change; that logic must not be copied here.
     */
    public function testSetLocationNeverTouchesTheCart(): void
    {
        $body = $this->methodBody($this->read('Controllers/Monline/CatalogController.php'), 'setLocation');

        $this->assertStringNotContainsString('monlineCart', $body);
        $this->assertStringNotContainsString('removeUndeliverable', $body);
    }

    /**
     * The monline location picker must be its own thing, not a copy-paste of the
     * storefront's — it has no delivery address to capture and must never touch the
     * cart on a location change.
     */
    public function testLocationModalAndScriptDoNotCarryStoreOnlyLogic(): void
    {
        $modal = $this->read('Views/monline/_location_modal.php');
        $this->assertStringContainsString("site_url('monline/location')", $modal);
        $this->assertStringNotContainsString('pincode', $modal, 'monline has no delivery address to capture');
        $this->assertStringNotContainsString('state_code', $modal);

        $js = (string) file_get_contents(FCPATH . 'assets/js/monline-location.js');
        $this->assertStringNotContainsString('removeUndeliverable', $js);
        $this->assertStringNotContainsString('STATE_GST', $js, 'the GST-state mapping is store-checkout-specific, not needed for a sort-only picker');
    }

    /**
     * The location picker modal and script must be buyer-only, per the decision that
     * anonymous visitors get no location sort and no picker before login.
     */
    public function testLocationPickerIsGatedOnIsBuyer(): void
    {
        $layout = $this->read('Views/monline/_layout.php');

        $this->assertMatchesRegularExpression(
            "/if \(! empty\(\\\$isBuyer\)\): \?>\s*\n\s*<\?= \\\$this->include\('monline\/_location_modal'\)/",
            $layout,
            'the location modal must only render for a signed-in buyer',
        );
        $this->assertStringContainsString('monline-location.js', $layout);
        $this->assertStringContainsString('openMonlineLocationPicker', $layout);
    }

    /** JS is served from public/, not assets/ — the two copies must stay identical. */
    public function testMonlineLocationScriptIsMirroredToPublic(): void
    {
        $src  = (string) file_get_contents(ROOTPATH . 'assets/js/monline-location.js');
        $pub  = (string) file_get_contents(FCPATH . 'assets/js/monline-location.js');
        $this->assertNotSame('', $src);
        $this->assertSame($src, $pub, 'assets/js/monline-location.js and public/assets/js/monline-location.js have drifted');
    }

    /** _product_card.php must show the computed distance whenever the row has one. */
    public function testProductCardShowsDistanceWhenPresent(): void
    {
        $src = $this->read('Views/monline/_product_card.php');

        $this->assertStringContainsString('distance_km', $src);
    }

    /** The hero must communicate the value of buying direct from a manufacturer. */
    public function testHomeHeroCommunicatesTheDirectFromManufacturerValueProp(): void
    {
        $src = $this->read('Views/monline/home.php');

        $this->assertStringContainsString('distributor', $src, 'the hero must mention skipping the distributor markup');
    }

    /** The location-override routes must exist and be CSRF-filtered, like every other mutating monline route. */
    public function testLocationRoutesAreRegisteredAndCsrfFiltered(): void
    {
        $routes = $this->read('Config/Routes.php');

        $this->assertMatchesRegularExpression(
            "/post\\('location', 'Monline\\\\CatalogController::setLocation', \\['filter' => 'csrf'\\]\\)/",
            $routes,
        );
        $this->assertMatchesRegularExpression(
            "/post\\('location\\/clear', 'Monline\\\\CatalogController::clearLocation', \\['filter' => 'csrf'\\]\\)/",
            $routes,
        );
    }

    /** The controller must derive that flag from a resolved buyer, not a bare login. */
    public function testControllerOptsIntoPricesOnlyForAResolvedBuyer(): void
    {
        $ctrl = $this->read('Controllers/Monline/CatalogController.php');
        $base = $this->read('Controllers/Monline/BaseMonlineController.php');

        $this->assertStringContainsString('$withPrices = $this->isBuyer();', $ctrl);

        // isBuyer() must require BOTH a vendor principal and a resolvable vendor row.
        // isLoggedIn alone would let a customer or rider session see wholesale pricing.
        $this->assertStringContainsString("principal_type') === 'vendor'", $base);
        $this->assertStringContainsString('buyerVendorId() !== null', $base);
    }

    /**
     * No monline view may print a price outside a showPrices guard.
     *
     * Covers '_product_card' and 'home' too: once the catalogue becomes publicly
     * browsable, price markup moves into the shared card partial and onto the landing
     * page, and this test must follow it there or it silently stops asserting anything.
     */
    public function testViewsGatePriceOutputOnShowPrices(): void
    {
        foreach (['browse', 'product', '_product_card', 'home'] as $view) {
            $src = (string) file_get_contents(APPPATH . "Views/monline/{$view}.php");

            $this->assertStringContainsString(
                'showPrices',
                $src,
                "monline/{$view}.php prints prices without checking showPrices",
            );
            // Every base_price echo must sit after a showPrices check in the same file.
            $guard = strpos($src, 'showPrices');
            $price = strpos($src, 'base_price');
            if ($price !== false) {
                $this->assertLessThan($price, $guard, "monline/{$view}.php echoes a price before the guard");
            }
        }
    }

    /**
     * Browsing must be public. home() used to render nothing but a sign-in wall for a
     * logged-out visitor — no catalogue call at all — which contradicts the requirement
     * that anyone can browse products and only the price stays gated.
     */
    public function testHomeShowsCatalogueToEveryVisitor(): void
    {
        $src = $this->read('Controllers/Monline/CatalogController.php');

        $this->assertStringNotContainsString(
            "if (! \$this->isBuyer()) {\n            return \$this->render('monline/home', 'Wholesale marketplace');",
            $src,
            'home() must not gate the whole catalogue behind isBuyer() — only the price may be gated',
        );

        $body = $this->methodBody($src, 'home');
        $this->assertStringContainsString('$withPrices = $this->isBuyer();', $body);
        $this->assertStringContainsString('$repo->products(', $body, 'home() must fetch products unconditionally');
        $this->assertStringContainsString("'showPrices'", $body);
    }

    // ------------------------------------------------------- making price containment

    /** making_price must appear nowhere in the monline surface — controllers or views. */
    public function testMakingPriceNeverReachesMonline(): void
    {
        $files = array_merge(
            glob(APPPATH . 'Controllers/Monline/*.php') ?: [],
            glob(APPPATH . 'Views/monline/*.php') ?: [],
            [APPPATH . 'Models/MonlineCatalogRepository.php'],
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
        $cart = $this->read('Libraries/Monline/MonlineCart.php');
        $this->assertStringNotContainsString('price', $cart, 'the cart must carry quantities only, never prices');
    }

    /**
     * Placement must re-check the manufacturer's ACCOUNT status, not just party_type.
     *
     * MonlineCatalogRepository::base() already hides an unapproved/rejected/suspended
     * manufacturer from browsing. Without the same predicate at placement, a buyer
     * holding a stale or guessed variant id can still place a real purchase order
     * against one — the catalogue gate is not a security boundary on its own.
     */
    public function testPlacementRejectsAnUnapprovedManufacturer(): void
    {
        $src = $this->read('Models/PurchaseOrderRepository.php');

        $this->assertStringContainsString(
            "whereIn('v.status', ['approved', 'active'])",
            $this->methodBody($src, 'resolveLines'),
            'resolveLines() must reject a variant whose manufacturer is not approved/active — '
            . 'browsing already hides these, and placement must match or the gate is bypassable',
        );
    }

    /**
     * A single unavailable cart line must not block every other line in the order.
     *
     * resolveLines() used to hard-fail the WHOLE cart the moment any one variant id
     * failed to resolve — and because the cart page itself never removed the bad id
     * either, the buyer had no way to discover or clear it. Every future "Place" click
     * failed identically, forever. Unresolvable ids must now be dropped and reported,
     * not treated as a reason to refuse everything else.
     */
    public function testAnUnresolvableLineDoesNotBlockTheRestOfTheOrder(): void
    {
        $body = $this->methodBody($this->read('Models/PurchaseOrderRepository.php'), 'resolveLines');

        $this->assertStringNotContainsString(
            "return ['error' => 'One of the items is no longer available.', 'rows' => []];",
            $body,
            'an unresolvable id must no longer hard-fail the entire cart',
        );
        $this->assertStringContainsString('$dropped[] = $vid;', $body);
        $this->assertStringContainsString('continue;', $body);

        // placeFromCart() must surface what got dropped so the controller can act on it.
        $this->assertStringContainsString(
            "'dropped' => \$lines['dropped']",
            $this->methodBody($this->read('Models/PurchaseOrderRepository.php'), 'placeFromCart'),
        );
    }

    /** The cart page must purge stale lines on view, not just silently omit them. */
    public function testCartViewPurgesLinesThatNoLongerResolve(): void
    {
        $body = $this->methodBody($this->read('Controllers/Monline/OrderController.php'), 'cart');

        $this->assertStringContainsString('array_diff(array_keys($raw)', $body, 'the cart must detect ids present in session but absent from the resolved lines');
        $this->assertStringContainsString('$cartSvc->remove(', $body, 'a stale id must actually be removed from the session cart');
        $this->assertStringContainsString("'removedCount'", $body, 'the view needs to be told something was removed, or it stays silent');
    }

    /** A cart add for an unresolvable product must be REJECTED, never silently accepted. */
    public function testAddRejectsWhenTheProductCannotBeResolved(): void
    {
        $body = $this->methodBody($this->read('Controllers/Monline/OrderController.php'), 'add');

        $this->assertStringContainsString(
            'if ($rules === null)',
            $body,
            'add() must reject when findBySlug() returns null (e.g. an unapproved manufacturer) '
            . 'rather than falling through and adding the raw POSTed variant_id unvalidated',
        );

        // The rejection must return BEFORE the raw POSTed variant/qty reaches the cart.
        $nullCheck = strpos($body, 'if ($rules === null)');
        $cartAdd   = strpos($body, "service('monlineCart')->add(");
        $this->assertNotFalse($nullCheck);
        $this->assertNotFalse($cartAdd);
        $this->assertLessThan($nullCheck === false ? 0 : $cartAdd, $nullCheck, 'the null guard must precede the cart write');

        $between = substr($body, $nullCheck, $cartAdd - $nullCheck);
        $this->assertStringContainsString('return redirect()', $between, 'the null branch must return, not merely check');
    }

    /**
     * add() must reject a variant id that doesn't actually belong to the posted slug.
     *
     * The MOQ check validates $qty against $rules (derived from slug), but nothing
     * compared $rules['variant_id'] to the independently-posted variant_id — a tampered
     * hidden field could validate against one product's MOQ while enqueuing a different
     * variant entirely.
     */
    public function testAddRejectsAMismatchedVariantIdAndSlug(): void
    {
        $body = $this->methodBody($this->read('Controllers/Monline/OrderController.php'), 'add');

        $this->assertStringContainsString(
            "(int) (\$rules['variant_id'] ?? 0) !== \$variantId",
            $body,
            'add() must verify the posted variant_id actually belongs to the posted slug',
        );
    }

    /** cart/update must re-validate MOQ/step — add() does, update() must too. */
    public function testCartUpdateValidatesMoqAndStep(): void
    {
        $body = $this->methodBody($this->read('Controllers/Monline/OrderController.php'), 'update');

        $this->assertStringContainsString(
            'PurchaseRules::validate',
            $body,
            'update() must validate the new quantity — a buyer could otherwise set a qty below '
            . 'MOQ or off-step with no error until placement',
        );
    }

    /**
     * browse() must support paging — otherwise a catalogue past the first 48 products
     * (the default limit()) has no UI path to reach the rest at all.
     */
    public function testBrowseSupportsPagination(): void
    {
        $body = $this->methodBody($this->read('Controllers/Monline/CatalogController.php'), 'browse');

        $this->assertStringContainsString("getGet('page')", $body, 'browse() never reads a page parameter');
        $this->assertStringContainsString("'offset'", $body, "the page must translate into MonlineCatalogRepository's offset option");

        $view = $this->read('Views/monline/browse.php');
        $this->assertStringContainsString('page', $view, 'browse.php has no pagination controls at all');
    }

    /** The cart badge must read from one source everywhere, not disagree page to page. */
    public function testCartCountIsConsistentAcrossPages(): void
    {
        $cartBody = $this->methodBody($this->read('Controllers/Monline/OrderController.php'), 'cart');

        $this->assertStringNotContainsString(
            'count($lines)',
            $cartBody,
            "cart() must not report a different count than every other page's nav badge",
        );
        $this->assertStringContainsString('$cartSvc->count()', $cartBody);
    }

    // ------------------------------------------------------------------ stylesheet

    /**
     * The anchor colour rule must stay UNSCOPED.
     *
     * `.mo-body a` has specificity (0,1,1), which beats Bootstrap's
     * `.btn { color: var(--bs-btn-color) }` (0,1,0) and every .mo-* component that
     * sets its own colour (.mo-brand, .mo-catlink, .mo-ptitle, .mo-gate). The visible
     * result is anchor-buttons rendered violet-on-violet — a solid rectangle with
     * invisible text. A bare `a` is (0,0,1) and correctly loses to all of them, which
     * is exactly why store.css writes it that way.
     */
    public function testAnchorColourRuleIsNotScopedAboveComponentClasses(): void
    {
        $css = (string) file_get_contents(ROOTPATH . 'assets/css/monline.css');

        $this->assertMatchesRegularExpression(
            '/^a \{[^}]*color:/m',
            $css,
            'monline.css must set the anchor colour with a bare `a` selector',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^\.mo-body a \{/m',
            $css,
            'scoping the anchor rule to .mo-body makes it outrank .btn and every .mo-* '
            . 'component colour — anchor-buttons render with invisible text',
        );
    }

    /** The two CSS copies are served from different roots and must not drift. */
    public function testMonlineStylesheetIsMirroredToPublic(): void
    {
        $src = (string) file_get_contents(ROOTPATH . 'assets/css/monline.css');
        $pub = (string) file_get_contents(FCPATH . 'assets/css/monline.css');

        $this->assertNotSame('', $src);
        $this->assertSame($src, $pub, 'assets/css/monline.css and public/assets/css/monline.css have drifted');
    }

    // ----------------------------------------------------------- homepage sections

    /** categories() must exist and stay scoped to the same manufacturer-only base(). */
    public function testCategoriesMethodExistsAndIsScopedToManufacturers(): void
    {
        $src  = $this->read('Models/MonlineCatalogRepository.php');
        $body = $this->methodBody($src, 'categories');

        $this->assertNotSame('', $body, 'MonlineCatalogRepository::categories() is missing');
        $this->assertStringContainsString('this->base()', $body, 'categories() must reuse base(), not a separate unscoped query');
        $this->assertStringContainsString('product_count', $body);
    }

    /** render() must expose category data for the nav row, on every monline page. */
    public function testRenderExposesNavCategories(): void
    {
        $body = $this->methodBody($this->read('Controllers/Monline/BaseMonlineController.php'), 'render');

        $this->assertStringContainsString("'navCategories'", $body);
        $this->assertStringContainsString('categories()', $body);
    }

    /** The homepage must offer category browsing and featured manufacturers, not just a flat product grid. */
    public function testHomeHasCategoryAndManufacturerSections(): void
    {
        $src = $this->read('Views/monline/home.php');

        $this->assertStringContainsString('navCategories', $src, 'home.php must render a category browsing section');
        $this->assertStringContainsString('manufacturers', $src, 'home.php must render a featured-manufacturers section');
    }

    /** home() must fetch the manufacturer list for the featured-manufacturers section. */
    public function testHomeControllerFetchesManufacturers(): void
    {
        $body = $this->methodBody($this->read('Controllers/Monline/CatalogController.php'), 'home');

        $this->assertStringContainsString("'manufacturers'", $body);
        $this->assertStringContainsString('manufacturers()', $body);
    }

    /** browse.php must expose the category filter — the backend already supports it. */
    public function testBrowseHasACategoryFilter(): void
    {
        $src = $this->read('Views/monline/browse.php');

        $this->assertStringContainsString('name="category"', $src);
    }

    /** Only manufacturers may be sold on monline. */
    public function testOnlyManufacturerProductsAreSellable(): void
    {
        $this->assertStringContainsString(
            "where('v.party_type', 'manufacturer')",
            $this->read('Models/PurchaseOrderRepository.php'),
            'placement must reject a non-manufacturer variant',
        );
        $this->assertStringContainsString(
            "where('v.party_type', 'manufacturer')",
            $this->read('Models/MonlineCatalogRepository.php'),
            'the catalogue must show manufacturers only',
        );
    }

    // -------------------------------------------------------------- cart isolation

    /**
     * The B2B cart must NOT reuse the consumer cart's session key. The cookie is
     * domain-wide, so one browser shares a session between shiplore.in and
     * monline.shiplore.in — the same key would merge a shopper's basket with a
     * vendor's wholesale order.
     */
    public function testB2bCartUsesItsOwnSessionKey(): void
    {
        $b2b      = $this->read('Libraries/Monline/MonlineCart.php');
        $consumer = $this->read('Libraries/Store/CartService.php');

        // Anchor to the real declaration (line-start + indentation), not a docblock
        // mention — MonlineCart's docblock quotes 'store_cart' while explaining why it
        // is deliberately NOT reused, and an unanchored match grabs that instead.
        preg_match("/^\s+private const KEY = '([a-z_]+)'/m", $b2b, $mB2b);
        preg_match("/^\s+private const KEY = '([a-z_]+)'/m", $consumer, $mCon);

        $this->assertNotEmpty($mB2b, 'MonlineCart has no KEY constant');
        $this->assertNotEmpty($mCon, 'CartService has no KEY constant');
        $this->assertNotSame(
            $mCon[1],
            $mB2b[1],
            'the B2B cart shares the consumer cart session key — baskets would merge',
        );
    }

    /** BuyerLocationService must be registered as a CI4 service. */
    public function testMonlineLocationServiceIsRegistered(): void
    {
        $this->assertInstanceOf(
            \App\Libraries\Monline\BuyerLocationService::class,
            service('monlineLocationService'),
        );
    }

    /**
     * The monline location override must NOT reuse the storefront's location session
     * key either — same domain-wide-cookie hazard as the cart. A buyer changing where
     * shiplore.in delivers their groceries must never silently change what monline
     * sorts by, and vice versa.
     */
    public function testMonlineLocationUsesItsOwnSessionKey(): void
    {
        $b2b      = $this->read('Libraries/Monline/BuyerLocationService.php');
        $consumer = $this->read('Libraries/Store/LocationService.php');

        preg_match("/^\s+private const KEY = '([a-z_]+)'/m", $b2b, $mB2b);
        preg_match("/^\s+private const KEY = '([a-z_]+)'/m", $consumer, $mCon);

        $this->assertNotEmpty($mB2b, 'BuyerLocationService has no KEY constant');
        $this->assertNotEmpty($mCon, 'LocationService has no KEY constant');
        $this->assertNotSame(
            $mCon[1],
            $mB2b[1],
            'the monline location override shares the storefront location session key',
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

    /** Receipt must go through an explicit status check and raise stock via InventoryService. */
    public function testReceiptRaisesStockThroughInventoryService(): void
    {
        $body = $this->methodBody($this->read('Models/PurchaseOrderRepository.php'), 'receive');

        // Not canPurchaseOrder(): receive() deliberately does NOT call it (see
        // testReceiptCannotBeAppliedTwice) — that string only ever matched this
        // method's own explanatory comment, in both the old code and the new,
        // so it proved nothing about the actual gate.
        $this->assertStringContainsString("'dispatched'", $body, 'receipt must validate the starting status');
        $this->assertStringContainsString("service('inventoryService')", $body);
        $this->assertStringContainsString("'ref_type' => 'mfg_purchase_order'", $body, 'the ledger row must reference the PO');
        $this->assertStringContainsString('buyer_shop_id', $body, 'stock must land at the destination shop');
        $this->assertStringContainsString('transBegin', $body, 'stock-in and status must move together');
    }

    /**
     * receive() must not be callable twice.
     *
     * StatusMachine::allowed() has an idempotent `$from === $to` shortcut — fine for
     * transitions that just rewrite a timestamp, but receive()'s side effect
     * (crediting stock via InventoryService) is NOT idempotent. Guarding solely with
     * canPurchaseOrder($from, 'received') lets a second call on an already-'received'
     * order pass that shortcut and credit stock a second time. receive() must check
     * the starting status explicitly rather than rely on that shortcut.
     *
     * Audit M17: that check must also read the status under a `FOR UPDATE` lock taken
     * INSIDE the transaction, not the pre-transaction $found read — otherwise two
     * concurrent "mark received" calls (a double-tapped button, or a retry) both read
     * 'dispatched', both pass, and both credit stock. Hence $locked['status'], not
     * $found['po']['status'].
     */
    public function testReceiptCannotBeAppliedTwice(): void
    {
        $body = $this->methodBody($this->read('Models/PurchaseOrderRepository.php'), 'receive');

        $this->assertStringContainsString('FOR UPDATE', $body, 'the status re-check must happen under a row lock, or two concurrent calls both pass it');
        $this->assertStringContainsString(
            "\$locked['status'] !== 'dispatched'",
            $body,
            "receive() must explicitly require the LOCKED row's status === 'dispatched' — canPurchaseOrder() alone "
            . "would let a second call on an already-'received' order through its idempotent "
            . '$from===$to shortcut and double-credit stock',
        );

        // The guard must run before stock is actually credited. Not
        // strpos(..., "service('inventoryService')") — that matches
        // `$service = service('inventoryService')`, which only resolves the service
        // and runs before transBegin() in both the old code and the new; the real
        // credit call is $service->receive(...), inside the loop after the guard.
        $guard  = strpos($body, "\$locked['status'] !== 'dispatched'");
        $credit = strpos($body, '$service->receive(');
        $this->assertNotFalse($guard);
        $this->assertNotFalse($credit);
        $this->assertLessThan($credit, $guard, 'the status guard must precede the stock credit');
    }

    /** A buyer may only route stock into a shop they are allowed to act on. */
    public function testDestinationShopIsCheckedAgainstTheBuyersAllowedShops(): void
    {
        $body = $this->methodBody($this->read('Controllers/Monline/OrderController.php'), 'place');

        $this->assertStringContainsString(
            'in_array($shopId, $this->buyerShopIds(), true)',
            $body,
            'a branch manager could otherwise route stock into a sibling branch',
        );
    }

    /** Every ordering action requires a buyer; browsing is the only anonymous surface. */
    public function testEveryOrderingActionRequiresABuyer(): void
    {
        $src = $this->read('Controllers/Monline/OrderController.php');
        preg_match_all('/public function (\w+)\s*\(/', $src, $m);

        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $method) {
            $this->assertStringContainsString(
                'requireBuyer',
                $this->methodBody($src, $method),
                "Monline\\OrderController::{$method}() does not require a buyer",
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
