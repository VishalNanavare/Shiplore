<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// ---- Per-subdomain root entry points ----
// Each panel host lands on its own login at the bare root; the apex
// (shiplorelocal.in) shows the customer storefront. `subdomain` is
// domain-agnostic so this also works on production (shiplore.in).
$routes->get('/', 'Auth\LoginController::show',      ['subdomain' => 'admin']);   // admin.  -> staff login (-> admin dashboard if authed)
$routes->get('/', 'Auth\LoginController::show',      ['subdomain' => 'vendor']);  // vendor. -> staff login (-> vendor dashboard if authed)
$routes->get('/', 'Auth\LoginController::show',      ['subdomain' => 'shop']);    // shop.   -> staff login (shop managers are vendor-panel staff)
$routes->get('/', 'Rider\AuthController::loginForm', ['subdomain' => 'rider']);   // rider.  -> rider login
// Manufacturer surfaces. manufacturer./mshop. mirror vendor./shop. — owner login vs
// unit-staff login, both landing in the manufacturer panel. monline. is the B2B
// marketplace. These MUST stay above the apex fallback: a subdomain route registers
// with overwrite, but the bare '/' below does not, so whichever is declared first wins.
$routes->get('/', 'Auth\LoginController::show',      ['subdomain' => 'manufacturer']); // manufacturer. -> staff login
$routes->get('/', 'Auth\LoginController::show',      ['subdomain' => 'mshop']);        // mshop. -> unit-staff login
// monline. MUST have its own root route. Without one the bare '/' falls through to the
// apex route below and serves the CONSUMER storefront on the B2B hostname — wrong
// catalogue, wrong audience, consumer pricing. The full marketplace lands in phase B;
// this entry point is deliberately live ahead of it so the subdomain is never wrong.
$routes->get('/', 'Monline\CatalogController::home', ['subdomain' => 'monline']);   // monline. -> B2B marketplace
$routes->get('/', 'Store\StoreController::home');                                  // apex shiplorelocal.in -> ecommerce homepage

// ---- Media (private files served by uuid) ----
$routes->get('media/(:segment)', 'MediaController::serve/$1');

// ---- Staff (admin/vendor) session auth (Phase 5, web) ----
$routes->get('login', 'Auth\LoginController::show');        // staff login page
$routes->post('login', 'Auth\LoginController::attempt', ['filter' => ['csrf', 'throttle:10,60']]);
$routes->post('login/otp', 'Auth\LoginController::otpLogin', ['filter' => ['csrf', 'throttle:10,60']]); // Firebase phone-OTP sign-in
$routes->post('logout', 'Auth\LoginController::logout', ['filter' => 'csrf']);
// Shared topbar notification feed (JSON) — any logged-in staff user.
$routes->get('notifications/feed', 'NotificationFeedController::feed', ['filter' => 'webAuth']);
$routes->addRedirect('admin', 'admin/dashboard');          // bare /admin -> dashboard (webAuth-guarded)

// CSP violation reports (audit M10). Browser-sent, no session, no CSRF token — same
// "hash/shape-verified, no CSRF needed" pattern as the PayU redirect-backs below.
$routes->post('csp-report', 'CspReportController::collect');

// Vendor self-registration (Firebase mobile auth + email code + GST-API verification)
$routes->get('register', 'Auth\RegisterController::show');
$routes->post('register/send-codes', 'Auth\RegisterController::sendCodes', ['filter' => ['csrf', 'throttle:5,60']]);
$routes->post('register/otp-ticket', 'Auth\RegisterController::mobileOtpTicket', ['filter' => ['csrf', 'throttle:5,60']]);
$routes->post('register/verify-mobile', 'Auth\RegisterController::verifyMobile', ['filter' => ['csrf', 'throttle:10,60']]);
$routes->post('register/resend/email', 'Auth\RegisterController::resendEmail', ['filter' => ['csrf', 'throttle:3,300']]);
$routes->post('register/complete', 'Auth\RegisterController::complete', ['filter' => 'csrf']);
$routes->post('register/cancel', 'Auth\RegisterController::cancel', ['filter' => 'csrf']);

// Manufacturer self-registration. Mirrors the vendor flow above (Firebase mobile OTP +
// emailed code + GST verification) but collects NO delivery range and creates a
// principal_type='manufacturer' account whose first location is an `mshops` row.
// Same throttles as the vendor flow — these send SMS and email to a third party.
$routes->get('manufacturer-register', 'Auth\ManufacturerRegisterController::show');
$routes->post('manufacturer-register/send-codes', 'Auth\ManufacturerRegisterController::sendCodes', ['filter' => ['csrf', 'throttle:5,60']]);
$routes->post('manufacturer-register/otp-ticket', 'Auth\ManufacturerRegisterController::mobileOtpTicket', ['filter' => ['csrf', 'throttle:5,60']]);
$routes->post('manufacturer-register/verify-mobile', 'Auth\ManufacturerRegisterController::verifyMobile', ['filter' => ['csrf', 'throttle:10,60']]);
$routes->post('manufacturer-register/resend/email', 'Auth\ManufacturerRegisterController::resendEmail', ['filter' => ['csrf', 'throttle:3,300']]);
$routes->post('manufacturer-register/complete', 'Auth\ManufacturerRegisterController::complete', ['filter' => 'csrf']);
$routes->post('manufacturer-register/cancel', 'Auth\ManufacturerRegisterController::cancel', ['filter' => 'csrf']);

// Password reset (Phase 5, web)
$routes->get('forgot-password', 'Auth\ForgotPasswordController::showForgot');
$routes->post('forgot-password', 'Auth\ForgotPasswordController::sendReset', ['filter' => ['csrf', 'throttle:5,300']]);
$routes->get('reset-password', 'Auth\ForgotPasswordController::showReset');
$routes->post('reset-password', 'Auth\ForgotPasswordController::doReset', ['filter' => 'csrf']);

// ---- Customer storefront (Phase 8) — public; account pages guarded in controller ----
$routes->group('store', static function (RouteCollection $routes): void {
    $routes->get('/', 'Store\StoreController::home');
    $routes->get('location', 'Store\StoreController::location');
    $routes->post('location', 'Store\StoreController::setLocation', ['filter' => 'csrf']);
    $routes->get('shops', 'Store\StoreController::shops');
    $routes->get('shop/(:num)', 'Store\StoreController::shop/$1');
    $routes->get('category/(:segment)', 'Store\StoreController::category/$1');
    $routes->get('products', 'Store\StoreController::products');
    $routes->get('product/(:num)/variants', 'Store\StoreController::variantsSheet/$1'); // quick-add bottom-sheet (AJAX)
    $routes->get('product/(:segment)', 'Store\StoreController::product/$1');
    $routes->post('product/(:num)/review', 'Store\AccountController::submitReview/$1', ['filter' => 'csrf']);
    $routes->get('wishlist', 'Store\StoreController::wishlist');
    $routes->post('wishlist/add', 'Store\StoreController::wishlistAdd', ['filter' => 'csrf']);
    $routes->post('wishlist/remove', 'Store\StoreController::wishlistRemove', ['filter' => 'csrf']);

    // Cart + checkout
    $routes->get('cart', 'Store\CheckoutController::cart');
    $routes->get('cart/mini', 'Store\CheckoutController::miniCart');   // slide-over drawer body
    $routes->post('cart/add', 'Store\CheckoutController::addToCart', ['filter' => 'csrf']);
    $routes->post('cart/update', 'Store\CheckoutController::updateCart', ['filter' => 'csrf']);
    $routes->post('cart/remove', 'Store\CheckoutController::removeCart', ['filter' => 'csrf']);
    $routes->post('cart/coupon', 'Store\CheckoutController::applyCoupon', ['filter' => 'csrf']);
    // Stepped checkout (Blinkit-style): select address → add address (map) → payment
    $routes->get('checkout', 'Store\CheckoutController::checkout');                              // step 1: choose address
    $routes->get('checkout/add-address', 'Store\CheckoutController::addAddressForm');            // step 2: map + form (full page)
    $routes->get('checkout/address-form', 'Store\CheckoutController::addressForm');              // add/edit form fragment (modal)
    $routes->post('checkout/save-address', 'Store\CheckoutController::saveAddress', ['filter' => 'csrf']);
    $routes->post('checkout/delete-address/(:num)', 'Store\CheckoutController::deleteAddress/$1', ['filter' => 'csrf']);
    $routes->post('checkout/use-address/(:num)', 'Store\CheckoutController::useAddress/$1', ['filter' => 'csrf']);
    $routes->get('checkout/payment', 'Store\CheckoutController::payment');                       // step 3: payment
    $routes->post('checkout/place', 'Store\CheckoutController::place', ['filter' => 'csrf']);
    $routes->post('pay/payu/success', 'Store\PaymentController::payuSuccess'); // PayU redirect-back; hash-verified, no CSRF needed
    $routes->post('pay/payu/failure', 'Store\PaymentController::payuFailure'); // PayU redirect-back on failure
    $routes->get('pay/(:segment)', 'Store\PaymentController::pay/$1');
    $routes->get('order/(:segment)', 'Store\CheckoutController::confirmation/$1');
    $routes->get('track', 'Store\CheckoutController::trackForm');
    $routes->get('track/(:segment)', 'Store\CheckoutController::track/$1');
    $routes->post('deliveries/(:num)/rate', 'Store\AccountController::rateDelivery/$1', ['filter' => 'csrf']);
    $routes->post('deliveries/(:num)/dispute', 'Store\AccountController::raiseDispute/$1', ['filter' => 'csrf']);

    // Customer auth + account
    $routes->get('login', 'Store\AccountController::loginForm');
    $routes->post('login', 'Store\AccountController::sendCode', ['filter' => ['csrf', 'throttle:5,60']]);
    $routes->post('login/email', 'Store\AccountController::sendCodeEmail', ['filter' => ['csrf', 'throttle:5,60']]);
    $routes->post('login/otp', 'Store\AccountController::otpLogin', ['filter' => ['csrf', 'throttle:5,60']]);
    $routes->post('login/verify', 'Store\AccountController::verify', ['filter' => ['csrf', 'throttle:5,60']]);
    $routes->post('logout', 'Store\AccountController::logout', ['filter' => 'csrf']);
    $routes->get('account', 'Store\AccountController::index');
    $routes->post('account/profile', 'Store\AccountController::updateProfile', ['filter' => 'csrf']);
    $routes->get('account/orders', 'Store\AccountController::orders');
    $routes->get('account/orders/(:segment)/fragment', 'Store\AccountController::orderFragment/$1'); // modal body (AJAX)
    $routes->get('account/orders/(:segment)', 'Store\AccountController::orderDetail/$1');
    $routes->post('account/orders/(:segment)/cancel', 'Store\AccountController::cancelOrder/$1', ['filter' => 'csrf']);
    $routes->post('account/orders/(:segment)/cancel-item/(:num)', 'Store\AccountController::cancelOrderItem/$1/$2', ['filter' => 'csrf']);
    $routes->post('account/orders/(:segment)/return', 'Store\AccountController::submitOrderReturn/$1', ['filter' => 'csrf']);
    $routes->get('account/addresses', 'Store\AccountController::addresses');
    $routes->post('account/addresses', 'Store\AccountController::addAddress', ['filter' => 'csrf']);
    $routes->get('account/address-form', 'Store\AccountController::addressForm');                 // add/edit form fragment (modal)
    $routes->post('account/address/save', 'Store\AccountController::saveMapAddress', ['filter' => 'csrf']); // map-based modal
    $routes->post('account/addresses/(:num)/delete', 'Store\AccountController::deleteAddress/$1', ['filter' => 'csrf']);
    $routes->get('account/notifications', 'Store\AccountController::notifications');
    $routes->get('account/invoices/(:num)/pdf', 'Store\AccountController::invoicePdf/$1');
    $routes->get('account/support', 'Store\AccountController::support');
    $routes->post('account/return', 'Store\AccountController::submitReturn', ['filter' => 'csrf']);
});

// ---- Rider web panel (standalone; own mobile-OTP session, not webAuth) ----
// Rider public routes (login/logout — no auth required)
//
// 'subdomain' restricts this whole group to rider.shiplore.in — see
// PanelSubdomainIsolationTest. Without it, rider/login (and everything else here)
// resolved identically on the apex and every other panel's subdomain, the exact
// leak reported for monline (https://shiplore.in/monline/browse serving the same
// page as monline.shiplore.in). CI4 simply never registers the route when the
// host doesn't match, so a wrong-host request gets the framework's normal 404.
$routes->group('rider', ['subdomain' => 'rider'], static function (RouteCollection $routes): void {
    $routes->get('login', 'Rider\AuthController::loginForm');
    $routes->post('login', 'Rider\AuthController::sendCode', ['filter' => ['csrf', 'throttle:5,60']]);
    $routes->post('login/otp', 'Rider\AuthController::otpLogin', ['filter' => ['csrf', 'throttle:5,60']]);
    $routes->post('login/verify', 'Rider\AuthController::verify', ['filter' => ['csrf', 'throttle:5,60']]);
    $routes->post('logout', 'Rider\AuthController::logout', ['filter' => 'csrf']);
});

// Rider protected routes (session-guarded via riderAuth)
$routes->group('rider', ['filter' => 'riderAuth', 'subdomain' => 'rider'], static function (RouteCollection $routes): void {
    $routes->get('dashboard', 'Rider\DashboardController::index');
    $routes->get('poll', 'Rider\DashboardController::poll');               // live offers + stats (JSON)
    $routes->get('earnings', 'Rider\DashboardController::earnings');
    $routes->get('performance', 'Rider\DashboardController::performance');
    $routes->post('availability', 'Rider\DashboardController::setAvailability', ['filter' => 'csrf']);
    $routes->post('location', 'Rider\DashboardController::location', ['filter' => 'csrf']);

    $routes->get('route', 'Rider\DeliveryController::route');
    $routes->get('deliveries', 'Rider\DeliveryController::index');
    $routes->get('deliveries/(:num)', 'Rider\DeliveryController::show/$1');
    $routes->post('deliveries/(:num)/accept', 'Rider\DeliveryController::accept/$1', ['filter' => 'csrf']);
    $routes->post('deliveries/(:num)/decline', 'Rider\DeliveryController::decline/$1', ['filter' => 'csrf']);
    $routes->post('deliveries/(:num)/status', 'Rider\DeliveryController::status/$1', ['filter' => 'csrf']);
    $routes->post('deliveries/(:num)/pod', 'Rider\DeliveryController::pod/$1', ['filter' => 'csrf']);
    $routes->post('deliveries/(:num)/cod', 'Rider\DeliveryController::cod/$1', ['filter' => 'csrf']);

    $routes->get('documents', 'Rider\DocumentController::index');
    $routes->post('documents/upload', 'Rider\DocumentController::upload', ['filter' => 'csrf']);
    $routes->post('documents/(:num)/delete', 'Rider\DocumentController::remove/$1', ['filter' => 'csrf']);
});

// Exit impersonation. Deliberately declared BEFORE and OUTSIDE the `webAuth:platform`
// group below (first-declaration-wins, per the pattern already used elsewhere in this
// file): while an admin is inside a vendor/manufacturer portal, their principal_type
// is rewritten to 'vendor'/'manufacturer' by PortalController::startStaffImpersonation(),
// so the platform pin would block the only way back the moment
// auth.enforcePrincipalType=true is set. Plain `webAuth` still requires a live
// session; leave() itself refuses to do anything without a valid is_impersonating
// stash, so a non-impersonating user posting here just no-ops back to the dashboard.
$routes->post('admin/portal/leave', 'Admin\PortalController::leave', ['filter' => ['webAuth', 'csrf']]);

// ---- Authenticated admin web pages (session-guarded) ----
// `webAuth:platform` pins this group to platform principals. The session cookie is
// domain-wide (.shiplore.in), so without the argument a vendor login is accepted
// here too. Log-only until auth.enforcePrincipalType=true — see App\Filters\WebAuthFilter.
// 'subdomain' pins the whole group to admin.shiplore.in — see the comment on the
// rider group above and PanelSubdomainIsolationTest.
$routes->group('admin', ['filter' => 'webAuth:platform', 'subdomain' => 'admin'], static function (RouteCollection $routes): void {
    $routes->get('dashboard', 'AdminController::dashboard');

    // "Go to Portal" — admin impersonation into vendor/shop/rider portals + return.
    $routes->post('portal/enter/vendor/(:num)', 'Admin\PortalController::enterVendor/$1', ['filter' => 'csrf']);
    $routes->post('portal/enter/manufacturer/(:num)', 'Admin\PortalController::enterManufacturer/$1', ['filter' => 'csrf']);
    $routes->post('portal/enter/shop/(:num)', 'Admin\PortalController::enterShop/$1', ['filter' => 'csrf']);
    $routes->post('portal/enter/rider/(:num)', 'Admin\PortalController::enterRider/$1', ['filter' => 'csrf']);

    // Vendor management
    $routes->get('vendors', 'Admin\VendorController::index');
    $routes->get('vendors/new', 'Admin\VendorController::new');
    $routes->get('vendors/(:num)', 'Admin\VendorController::show/$1');
    $routes->post('vendors/store', 'Admin\VendorController::store', ['filter' => 'csrf']);
    $routes->get('vendors/(:num)/edit', 'Admin\VendorController::edit/$1');
    $routes->post('vendors/(:num)/update', 'Admin\VendorController::update/$1', ['filter' => 'csrf']);
    $routes->post('vendors/(:num)/approve', 'Admin\VendorController::approve/$1', ['filter' => 'csrf']);
    $routes->post('vendors/(:num)/reject', 'Admin\VendorController::reject/$1', ['filter' => 'csrf']);
    $routes->get('vendors/(:num)/documents', 'Admin\VendorController::documents/$1');
    $routes->get('vendors/(:num)/statement', 'Admin\VendorController::statement/$1');
    $routes->post('vendors/(:num)/documents/(:num)/verify', 'Admin\VendorController::verifyDoc/$1/$2', ['filter' => 'csrf']);
    $routes->post('vendors/(:num)/documents/(:num)/reject', 'Admin\VendorController::rejectDoc/$1/$2', ['filter' => 'csrf']);
    $routes->get('vendors/type-mismatches', 'Admin\VendorTypeMismatchController::index');
    $routes->get('vendors/(:num)/product-mismatches', 'Admin\VendorTypeMismatchController::show/$1');
    $routes->post('vendors/(:num)/product-mismatches/reassign', 'Admin\VendorTypeMismatchController::reassign/$1', ['filter' => 'csrf']);
    $routes->post('vendors/auto-fix-type-mismatches', 'Admin\VendorTypeMismatchController::autoFix', ['filter' => 'csrf']);

    // Manufacturer management. Separate from vendors: they share the `vendors` table but
    // not the screens, and deliberately not the permissions — manufacturer.approve must
    // not be implied by vendor.approve.
    $routes->get('manufacturers', 'Admin\ManufacturerController::index');
    $routes->get('manufacturers/(:num)', 'Admin\ManufacturerController::show/$1');
    $routes->post('manufacturers/(:num)/approve', 'Admin\ManufacturerController::approve/$1', ['filter' => 'csrf']);
    $routes->post('manufacturers/(:num)/reject', 'Admin\ManufacturerController::reject/$1', ['filter' => 'csrf']);

    // monline B2B purchase-order oversight. Read-first; the one write is a force-cancel.
    $routes->get('purchase-orders', 'Admin\PurchaseOrderController::index');
    $routes->get('purchase-orders/(:num)', 'Admin\PurchaseOrderController::show/$1');
    $routes->post('purchase-orders/(:num)/cancel', 'Admin\PurchaseOrderController::cancel/$1', ['filter' => 'csrf']);

    // Shop management
    $routes->get('shops', 'Admin\ShopController::index');
    $routes->get('shops/new', 'Admin\ShopController::new');
    $routes->post('shops/store', 'Admin\ShopController::store', ['filter' => 'csrf']);
    $routes->get('shops/(:num)', 'Admin\ShopController::show/$1');
    $routes->get('shops/(:num)/edit', 'Admin\ShopController::edit/$1');
    $routes->post('shops/(:num)/update', 'Admin\ShopController::update/$1', ['filter' => 'csrf']);
    $routes->post('shops/(:num)/activate', 'Admin\ShopController::activate/$1', ['filter' => 'csrf']);
    $routes->post('shops/(:num)/deactivate', 'Admin\ShopController::deactivate/$1', ['filter' => 'csrf']);

    // Product approvals
    $routes->get('product-approvals', 'Admin\ProductApprovalController::index');
    $routes->post('product-approvals/bulk', 'Admin\ProductApprovalController::bulk', ['filter' => 'csrf']);
    $routes->post('product-approvals/(:num)/approve', 'Admin\ProductApprovalController::approve/$1', ['filter' => 'csrf']);
    $routes->post('product-approvals/(:num)/reject', 'Admin\ProductApprovalController::reject/$1', ['filter' => 'csrf']);
    $routes->post('product-approvals/(:num)/publish', 'Admin\ProductApprovalController::publish/$1', ['filter' => 'csrf']);
    $routes->post('product-approvals/(:num)/unpublish', 'Admin\ProductApprovalController::unpublish/$1', ['filter' => 'csrf']);
    $routes->post('product-approvals/(:num)/request-changes', 'Admin\ProductApprovalController::requestChanges/$1', ['filter' => 'csrf']);
    $routes->get('products/export', 'Admin\ProductController::export');
    $routes->post('products/ai-suggest', 'Admin\ProductController::aiSuggest', ['filter' => 'csrf']);
    // Draft + autosave (RB3)
    $routes->post('products/draft', 'Admin\ProductController::draft', ['filter' => 'csrf']);
    $routes->post('products/(:num)/autosave/(:segment)', 'Admin\ProductController::autosaveSection/$1/$2', ['filter' => 'csrf']);
    $routes->post('products/(:num)/duplicate', 'Admin\ProductController::duplicate/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/delete', 'Admin\ProductController::delete/$1', ['filter' => 'csrf']);
    $routes->post('products/bulk', 'Admin\ProductController::bulk', ['filter' => 'csrf']);
    $routes->get('products/trash', 'Admin\ProductController::trash');
    $routes->post('products/(:num)/restore', 'Admin\ProductController::restore/$1', ['filter' => 'csrf']);
    // Dynamic dependency lookups (RB2)
    $routes->get('lookup/vendors/(:num)/shops', 'Admin\ProductLookupController::shops/$1');
    $routes->get('lookup/vendors/(:num)/categories', 'Admin\ProductLookupController::categories/$1');
    $routes->get('lookup/categories/(:num)/attributes', 'Admin\ProductLookupController::attributes/$1');
    $routes->get('lookup/categories/(:num)/defaults', 'Admin\ProductLookupController::defaults/$1');
    $routes->get('lookup/categories/(:num)/brands', 'Admin\ProductLookupController::brands/$1');
    $routes->get('lookup/attributes/(:num)/values', 'Admin\ProductLookupController::attributeValues/$1');

    // Order management
    $routes->get('orders', 'Admin\OrderController::index');
    $routes->get('orders/export', 'Admin\OrderController::export');
    $routes->get('orders/(:num)', 'Admin\OrderController::show/$1');
    $routes->post('orders/(:num)/cancel', 'Admin\OrderController::cancel/$1', ['filter' => 'csrf']);
    $routes->post('orders/(:num)/release-claim', 'Admin\OrderController::releaseClaim/$1', ['filter' => 'csrf']);
    $routes->post('orders/(:num)/force-claim', 'Admin\OrderController::forceClaim/$1', ['filter' => 'csrf']);
    $routes->post('orders/(:num)/priority', 'Admin\OrderController::setPriority/$1', ['filter' => 'csrf']);
    $routes->post('orders/(:num)/override-delivery', 'Admin\OrderController::overrideDelivery/$1', ['filter' => 'csrf']);
    $routes->post('orders/(:num)/return-to-shop', 'Admin\OrderController::returnToShop/$1', ['filter' => 'csrf']);

    // Payments (read-only)
    $routes->get('payments', 'Admin\PaymentController::index');
    $routes->get('payments/(:num)', 'Admin\PaymentController::show/$1');

    // Refunds
    $routes->get('refunds', 'Admin\RefundController::index');
    $routes->get('refunds/(:num)', 'Admin\RefundController::show/$1');
    $routes->post('refunds/(:num)/process', 'Admin\RefundController::process/$1', ['filter' => 'csrf']);
    $routes->post('refunds/(:num)/fail', 'Admin\RefundController::fail/$1', ['filter' => 'csrf']);

    // Settlements
    $routes->get('settlements', 'Admin\SettlementController::index');
    $routes->post('settlements/run', 'Admin\SettlementController::run', ['filter' => 'csrf']);
    $routes->get('ledger', 'Admin\LedgerController::index');
    $routes->get('ledger/trial-balance', 'Admin\LedgerController::trialBalance');
    $routes->get('settlements/(:num)', 'Admin\SettlementController::show/$1');
    $routes->post('settlements/(:num)/approve', 'Admin\SettlementController::approve/$1', ['filter' => 'csrf']);
    $routes->post('settlements/(:num)/mark-paid', 'Admin\SettlementController::markPaid/$1', ['filter' => 'csrf']);
    $routes->post('settlements/(:num)/adjustments', 'Admin\SettlementController::addAdjustment/$1', ['filter' => 'csrf']);
    $routes->post('settlements/(:num)/adjustments/(:num)/reverse', 'Admin\SettlementController::reverseAdjustment/$1/$2', ['filter' => 'csrf']);
    $routes->post('settlements/(:num)/apply-taxes', 'Admin\SettlementController::applyTaxes/$1', ['filter' => 'csrf']);
    $routes->get('payouts', 'Admin\PayoutController::index');
    $routes->post('payouts/run', 'Admin\PayoutController::run', ['filter' => 'csrf']);
    $routes->post('payouts/bank-account', 'Admin\PayoutController::addBankAccount', ['filter' => 'csrf']);
    $routes->get('payouts/(:num)', 'Admin\PayoutController::show/$1');
    $routes->post('payouts/(:num)/mark-paid', 'Admin\PayoutController::markPaid/$1', ['filter' => 'csrf']);
    $routes->post('payouts/(:num)/mark-failed', 'Admin\PayoutController::markFailed/$1', ['filter' => 'csrf']);
    $routes->get('reconciliations', 'Admin\ReconciliationController::index');
    $routes->post('reconciliations/(:num)/resolve', 'Admin\ReconciliationController::resolve/$1', ['filter' => 'csrf']);
    $routes->post('reconciliations/(:num)/dispute', 'Admin\ReconciliationController::dispute/$1', ['filter' => 'csrf']);

    // Commission plans
    $routes->get('commission', 'Admin\CommissionController::index');
    $routes->post('commission/(:num)/activate', 'Admin\CommissionController::activate/$1', ['filter' => 'csrf']);
    $routes->post('commission/(:num)/deactivate', 'Admin\CommissionController::deactivate/$1', ['filter' => 'csrf']);

    // Customers
    $routes->get('customers', 'Admin\CustomerController::index');
    $routes->get('customers/(:num)', 'Admin\CustomerController::show/$1');
    $routes->post('customers/(:num)/block', 'Admin\CustomerController::block/$1', ['filter' => 'csrf']);
    $routes->post('customers/(:num)/unblock', 'Admin\CustomerController::unblock/$1', ['filter' => 'csrf']);

    // Masters — Categories
    $routes->get('categories', 'Admin\CategoryController::index');
    $routes->get('categories/new', 'Admin\CategoryController::new');
    $routes->post('categories/store', 'Admin\CategoryController::store', ['filter' => 'csrf']);
    $routes->get('categories/(:num)/edit', 'Admin\CategoryController::edit/$1');
    $routes->post('categories/(:num)/update', 'Admin\CategoryController::update/$1', ['filter' => 'csrf']);
    $routes->post('categories/(:num)/activate', 'Admin\CategoryController::activate/$1', ['filter' => 'csrf']);
    $routes->post('categories/(:num)/deactivate', 'Admin\CategoryController::deactivate/$1', ['filter' => 'csrf']);

    // Masters — Business Types
    $routes->get('business-types', 'Admin\BusinessTypeController::index');
    $routes->get('business-types/new', 'Admin\BusinessTypeController::new');
    $routes->post('business-types/store', 'Admin\BusinessTypeController::store', ['filter' => 'csrf']);
    $routes->get('business-types/(:num)/edit', 'Admin\BusinessTypeController::edit/$1');
    $routes->post('business-types/(:num)/update', 'Admin\BusinessTypeController::update/$1', ['filter' => 'csrf']);
    $routes->post('business-types/(:num)/activate', 'Admin\BusinessTypeController::activate/$1', ['filter' => 'csrf']);
    $routes->post('business-types/(:num)/deactivate', 'Admin\BusinessTypeController::deactivate/$1', ['filter' => 'csrf']);

    // Masters — Attributes
    $routes->get('attributes', 'Admin\AttributeController::index');
    $routes->get('attributes/new', 'Admin\AttributeController::new');
    $routes->post('attributes/store', 'Admin\AttributeController::store', ['filter' => 'csrf']);
    $routes->get('attributes/(:num)/edit', 'Admin\AttributeController::edit/$1');
    $routes->post('attributes/(:num)/update', 'Admin\AttributeController::update/$1', ['filter' => 'csrf']);
    $routes->post('attributes/(:num)/activate', 'Admin\AttributeController::activate/$1', ['filter' => 'csrf']);
    $routes->post('attributes/(:num)/deactivate', 'Admin\AttributeController::deactivate/$1', ['filter' => 'csrf']);

    // Brands
    $routes->get('brands', 'Admin\BrandController::index');
    $routes->get('brands/new', 'Admin\BrandController::new');
    $routes->post('brands/store', 'Admin\BrandController::store', ['filter' => 'csrf']);
    $routes->get('brands/(:num)/edit', 'Admin\BrandController::edit/$1');
    $routes->post('brands/(:num)/update', 'Admin\BrandController::update/$1', ['filter' => 'csrf']);
    $routes->post('brands/(:num)/approve', 'Admin\BrandController::approve/$1', ['filter' => 'csrf']);
    $routes->post('brands/(:num)/deactivate', 'Admin\BrandController::deactivate/$1', ['filter' => 'csrf']);
    $routes->post('brands/(:num)/categories', 'Admin\BrandController::saveCategories/$1', ['filter' => 'csrf']);

    // Banners (home carousel — max 6 active; image upload to public/uploads/banners/)
    $routes->get('banners', 'Admin\BannerController::index');
    $routes->post('banners/store', 'Admin\BannerController::store', ['filter' => 'csrf']);
    $routes->post('banners/(:num)/update', 'Admin\BannerController::update/$1', ['filter' => 'csrf']);
    $routes->post('banners/(:num)/delete', 'Admin\BannerController::delete/$1', ['filter' => 'csrf']);
    $routes->post('banners/(:num)/toggle', 'Admin\BannerController::toggle/$1', ['filter' => 'csrf']);
    $routes->get('banners/brands', 'Admin\BannerController::brands');
    $routes->get('banners/discount-tiers', 'Admin\BannerController::discountTiers');

    // Promotions
    $routes->get('promotions', 'Admin\PromotionController::index');
    $routes->get('promotions/new', 'Admin\PromotionController::new');
    $routes->post('promotions/store', 'Admin\PromotionController::store', ['filter' => 'csrf']);
    $routes->get('promotions/(:num)/edit', 'Admin\PromotionController::edit/$1');
    $routes->post('promotions/(:num)/update', 'Admin\PromotionController::update/$1', ['filter' => 'csrf']);
    $routes->post('promotions/(:num)/activate', 'Admin\PromotionController::activate/$1', ['filter' => 'csrf']);
    $routes->post('promotions/(:num)/pause', 'Admin\PromotionController::pause/$1', ['filter' => 'csrf']);

    // Reviews
    $routes->get('reviews', 'Admin\ReviewController::index');
    $routes->post('reviews/(:num)/publish', 'Admin\ReviewController::publish/$1', ['filter' => 'csrf']);
    $routes->post('reviews/(:num)/reject', 'Admin\ReviewController::reject/$1', ['filter' => 'csrf']);
    $routes->post('reviews/(:num)/restore', 'Admin\ReviewController::restore/$1', ['filter' => 'csrf']);

    // Warehouses
    $routes->get('warehouses', 'Admin\WarehouseController::index');
    $routes->get('warehouses/new', 'Admin\WarehouseController::new');
    $routes->post('warehouses/store', 'Admin\WarehouseController::store', ['filter' => 'csrf']);
    $routes->get('warehouses/(:num)/edit', 'Admin\WarehouseController::edit/$1');
    $routes->post('warehouses/(:num)/update', 'Admin\WarehouseController::update/$1', ['filter' => 'csrf']);
    $routes->post('warehouses/(:num)/activate', 'Admin\WarehouseController::activate/$1', ['filter' => 'csrf']);
    $routes->post('warehouses/(:num)/deactivate', 'Admin\WarehouseController::deactivate/$1', ['filter' => 'csrf']);

    // New masters (units, tax classes, HSN/SAC, delivery zones) — generic CRUD
    foreach ([
        'units' => 'UnitController', 'tax-classes' => 'TaxClassController',
        'hsn-codes' => 'HsnController', 'zones' => 'ZoneController',
    ] as $slug => $ctrl) {
        $routes->get($slug, "Admin\\{$ctrl}::index");
        $routes->get($slug . '/new', "Admin\\{$ctrl}::new");
        $routes->post($slug . '/store', "Admin\\{$ctrl}::store", ['filter' => 'csrf']);
        $routes->get($slug . '/(:num)/edit', "Admin\\{$ctrl}::edit/\$1");
        $routes->post($slug . '/(:num)/update', "Admin\\{$ctrl}::update/\$1", ['filter' => 'csrf']);
        $routes->post($slug . '/(:num)/toggle', "Admin\\{$ctrl}::toggle/\$1", ['filter' => 'csrf']);
    }

    // Stock transfers
    $routes->get('transfers', 'Admin\TransferController::index');
    $routes->post('transfers/(:num)/approve', 'Admin\TransferController::approve/$1', ['filter' => 'csrf']);
    $routes->post('transfers/(:num)/reject', 'Admin\TransferController::reject/$1', ['filter' => 'csrf']);
    $routes->post('transfers/(:num)/pack', 'Admin\TransferController::pack/$1', ['filter' => 'csrf']);
    $routes->post('transfers/(:num)/dispatch', 'Admin\TransferController::dispatch/$1', ['filter' => 'csrf']);
    $routes->post('transfers/(:num)/receive', 'Admin\TransferController::receive/$1', ['filter' => 'csrf']);
    $routes->post('transfers/(:num)/close', 'Admin\TransferController::close/$1', ['filter' => 'csrf']);
    $routes->post('transfers/(:num)/cancel', 'Admin\TransferController::cancel/$1', ['filter' => 'csrf']);

    // Products — admin create/edit (category-gated + images)
    $routes->get('products', 'Admin\ProductController::index');
    $routes->get('products/new', 'Admin\ProductController::new');
    $routes->post('products/store', 'Admin\ProductController::store', ['filter' => 'csrf']);
    $routes->get('products/(:num)/edit', 'Admin\ProductController::edit/$1');
    $routes->post('products/(:num)/update', 'Admin\ProductController::update/$1', ['filter' => 'csrf']);
    // Variant engine (P2)
    $routes->get('products/(:num)/variants', 'Admin\ProductVariantController::index/$1');
    $routes->post('products/(:num)/variants/generate', 'Admin\ProductVariantController::generate/$1', ['filter' => 'csrf']);
    $routes->post('variants/(:num)/update', 'Admin\ProductVariantController::update/$1', ['filter' => 'csrf']);
    $routes->post('variants/(:num)/delete', 'Admin\ProductVariantController::delete/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/variants/bulk', 'Admin\ProductVariantController::bulkUpdate/$1', ['filter' => 'csrf']);
    $routes->post('variants/(:num)/barcodes', 'Admin\ProductVariantController::saveBarcodes/$1', ['filter' => 'csrf']);
    // Media management (RB7)
    $routes->get('products/(:num)/media/library', 'Admin\ProductMediaController::library/$1');
    $routes->post('products/(:num)/media/attach', 'Admin\ProductMediaController::attachLibrary/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/media/upload-image', 'Admin\ProductMediaController::uploadImage/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/media/reorder', 'Admin\ProductMediaController::reorder/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/media/primary', 'Admin\ProductMediaController::setPrimary/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/media/remove', 'Admin\ProductMediaController::removeImage/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/media/doc-upload', 'Admin\ProductMediaController::uploadDoc/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/media/doc-delete/(:num)', 'Admin\ProductMediaController::deleteDoc/$1/$2', ['filter' => 'csrf']);
    // Inventory engine (P3)
    $routes->get('products/(:num)/inventory', 'Admin\ProductInventoryController::index/$1');
    $routes->post('products/(:num)/inventory/receive', 'Admin\ProductInventoryController::receive/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/inventory/adjust', 'Admin\ProductInventoryController::adjust/$1', ['filter' => 'csrf']);
    // Pricing engine (P4)
    $routes->get('products/(:num)/pricing', 'Admin\ProductPricingController::index/$1');
    $routes->post('products/(:num)/pricing/special', 'Admin\ProductPricingController::addSpecial/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/pricing/tier', 'Admin\ProductPricingController::addTier/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/pricing/group', 'Admin\ProductPricingController::setGroup/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/pricing/special-delete/(:num)', 'Admin\ProductPricingController::deleteSpecial/$1/$2', ['filter' => 'csrf']);
    $routes->post('products/(:num)/pricing/tier-delete/(:num)', 'Admin\ProductPricingController::deleteTier/$1/$2', ['filter' => 'csrf']);
    // Specialized product types (P6)
    $routes->get('products/(:num)/type', 'Admin\ProductTypeController::index/$1');
    $routes->post('products/(:num)/type/bundle-add', 'Admin\ProductTypeController::bundleAdd/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/bundle-del/(:num)', 'Admin\ProductTypeController::bundleDel/$1/$2', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/download-add', 'Admin\ProductTypeController::downloadAdd/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/download-del/(:num)', 'Admin\ProductTypeController::downloadDel/$1/$2', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/license-add', 'Admin\ProductTypeController::licenseAdd/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/plan-add', 'Admin\ProductTypeController::planAdd/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/plan-del/(:num)', 'Admin\ProductTypeController::planDel/$1/$2', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/gift-save', 'Admin\ProductTypeController::giftSave/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/rental-save', 'Admin\ProductTypeController::rentalSave/$1', ['filter' => 'csrf']);

    // Integrations — Payment Gateways (robust, multi-gateway)
    $routes->get('payment-gateways', 'Admin\PaymentGatewayController::index');
    $routes->get('payment-gateways/new', 'Admin\PaymentGatewayController::new');
    $routes->post('payment-gateways/store', 'Admin\PaymentGatewayController::store', ['filter' => 'csrf']);
    $routes->get('payment-gateways/(:num)/edit', 'Admin\PaymentGatewayController::edit/$1');
    $routes->post('payment-gateways/(:num)/update', 'Admin\PaymentGatewayController::update/$1', ['filter' => 'csrf']);
    $routes->post('payment-gateways/(:num)/enable', 'Admin\PaymentGatewayController::enable/$1', ['filter' => 'csrf']);
    $routes->post('payment-gateways/(:num)/disable', 'Admin\PaymentGatewayController::disable/$1', ['filter' => 'csrf']);
    $routes->post('payment-gateways/(:num)/test', 'Admin\PaymentGatewayController::test/$1', ['filter' => ['csrf', 'throttle:5,300']]);
    $routes->post('payment-gateways/(:num)/delete', 'Admin\PaymentGatewayController::delete/$1', ['filter' => 'csrf']);

    // Access control & config (Batch 6)
    $routes->get('roles', 'Admin\RoleController::index');
    $routes->get('roles/(:num)/edit', 'Admin\RoleController::edit/$1');
    $routes->post('roles/(:num)/update', 'Admin\RoleController::update/$1', ['filter' => 'csrf']);
    $routes->get('users', 'Admin\UserController::index');
    $routes->get('users/new', 'Admin\UserController::new');
    $routes->post('users/store', 'Admin\UserController::store', ['filter' => 'csrf']);
    $routes->get('users/(:num)/edit', 'Admin\UserController::edit/$1');
    $routes->post('users/(:num)/update', 'Admin\UserController::update/$1', ['filter' => 'csrf']);
    $routes->post('users/(:num)/suspend', 'Admin\UserController::suspend/$1', ['filter' => 'csrf']);
    $routes->post('users/(:num)/activate', 'Admin\UserController::activate/$1', ['filter' => 'csrf']);
    $routes->get('feature-flags', 'Admin\FeatureFlagController::index');
    $routes->post('feature-flags/(:num)/toggle', 'Admin\FeatureFlagController::toggle/$1', ['filter' => 'csrf']);

    // Sync engine — health dashboard + manual actions
    $routes->get('sync-health', 'Admin\SyncHealthController::index');
    $routes->post('sync-health/trigger', 'Admin\SyncHealthController::trigger', ['filter' => 'csrf']);
    $routes->post('sync-health/conflict/(:num)/resolve', 'Admin\SyncHealthController::resolveConflict/$1', ['filter' => 'csrf']);
    $routes->post('sync-health/deadletter/(:num)/requeue', 'Admin\SyncHealthController::requeue/$1', ['filter' => 'csrf']);

    // Media library browser (vendors -> shops -> files)
    $routes->get('media', 'Admin\MediaController::index');
    $routes->get('media/vendor/(:num)', 'Admin\MediaController::vendor/$1');
    $routes->get('media/shop/(:num)', 'Admin\MediaController::shop/$1');
    $routes->post('media/upload/(:segment)/(:num)', 'Admin\MediaController::upload/$1/$2', ['filter' => 'csrf']);
    $routes->post('media/(:num)/delete', 'Admin\MediaController::delete/$1', ['filter' => 'csrf']);
    $routes->get('media/(:num)/view', 'Admin\MediaController::view/$1');

    // Integrations — Firebase / Email / GST API config
    $routes->get('integrations/aws', 'Admin\AwsSettingsController::index');
    $routes->post('integrations/aws/save', 'Admin\AwsSettingsController::save', ['filter' => 'csrf']);
    $routes->post('integrations/aws/test', 'Admin\AwsSettingsController::test', ['filter' => ['csrf', 'throttle:5,300']]);
    $routes->get('integrations/(:segment)', 'Admin\IntegrationController::show/$1');
    $routes->post('integrations/(:segment)', 'Admin\IntegrationController::save/$1', ['filter' => 'csrf']);
    $routes->post('integrations/(:segment)/test', 'Admin\IntegrationController::test/$1', ['filter' => ['csrf', 'throttle:5,300']]);

    // Firebase Phone-OTP test page (validates the saved Firebase config end-to-end)
    $routes->get('firebase-otp-test', 'Auth\FirebaseOtpController::show');
    $routes->post('firebase-otp-verify', 'Auth\FirebaseOtpController::verify', ['filter' => 'csrf']);

    // Support tickets
    $routes->get('support', 'Admin\SupportController::index');
    $routes->get('support/(:num)', 'Admin\SupportController::show/$1');
    $routes->post('support/(:num)/reply', 'Admin\SupportController::reply/$1', ['filter' => 'csrf']);
    $routes->post('support/(:num)/resolve', 'Admin\SupportController::resolve/$1', ['filter' => 'csrf']);
    $routes->post('support/(:num)/close', 'Admin\SupportController::close/$1', ['filter' => 'csrf']);
    $routes->post('support/(:num)/reopen', 'Admin\SupportController::reopen/$1', ['filter' => 'csrf']);

    // Read-only oversight
    $routes->get('coupons', 'Admin\CouponController::index');
    $routes->get('coupons/new', 'Admin\CouponController::new');
    $routes->get('coupons/(:num)', 'Admin\CouponController::show/$1');
    $routes->post('coupons/store', 'Admin\CouponController::store', ['filter' => 'csrf']);
    $routes->get('coupons/(:num)/edit', 'Admin\CouponController::edit/$1');
    $routes->post('coupons/(:num)/update', 'Admin\CouponController::update/$1', ['filter' => 'csrf']);
    $routes->get('backups', 'Admin\BackupController::index');
    $routes->get('notifications', 'Admin\NotificationController::index');
    $routes->get('audit-logs', 'Admin\AuditLogController::index');
    $routes->get('deliveries', 'Admin\DeliveryController::index');
    $routes->get('deliveries/(:num)', 'Admin\DeliveryController::show/$1');
    $routes->post('deliveries/(:num)/status', 'Admin\DeliveryController::updateStatus/$1', ['filter' => 'csrf']);
    // Rider management (cross-vendor) + payout plans (Phase 1)
    $routes->get('riders', 'Admin\RiderController::index');
    $routes->get('riders/(:num)', 'Admin\RiderController::show/$1');
    $routes->post('riders/(:num)/status', 'Admin\RiderController::setStatus/$1', ['filter' => 'csrf']);
    $routes->post('riders/(:num)/plan', 'Admin\RiderController::assignPlan/$1', ['filter' => 'csrf']);
    $routes->post('riders/(:num)/documents/(:num)/verify', 'Admin\RiderController::verifyDoc/$1/$2', ['filter' => 'csrf']);
    $routes->post('riders/(:num)/documents/(:num)/reject', 'Admin\RiderController::rejectDoc/$1/$2', ['filter' => 'csrf']);
    $routes->post('riders/(:num)/disputes/(:num)/resolve', 'Admin\RiderController::resolveDispute/$1/$2', ['filter' => 'csrf']);

    // Rider finance — COD reconciliation + earnings payout batches + statements
    $routes->get('rider-finance/cod', 'Admin\RiderFinanceController::cod');
    $routes->post('rider-finance/cod/(:num)/deposit', 'Admin\RiderFinanceController::codDeposit/$1', ['filter' => 'csrf']);
    $routes->post('rider-finance/cod/(:num)/reconcile', 'Admin\RiderFinanceController::codReconcile/$1', ['filter' => 'csrf']);
    $routes->post('rider-finance/cod/rider/(:num)/deposit-all', 'Admin\RiderFinanceController::codDepositRider/$1', ['filter' => 'csrf']);
    $routes->get('rider-finance/payouts', 'Admin\RiderFinanceController::payouts');
    $routes->post('rider-finance/payouts/run', 'Admin\RiderFinanceController::runPayouts', ['filter' => 'csrf']);
    $routes->get('rider-finance/payouts/(:num)', 'Admin\RiderFinanceController::payoutShow/$1');
    $routes->post('rider-finance/payouts/(:num)/paid', 'Admin\RiderFinanceController::payoutMarkPaid/$1', ['filter' => 'csrf']);
    $routes->post('rider-finance/payouts/(:num)/failed', 'Admin\RiderFinanceController::payoutMarkFailed/$1', ['filter' => 'csrf']);
    $routes->post('rider-finance/payout-rider/(:num)/paid', 'Admin\RiderFinanceController::payoutRiderPaid/$1', ['filter' => 'csrf']);
    $routes->get('rider-finance/statement/(:num)', 'Admin\RiderFinanceController::statement/$1');
    $routes->get('rider-plans', 'Admin\RiderPlanController::index');
    $routes->get('rider-plans/(:num)/edit', 'Admin\RiderPlanController::edit/$1');
    $routes->post('rider-plans/store', 'Admin\RiderPlanController::store', ['filter' => 'csrf']);
    $routes->post('rider-plans/(:num)/update', 'Admin\RiderPlanController::update/$1', ['filter' => 'csrf']);
    $routes->post('rider-plans/(:num)/toggle', 'Admin\RiderPlanController::toggle/$1', ['filter' => 'csrf']);
    $routes->get('imports', 'Admin\ImportController::index');
    $routes->post('imports/upload', 'Admin\ImportController::upload', ['filter' => 'csrf']);
    $routes->get('imports/template/(:segment)', 'Admin\ImportController::template/$1');
    $routes->get('imports/(:num)', 'Admin\ImportController::show/$1');
    $routes->get('profile', 'Admin\ProfileController::index');
    $routes->post('profile', 'Admin\ProfileController::save', ['filter' => 'csrf']);
    $routes->post('profile/password', 'Admin\ProfileController::savePassword', ['filter' => 'csrf']);
    $routes->get('settings', 'Admin\SettingsController::index');
    $routes->post('settings/delivery', 'Admin\SettingsController::saveDelivery', ['filter' => 'csrf']);
    $routes->post('settings/system', 'Admin\SettingsController::saveSystem', ['filter' => 'csrf']);
    $routes->get('reports', 'Admin\ReportController::index');
    $routes->get('reports/gst', 'Admin\ReportController::gst');
    $routes->get('reports/export-sales', 'Admin\ReportController::exportSales');
    $routes->post('reports/export-async', 'Admin\ReportController::exportAsync', ['filter' => 'csrf']);
    $routes->get('exports', 'Admin\ReportController::exports');

    // Documents (X2c) — invoice / credit-note PDFs, lazily generated + cached
    $routes->get('invoices/(:num)/pdf', 'Admin\DocumentController::invoicePdf/$1');
    $routes->get('credit-notes/(:num)/pdf', 'Admin\DocumentController::creditNotePdf/$1');

    // Governance (X3) — unified platform approval queue (L2)
    $routes->get('approvals', 'Admin\ApprovalQueueController::index');
    $routes->post('approvals/(:num)/decide', 'Admin\ApprovalQueueController::decide/$1', ['filter' => 'csrf']);

    // X4 — money visibility: returns pipeline, hold queue, document registers
    $routes->get('returns', 'Admin\ReturnController::index');
    $routes->get('returns/(:num)', 'Admin\ReturnController::show/$1');
    $routes->post('returns/(:num)/transition', 'Admin\ReturnController::transition/$1', ['filter' => 'csrf']);
    $routes->get('commission-holds', 'Admin\CommissionHoldController::index');
    $routes->get('invoices', 'Admin\DocumentController::index');
    $routes->get('credit-notes', 'Admin\DocumentController::creditNotes');
});

// ---- Vendor panel (Phase 7) — session-guarded; tenant-isolated in controllers ----
// Pinned to vendor principals. An admin reaches this group through
// Admin\PortalController, which rewrites principal_type to 'vendor' on enter.
// 'subdomain' pins this group to vendor.shiplore.in AND shop.shiplore.in — shop
// managers are vendor-panel staff (see the root-route comments at the top of this
// file), so shop. must resolve the same panel. See PanelSubdomainIsolationTest.
$routes->group('vendor', ['filter' => 'webAuth:vendor', 'subdomain' => ['vendor', 'shop']], static function (RouteCollection $routes): void {
    $routes->get('me',           'Vendor\MeController::index');
    $routes->post('me',          'Vendor\MeController::save',         ['filter' => 'csrf']);
    $routes->post('me/password', 'Vendor\MeController::savePassword', ['filter' => 'csrf']);
    $routes->get('dashboard', 'Vendor\VendorDashboardController::dashboard');

    // KYC / vendor document uploads (presigned S3, or local dummy when unconfigured)
    $routes->get('kyc', 'Vendor\DocumentUploadController::index');
    $routes->post('kyc/presign', 'Vendor\DocumentUploadController::presign', ['filter' => 'csrf']);
    // Raw PUT receiver for the local (non-S3) upload fallback. Same-origin and
    // session-authenticated, so it needs CSRF like every other write. The token
    // travels in the X-CSRF-TOKEN header, handed to the uploader by presign().
    $routes->put('kyc/put', 'Vendor\DocumentUploadController::put', ['filter' => 'csrf']);
    $routes->get('kyc/file', 'Vendor\DocumentUploadController::file');
    $routes->get('kyc/(:num)/view', 'Vendor\DocumentUploadController::view/$1');
    $routes->post('kyc/confirm', 'Vendor\DocumentUploadController::confirm', ['filter' => 'csrf']);
    $routes->post('kyc/(:num)/delete', 'Vendor\DocumentUploadController::delete/$1', ['filter' => 'csrf']);

    // Business profile / branding (logo via MediaService -> S3)
    $routes->get('profile', 'Vendor\ProfileController::index');
    $routes->post('profile/logo', 'Vendor\ProfileController::uploadLogo', ['filter' => 'csrf']);

    // Media library (general vendor/shop files)
    $routes->get('media', 'Vendor\MediaController::index');
    $routes->post('media/presign', 'Vendor\MediaController::presign', ['filter' => 'csrf']);
    // See the note on kyc/put above — same raw-PUT fallback, same CSRF need.
    $routes->put('media/put', 'Vendor\MediaController::put', ['filter' => 'csrf']);
    $routes->get('media/file', 'Vendor\MediaController::file');
    $routes->get('media/(:num)/view', 'Vendor\MediaController::view/$1');
    $routes->post('media/confirm', 'Vendor\MediaController::confirm', ['filter' => 'csrf']);
    $routes->post('media/(:num)/delete', 'Vendor\MediaController::delete/$1', ['filter' => 'csrf']);

    $routes->get('shops', 'Vendor\ShopController::index');
    $routes->get('shops/new', 'Vendor\ShopController::new');
    $routes->post('shops', 'Vendor\ShopController::create', ['filter' => 'csrf']);
    $routes->get('shops/(:num)', 'Vendor\ShopController::show/$1');
    $routes->post('shops/(:num)/open', 'Vendor\ShopController::open/$1', ['filter' => 'csrf']);
    $routes->post('shops/(:num)/close', 'Vendor\ShopController::close/$1', ['filter' => 'csrf']);
    $routes->post('shops/(:num)/hours', 'Vendor\ShopController::hours/$1', ['filter' => 'csrf']);
    $routes->post('shops/(:num)/holidays', 'Vendor\ShopController::holidayAdd/$1', ['filter' => 'csrf']);
    $routes->post('shops/(:num)/holidays/(:num)/delete', 'Vendor\ShopController::holidayDelete/$1/$2', ['filter' => 'csrf']);

    $routes->get('products', 'Vendor\ProductController::index');
    $routes->get('products/new', 'Vendor\ProductController::new');
    $routes->post('products/store', 'Vendor\ProductController::store', ['filter' => 'csrf']);
    $routes->get('products/(:num)/edit', 'Vendor\ProductController::edit/$1');
    $routes->post('products/(:num)/update', 'Vendor\ProductController::update/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/submit', 'Vendor\ProductController::submit/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/publish', 'Vendor\ProductController::publish/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/unpublish', 'Vendor\ProductController::unpublish/$1', ['filter' => 'csrf']);
    $routes->post('products/ai-suggest', 'Vendor\ProductController::aiSuggest', ['filter' => 'csrf']);
    // Draft + autosave (RB3) — tenant-scoped
    $routes->post('products/draft', 'Vendor\ProductController::draft', ['filter' => 'csrf']);
    $routes->post('products/(:num)/autosave/(:segment)', 'Vendor\ProductController::autosaveSection/$1/$2', ['filter' => 'csrf']);
    $routes->post('products/(:num)/duplicate', 'Vendor\ProductController::duplicate/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/delete', 'Vendor\ProductController::delete/$1', ['filter' => 'csrf']);
    $routes->post('products/bulk', 'Vendor\ProductController::bulk', ['filter' => 'csrf']);
    $routes->get('products/trash', 'Vendor\ProductController::trash');
    $routes->post('products/(:num)/restore', 'Vendor\ProductController::restore/$1', ['filter' => 'csrf']);
    // Dynamic dependency lookups (RB2) — tenant-scoped (vendor id forced from session)
    $routes->get('lookup/shops', 'Vendor\ProductLookupController::shops');
    $routes->get('lookup/categories', 'Vendor\ProductLookupController::categories');
    $routes->get('lookup/categories/(:num)/attributes', 'Vendor\ProductLookupController::attributes/$1');
    $routes->get('lookup/categories/(:num)/defaults', 'Vendor\ProductLookupController::defaults/$1');
    $routes->get('lookup/categories/(:num)/brands', 'Vendor\ProductLookupController::brands/$1');
    $routes->get('lookup/attributes/(:num)/values', 'Vendor\ProductLookupController::attributeValues/$1');
    // Variant engine (P2) — tenant-scoped
    $routes->get('products/(:num)/variants', 'Vendor\ProductVariantController::index/$1');
    $routes->post('products/(:num)/variants/generate', 'Vendor\ProductVariantController::generate/$1', ['filter' => 'csrf']);
    $routes->post('variants/(:num)/update', 'Vendor\ProductVariantController::update/$1', ['filter' => 'csrf']);
    $routes->post('variants/(:num)/delete', 'Vendor\ProductVariantController::delete/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/variants/bulk', 'Vendor\ProductVariantController::bulkUpdate/$1', ['filter' => 'csrf']);
    $routes->post('variants/(:num)/barcodes', 'Vendor\ProductVariantController::saveBarcodes/$1', ['filter' => 'csrf']);
    // Media management (RB7) — tenant-scoped
    $routes->get('products/(:num)/media/library', 'Vendor\ProductMediaController::library/$1');
    $routes->post('products/(:num)/media/attach', 'Vendor\ProductMediaController::attachLibrary/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/media/upload-image', 'Vendor\ProductMediaController::uploadImage/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/media/reorder', 'Vendor\ProductMediaController::reorder/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/media/primary', 'Vendor\ProductMediaController::setPrimary/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/media/remove', 'Vendor\ProductMediaController::removeImage/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/media/doc-upload', 'Vendor\ProductMediaController::uploadDoc/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/media/doc-delete/(:num)', 'Vendor\ProductMediaController::deleteDoc/$1/$2', ['filter' => 'csrf']);
    // Inventory engine (P3) — tenant-scoped
    $routes->get('products/(:num)/inventory', 'Vendor\ProductInventoryController::index/$1');
    $routes->post('products/(:num)/inventory/receive', 'Vendor\ProductInventoryController::receive/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/inventory/adjust', 'Vendor\ProductInventoryController::adjust/$1', ['filter' => 'csrf']);
    // Pricing engine (P4) — tenant-scoped
    $routes->get('products/(:num)/pricing', 'Vendor\ProductPricingController::index/$1');
    $routes->post('products/(:num)/pricing/special', 'Vendor\ProductPricingController::addSpecial/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/pricing/tier', 'Vendor\ProductPricingController::addTier/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/pricing/group', 'Vendor\ProductPricingController::setGroup/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/pricing/special-delete/(:num)', 'Vendor\ProductPricingController::deleteSpecial/$1/$2', ['filter' => 'csrf']);
    $routes->post('products/(:num)/pricing/tier-delete/(:num)', 'Vendor\ProductPricingController::deleteTier/$1/$2', ['filter' => 'csrf']);
    // Specialized product types (P6) — tenant-scoped
    $routes->get('products/(:num)/type', 'Vendor\ProductTypeController::index/$1');
    $routes->post('products/(:num)/type/bundle-add', 'Vendor\ProductTypeController::bundleAdd/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/bundle-del/(:num)', 'Vendor\ProductTypeController::bundleDel/$1/$2', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/download-add', 'Vendor\ProductTypeController::downloadAdd/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/download-del/(:num)', 'Vendor\ProductTypeController::downloadDel/$1/$2', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/license-add', 'Vendor\ProductTypeController::licenseAdd/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/plan-add', 'Vendor\ProductTypeController::planAdd/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/plan-del/(:num)', 'Vendor\ProductTypeController::planDel/$1/$2', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/gift-save', 'Vendor\ProductTypeController::giftSave/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/type/rental-save', 'Vendor\ProductTypeController::rentalSave/$1', ['filter' => 'csrf']);
    // Barcode label printing (S3) — no approval, gated by barcode.print
    $routes->get('products/(:num)/barcodes', 'Vendor\BarcodeController::index/$1');
    $routes->post('products/(:num)/barcodes/print', 'Vendor\BarcodeController::print/$1', ['filter' => 'csrf']);

    // Combo offers (S3) — POS-only → vendor approval; online → vendor → admin
    $routes->get('combos', 'Vendor\ComboController::index');
    $routes->post('combos', 'Vendor\ComboController::create', ['filter' => 'csrf']);

    $routes->get('inventory', 'Vendor\InventoryController::index');
    $routes->post('inventory/(:num)/update', 'Vendor\InventoryController::update/$1', ['filter' => 'csrf']);

    $routes->get('orders', 'Vendor\OrderController::index');
    $routes->get('orders/(:num)', 'Vendor\OrderController::show/$1');
    $routes->post('orders/(:num)/status', 'Vendor\OrderController::updateStatus/$1', ['filter' => 'csrf']);
    $routes->post('orders/(:num)/heartbeat', 'Vendor\OrderController::heartbeat/$1', ['filter' => 'csrf']);
    $routes->post('orders/(:num)/verify-otp', 'Vendor\OrderController::verifyOtp/$1', ['filter' => 'csrf']);
    $routes->post('orders/(:num)/regenerate-otp', 'Vendor\OrderController::regenerateOtp/$1', ['filter' => ['csrf', 'throttle:5,300']]);
    $routes->post('orders/(:num)/assign-delivery', 'Vendor\OrderController::assignDelivery/$1', ['filter' => 'csrf']);
    $routes->get('refunds', 'Vendor\RefundController::index');

    $routes->get('settlements', 'Vendor\SettlementController::index');
    $routes->get('settlements/(:num)', 'Vendor\SettlementController::show/$1');
    $routes->get('commission', 'Vendor\SettlementController::commission');
    $routes->get('gst', 'Vendor\GstController::index');

    $routes->get('transfers', 'Vendor\TransferController::index');
    $routes->get('transfers/find', 'Vendor\TransferController::find');
    $routes->post('transfers', 'Vendor\TransferController::create', ['filter' => 'csrf']);
    $routes->post('transfers/(:num)/approve', 'Vendor\TransferController::approve/$1', ['filter' => 'csrf']);
    $routes->post('transfers/(:num)/reject', 'Vendor\TransferController::reject/$1', ['filter' => 'csrf']);
    $routes->post('transfers/(:num)/pack', 'Vendor\TransferController::pack/$1', ['filter' => 'csrf']);
    $routes->post('transfers/(:num)/dispatch', 'Vendor\TransferController::dispatch/$1', ['filter' => 'csrf']);
    $routes->post('transfers/(:num)/receive', 'Vendor\TransferController::receive/$1', ['filter' => 'csrf']);
    $routes->post('transfers/(:num)/close', 'Vendor\TransferController::close/$1', ['filter' => 'csrf']);
    $routes->post('transfers/(:num)/cancel', 'Vendor\TransferController::cancel/$1', ['filter' => 'csrf']);

    // Web POS (T4)
    $routes->get('pos', 'Vendor\PosController::index');
    $routes->get('pos/search', 'Vendor\PosController::search');
    $routes->get('pos/scan/(:segment)', 'Vendor\PosController::scan/$1');
    $routes->post('pos/sale', 'Vendor\PosController::sale', ['filter' => 'csrf']);
    $routes->get('pos/receipt/(:num)', 'Vendor\PosController::receipt/$1');
    $routes->get('pos/receipt/(:num)/pdf', 'Vendor\PosController::receiptPdf/$1');
    $routes->get('pos/credits', 'Vendor\PosController::credits');
    $routes->post('pos/credits/(:num)/repay', 'Vendor\PosController::repay/$1', ['filter' => 'csrf']);

    // Purchase intake (Add Inventory) + supplier master
    $routes->get('purchase/add', 'Vendor\PurchaseController::add');
    $routes->get('purchase/history', 'Vendor\PurchaseController::history');
    $routes->get('purchase/search-products', 'Vendor\PurchaseController::searchProducts');
    $routes->post('purchase/store', 'Vendor\PurchaseController::store', ['filter' => 'csrf']);
    $routes->get('suppliers/search', 'Vendor\SupplierController::search');
    $routes->post('suppliers/store', 'Vendor\SupplierController::store', ['filter' => 'csrf']);
    $routes->get('pos/returns', 'Vendor\PosController::returns');
    $routes->post('pos/returns', 'Vendor\PosController::processReturn', ['filter' => 'csrf']);
    // POS Credit Note — walk-in refund (own bill no.) + 80mm print
    $routes->get('pos/credit-note', 'Vendor\PosController::creditNote');
    $routes->post('pos/credit-note', 'Vendor\PosController::createCreditNote', ['filter' => 'csrf']);
    $routes->get('pos/credit-note/(:num)', 'Vendor\PosController::creditNoteReceipt/$1');
    $routes->get('pos/credit-note/(:num)/pdf', 'Vendor\PosController::creditNotePdf/$1');
    $routes->get('pos/reports', 'Vendor\PosController::reports');
    $routes->get('pos/reports/export', 'Vendor\PosController::exportReport');
    $routes->get('pos/settings', 'Vendor\PosController::settings');
    $routes->post('pos/settings', 'Vendor\PosController::saveSettings', ['filter' => 'csrf']);
    $routes->get('warehouses', 'Vendor\WarehouseController::index');

    // Delivery operations (Phase 12)
    $routes->get('deliveries', 'Vendor\DeliveryController::index');
    $routes->get('deliveries/(:num)', 'Vendor\DeliveryController::show/$1');
    $routes->post('deliveries/(:num)/assign', 'Vendor\DeliveryController::assign/$1', ['filter' => 'csrf']);
    $routes->post('deliveries/(:num)/auto-assign', 'Vendor\DeliveryController::autoAssign/$1', ['filter' => 'csrf']);
    $routes->post('deliveries/(:num)/reassign', 'Vendor\DeliveryController::reassign/$1', ['filter' => 'csrf']);
    $routes->post('deliveries/(:num)/fail', 'Vendor\DeliveryController::fail/$1', ['filter' => 'csrf']);

    $routes->get('staff', 'Vendor\StaffController::index');
    $routes->get('staff/new', 'Vendor\StaffController::new');
    $routes->post('staff', 'Vendor\StaffController::create', ['filter' => 'csrf']);
    $routes->get('staff/(:num)/edit', 'Vendor\StaffController::edit/$1');
    $routes->post('staff/(:num)/update', 'Vendor\StaffController::update/$1', ['filter' => 'csrf']);
    $routes->post('staff/(:num)/suspend', 'Vendor\StaffController::suspend/$1', ['filter' => 'csrf']);
    $routes->post('staff/riders', 'Vendor\StaffController::addRider', ['filter' => 'csrf']);
    $routes->get('shops/(:num)/edit', 'Vendor\ShopController::edit/$1');
    $routes->post('shops/(:num)/save', 'Vendor\ShopController::save/$1', ['filter' => 'csrf']);
    $routes->get('notifications', 'Vendor\NotificationController::index');

    // Documents (X2c) — tenant-scoped invoice / credit-note PDFs
    $routes->get('invoices/(:num)/pdf', 'Vendor\DocumentController::invoicePdf/$1');
    $routes->get('credit-notes/(:num)/pdf', 'Vendor\DocumentController::creditNotePdf/$1');

    // Governance (X3) — approval inbox (L1) + the requester's own submissions
    $routes->get('approvals', 'Vendor\ApprovalController::index');
    $routes->post('approvals/(:num)/decide', 'Vendor\ApprovalController::decide/$1', ['filter' => 'csrf']);
    $routes->get('requests', 'Vendor\ApprovalController::mine');

    // X4 — document registers + commission hold lens
    $routes->get('invoices', 'Vendor\DocumentController::index');
    $routes->get('credit-notes', 'Vendor\DocumentController::creditNotes');
    $routes->get('commission-holds', 'Vendor\DocumentController::commissionHolds');
});

// ---- Manufacturer panel — session-guarded, tenant-isolated in controllers ----
// Reached from manufacturer.shiplore.in (owner) and mshop.shiplore.in (unit staff);
// both land here, exactly as vendor./shop. both land in the vendor panel.
//
// `webAuth:manufacturer` pins the principal, but that pin is LOG-ONLY until
// auth.enforcePrincipalType is turned on — so the real gate is the party_type
// constraint in ManufacturerAccountRepository, applied via requireManufacturer().
// 'subdomain' pins this group to manufacturer.shiplore.in AND mshop.shiplore.in,
// mirroring the vendor/shop pair above. See PanelSubdomainIsolationTest.
$routes->group('manufacturer', ['filter' => 'webAuth:manufacturer', 'subdomain' => ['manufacturer', 'mshop']], static function (RouteCollection $routes): void {
    $routes->get('dashboard', 'Manufacturer\ManufacturerDashboardController::index');

    // The acting user's own login (owner or unit staff). Not permission-gated: this is
    // the person, not the tenant — gating it would lock a store keeper out of changing
    // their own password.
    $routes->get('me', 'Manufacturer\MeController::index');
    $routes->post('me', 'Manufacturer\MeController::save', ['filter' => 'csrf']);
    $routes->post('me/password', 'Manufacturer\MeController::savePassword', ['filter' => 'csrf']);

    // The tenant's own identity/branding. Owner-only, enforced in the controller.
    $routes->get('profile', 'Manufacturer\ProfileController::index');
    $routes->post('profile/logo', 'Manufacturer\ProfileController::uploadLogo', ['filter' => 'csrf']);

    // Per-user feed. The po.* events raised by PurchaseOrderRepository land here.
    $routes->get('notifications', 'Manufacturer\NotificationController::index');

    // Units (factories). No delivery-range routes — mshops has no such columns.
    $routes->get('units', 'Manufacturer\UnitController::index');
    $routes->get('units/new', 'Manufacturer\UnitController::new');
    $routes->post('units/store', 'Manufacturer\UnitController::store', ['filter' => 'csrf']);
    $routes->get('units/(:num)/edit', 'Manufacturer\UnitController::edit/$1');
    $routes->post('units/(:num)/update', 'Manufacturer\UnitController::update/$1', ['filter' => 'csrf']);
    $routes->post('units/(:num)/toggle', 'Manufacturer\UnitController::toggle/$1', ['filter' => 'csrf']);

    // KYC documents. Same bucket, key scheme and vendor_documents table the vendor
    // panel uses — a manufacturer IS a `vendors` row, so only the tenant id differs.
    $routes->get('documents', 'Manufacturer\DocumentUploadController::index');
    $routes->post('documents/presign', 'Manufacturer\DocumentUploadController::presign', ['filter' => 'csrf']);
    $routes->put('documents/put', 'Manufacturer\DocumentUploadController::put');
    $routes->get('documents/file', 'Manufacturer\DocumentUploadController::file');
    $routes->get('documents/(:num)/view', 'Manufacturer\DocumentUploadController::view/$1');
    $routes->post('documents/confirm', 'Manufacturer\DocumentUploadController::confirm', ['filter' => 'csrf']);
    $routes->post('documents/(:num)/delete', 'Manufacturer\DocumentUploadController::delete/$1', ['filter' => 'csrf']);

    // Media library — flat and tenant-owned; see the controller for why there are no
    // per-unit folders.
    $routes->get('media', 'Manufacturer\MediaController::index');
    $routes->post('media/presign', 'Manufacturer\MediaController::presign', ['filter' => 'csrf']);
    $routes->put('media/put', 'Manufacturer\MediaController::put');
    $routes->get('media/file', 'Manufacturer\MediaController::file');
    $routes->get('media/(:num)/view', 'Manufacturer\MediaController::view/$1');
    $routes->post('media/confirm', 'Manufacturer\MediaController::confirm', ['filter' => 'csrf']);
    $routes->post('media/(:num)/delete', 'Manufacturer\MediaController::delete/$1', ['filter' => 'csrf']);

    // Staff and their unit assignments. This is the only writer of
    // mfg_staff_assignments, so it is what makes unit-scoped staff exist at all.
    $routes->get('staff', 'Manufacturer\StaffController::index');
    $routes->get('staff/new', 'Manufacturer\StaffController::new');
    $routes->post('staff', 'Manufacturer\StaffController::create', ['filter' => 'csrf']);
    $routes->get('staff/(:num)/edit', 'Manufacturer\StaffController::edit/$1');
    $routes->post('staff/(:num)/update', 'Manufacturer\StaffController::update/$1', ['filter' => 'csrf']);
    $routes->post('staff/(:num)/suspend', 'Manufacturer\StaffController::suspend/$1', ['filter' => 'csrf']);

    // Products — making price + selling price.
    $routes->get('products', 'Manufacturer\ProductController::index');
    $routes->get('products/new', 'Manufacturer\ProductController::new');
    $routes->post('products/store', 'Manufacturer\ProductController::store', ['filter' => 'csrf']);
    $routes->get('products/(:num)/edit', 'Manufacturer\ProductController::edit/$1');
    $routes->post('products/(:num)/update', 'Manufacturer\ProductController::update/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/autosave/(:segment)', 'Manufacturer\ProductController::autosave/$1/$2', ['filter' => 'csrf']);
    $routes->post('products/(:num)/submit', 'Manufacturer\ProductController::submit/$1', ['filter' => 'csrf']);

    // Variants — the same builder the vendor panel uses. Price/SKU/stock live here,
    // not on the product form, for every panel.
    $routes->get('products/(:num)/variants', 'Manufacturer\ProductVariantController::index/$1');
    $routes->post('products/(:num)/variants/generate', 'Manufacturer\ProductVariantController::generate/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/variants/bulk', 'Manufacturer\ProductVariantController::bulkUpdate/$1', ['filter' => 'csrf']);
    $routes->post('variants/(:num)/update', 'Manufacturer\ProductVariantController::update/$1', ['filter' => 'csrf']);
    $routes->post('variants/(:num)/delete', 'Manufacturer\ProductVariantController::delete/$1', ['filter' => 'csrf']);
    $routes->post('variants/(:num)/barcodes', 'Manufacturer\ProductVariantController::saveBarcodes/$1', ['filter' => 'csrf']);

    // Governance. Staff changes proposed by a manager land here for the owner's
    // decision; the engine and its tables are reused unchanged (tenant-agnostic).
    $routes->get('approvals', 'Manufacturer\ApprovalController::index');
    $routes->post('approvals/(:num)/decide', 'Manufacturer\ApprovalController::decide/$1', ['filter' => 'csrf']);
    $routes->get('requests', 'Manufacturer\ApprovalController::mine');

    // Getting dispatched purchase orders to their buyers, plus the manufacturer's own
    // riders. Deliveries are opened by the PO flow, so there is no create action here.
    $routes->get('deliveries', 'Manufacturer\DeliveryController::index');
    $routes->post('deliveries/(:num)/assign', 'Manufacturer\DeliveryController::assign/$1', ['filter' => 'csrf']);
    $routes->post('deliveries/(:num)/(:alpha)', 'Manufacturer\DeliveryController::transition/$1/$2', ['filter' => 'csrf']);
    $routes->get('riders', 'Manufacturer\DeliveryController::riders');
    $routes->post('riders', 'Manufacturer\DeliveryController::addRider', ['filter' => 'csrf']);

    // Stock at manufacturing units. The write action is "record production", not
    // "receive" — a manufacturer makes its stock rather than buying it in.
    $routes->get('inventory', 'Manufacturer\InventoryController::index');
    $routes->get('products/(:num)/stock', 'Manufacturer\InventoryController::product/$1');
    $routes->post('products/(:num)/stock/produce', 'Manufacturer\InventoryController::produce/$1', ['filter' => 'csrf']);
    $routes->post('products/(:num)/stock/adjust', 'Manufacturer\InventoryController::adjust/$1', ['filter' => 'csrf']);

    // Cascading lookups the product form and variant builder call over AJAX,
    // constrained to this manufacturer's own categories/attributes.
    $routes->get('lookup/categories', 'Manufacturer\ProductLookupController::categories');
    $routes->get('lookup/categories/(:num)/attributes', 'Manufacturer\ProductLookupController::attributes/$1');
    $routes->get('lookup/categories/(:num)/defaults', 'Manufacturer\ProductLookupController::defaults/$1');
    $routes->get('lookup/categories/(:num)/brands', 'Manufacturer\ProductLookupController::brands/$1');
    $routes->get('lookup/attributes/(:num)/values', 'Manufacturer\ProductLookupController::attributeValues/$1');

    // Incoming monline purchase orders (seller side). The buyer confirms receipt on
    // their side — a manufacturer cannot mark its own delivery received.
    $routes->get('purchase-orders', 'Manufacturer\PurchaseOrderController::index');
    $routes->get('purchase-orders/(:num)', 'Manufacturer\PurchaseOrderController::show/$1');
    $routes->post('purchase-orders/(:num)/(:alpha)', 'Manufacturer\PurchaseOrderController::transition/$1/$2', ['filter' => 'csrf']);
});

// ---- monline.shiplore.in — the B2B marketplace ----
// 'subdomain' pins this group to monline.shiplore.in. This is the exact leak an
// operator reported directly: https://shiplore.in/monline/browse served the same
// page as https://monline.shiplore.in/browse, because this group used to be
// path-based with no host restriction and so resolved on the apex (and on every
// other panel's subdomain) too. Browsing is public but PRICE-FREE — pricing and
// every ordering route require a resolved vendor buyer, enforced in the
// controllers. See PanelSubdomainIsolationTest.
$routes->group('monline', ['subdomain' => 'monline'], static function (RouteCollection $routes): void {
    $routes->get('/', 'Monline\CatalogController::home');
    $routes->get('browse', 'Monline\CatalogController::browse');
    $routes->get('product/(:segment)', 'Monline\CatalogController::product/$1');

    // Proximity-sort location override — buyers only, sorts, never filters.
    $routes->post('location', 'Monline\CatalogController::setLocation', ['filter' => 'csrf']);
    $routes->post('location/clear', 'Monline\CatalogController::clearLocation', ['filter' => 'csrf']);

    // Cart + checkout — buyers only.
    $routes->get('cart', 'Monline\OrderController::cart');
    $routes->post('cart/add', 'Monline\OrderController::add', ['filter' => 'csrf']);
    $routes->post('cart/update', 'Monline\OrderController::update', ['filter' => 'csrf']);
    $routes->post('cart/remove/(:num)', 'Monline\OrderController::remove/$1', ['filter' => 'csrf']);
    $routes->post('place', 'Monline\OrderController::place', ['filter' => 'csrf']);

    // Purchase orders (buyer side).
    $routes->get('orders', 'Monline\OrderController::orders');
    $routes->get('orders/(:num)', 'Monline\OrderController::show/$1');
    $routes->post('orders/(:num)/receive', 'Monline\OrderController::receive/$1', ['filter' => 'csrf']);
    $routes->post('orders/(:num)/cancel', 'Monline\OrderController::cancel/$1', ['filter' => 'csrf']);
});

// UI Kit — standalone design reference (own shell + menu; not wired to the app).
$routes->get('ui-kit', 'UiKitController::index');
$routes->get('ui-kit/(:segment)', 'UiKitController::show/$1');

// ---------------------------------------------------------------------
// API v1 (Phase 6). Scope/permission enforced by filters per route.
// ---------------------------------------------------------------------
$routes->group('api/v1', static function (RouteCollection $routes): void {
    // CORS preflight: resolve every OPTIONS under api/v1 so the `cors` filter answers it.
    $routes->options('(:any)', 'Api\V1\AuthApiController::preflight');

    // Current actor's resolved capabilities (auth required; self-scope).
    $routes->get('me/capabilities', 'MeController::capabilities', ['filter' => 'jwtAuth']);

    // ---- Phase 9: Mobile apps (shared backend, JWT) ----
    // Auth (public): OTP login (SMS/Firebase) + password login -> JWT.
    // No CSRF — stateless token API; rate-limited against brute force/abuse.
    $routes->post('auth/otp/request', 'Api\V1\AuthApiController::otpRequest', ['filter' => 'throttle:5,60']);
    $routes->post('auth/otp/verify', 'Api\V1\AuthApiController::otpVerify', ['filter' => 'throttle:10,60']);
    // Customer app — Firebase Phone-Auth ID token → customer JWT (auto signs-up by phone).
    $routes->post('auth/firebase', 'Api\V1\AuthApiController::firebaseVerify', ['filter' => 'throttle:5,60']);
    $routes->post('auth/login', 'Api\V1\AuthApiController::login', ['filter' => 'throttle:10,60']);
    // Sliding session — exchange a still-valid token for a fresh one (no re-login).
    $routes->post('auth/refresh', 'Api\V1\AuthApiController::refresh', ['filter' => 'jwtAuth']);
    // POS wizard — vendor Firebase Phone-Auth ID token → vendor JWT (rate-limited, no CSRF).
    $routes->post('auth/vendor/firebase-verify', 'Api\V1\VendorPosController::firebaseVerify', ['filter' => 'throttle:5,60']);
    // POS wizard — backend-native phone OTP (no Firebase/reCAPTCHA dependency).
    $routes->post('auth/vendor/otp/send',   'Api\V1\VendorPosController::otpSend',   ['filter' => 'throttle:5,60']);
    $routes->post('auth/vendor/otp/verify', 'Api\V1\VendorPosController::otpVerify', ['filter' => 'throttle:10,60']);

    // Customer app — browse public; orders require a customer JWT
    $routes->get('customer/home', 'Api\V1\CustomerApiController::home');
    $routes->get('customer/categories', 'Api\V1\CustomerApiController::categories');
    $routes->get('customer/brands', 'Api\V1\CustomerApiController::brands');
    $routes->get('customer/shop-categories', 'Api\V1\CustomerApiController::shopCategories');
    $routes->get('customer/shops', 'Api\V1\CustomerApiController::shops');
    $routes->get('customer/products', 'Api\V1\CustomerApiController::products');
    $routes->get('customer/product/(:segment)', 'Api\V1\CustomerApiController::product/$1');
    $routes->get('customer/track/(:segment)', 'Api\V1\CustomerApiController::track/$1', ['filter' => 'jwtAuth']);
    $routes->post('customer/cart/validate', 'Api\V1\CustomerApiController::validateCart', ['filter' => 'jwtAuth']);
    $routes->post('customer/coupons/validate', 'Api\V1\CustomerApiController::validateCoupon', ['filter' => 'jwtAuth']);
    $routes->post('customer/orders', 'Api\V1\CustomerApiController::placeOrder', ['filter' => 'jwtAuth']);
    $routes->get('customer/orders', 'Api\V1\CustomerApiController::orders', ['filter' => 'jwtAuth']);
    $routes->post('customer/orders/(:segment)/cancel', 'Api\V1\CustomerApiController::cancelOrder/$1', ['filter' => 'jwtAuth']);
    $routes->post('customer/orders/(:segment)/cancel-item/(:num)', 'Api\V1\CustomerApiController::cancelItem/$1/$2', ['filter' => 'jwtAuth']);
    $routes->post('customer/orders/(:segment)/return', 'Api\V1\CustomerApiController::returnOrder/$1', ['filter' => 'jwtAuth']);
    $routes->post('customer/deliveries/(:num)/rate', 'Api\V1\CustomerApiController::rateDelivery/$1', ['filter' => 'jwtAuth']);
    $routes->post('customer/products/(:num)/review', 'Api\V1\CustomerApiController::submitReview/$1', ['filter' => 'jwtAuth']);
    // Online payment (PayU): init the gateway txn, sign SDK hashes, verify the callback.
    $routes->post('customer/orders/(:segment)/pay/verify', 'Api\V1\CustomerApiController::payVerify/$1', ['filter' => 'jwtAuth']);
    $routes->post('customer/orders/(:segment)/pay', 'Api\V1\CustomerApiController::payInit/$1', ['filter' => 'jwtAuth']);
    $routes->post('customer/payu/hash', 'Api\V1\CustomerApiController::payuHash', ['filter' => 'jwtAuth']);
    // Coupons (public list) + account-synced wishlist.
    $routes->get('customer/coupons', 'Api\V1\CustomerApiController::coupons');
    $routes->get('customer/wishlist', 'Api\V1\CustomerApiController::wishlist', ['filter' => 'jwtAuth']);
    $routes->post('customer/wishlist', 'Api\V1\CustomerApiController::addWishlist', ['filter' => 'jwtAuth']);
    $routes->delete('customer/wishlist/(:num)', 'Api\V1\CustomerApiController::removeWishlist/$1', ['filter' => 'jwtAuth']);
    $routes->get('customer/profile',  'Api\V1\CustomerApiController::getProfile',    ['filter' => 'jwtAuth']);
    $routes->put('customer/profile',  'Api\V1\CustomerApiController::updateProfile', ['filter' => 'jwtAuth']);
    $routes->get('customer/addresses', 'Api\V1\CustomerApiController::addresses', ['filter' => 'jwtAuth']);
    $routes->post('customer/addresses', 'Api\V1\CustomerApiController::addAddress', ['filter' => 'jwtAuth']);
    $routes->put('customer/addresses/(:num)', 'Api\V1\CustomerApiController::updateAddress/$1', ['filter' => 'jwtAuth']);
    $routes->delete('customer/addresses/(:num)', 'Api\V1\CustomerApiController::deleteAddress/$1', ['filter' => 'jwtAuth']);
    $routes->post('customer/device-token', 'Api\V1\CustomerApiController::registerDeviceToken', ['filter' => 'jwtAuth']);
    $routes->post('customer/test-notification', 'Api\V1\CustomerApiController::sendTestNotification', ['filter' => ['jwtAuth', 'throttle:3,300']]);
    $routes->get('customer/notifications', 'Api\V1\CustomerApiController::notifications', ['filter' => 'jwtAuth']);
    $routes->get('customer/notifications/unread-count', 'Api\V1\CustomerApiController::notificationsUnreadCount', ['filter' => 'jwtAuth']);
    $routes->post('customer/notifications/(:num)/read', 'Api\V1\CustomerApiController::markNotificationRead/$1', ['filter' => 'jwtAuth']);
    $routes->post('customer/notifications/read-all', 'Api\V1\CustomerApiController::markAllNotificationsRead', ['filter' => 'jwtAuth']);
    $routes->delete('customer/notifications/all', 'Api\V1\CustomerApiController::clearAllNotifications', ['filter' => 'jwtAuth']);
    $routes->delete('customer/notifications/(:num)', 'Api\V1\CustomerApiController::deleteNotification/$1', ['filter' => 'jwtAuth']);
    $routes->post('customer/support', 'Api\V1\CustomerApiController::createSupportTicket', ['filter' => 'jwtAuth']);
    $routes->get('customer/sub-orders/(:num)/invoice/pdf', 'Api\V1\CustomerApiController::invoicePdf/$1', ['filter' => 'jwtAuth']);
    $routes->get('customer/wallet', 'Api\V1\CustomerApiController::wallet', ['filter' => 'jwtAuth']);
    $routes->get('customer/payment-instruments', 'Api\V1\CustomerApiController::paymentInstruments', ['filter' => 'jwtAuth']);
    $routes->post('customer/payment-instruments', 'Api\V1\CustomerApiController::addPaymentInstrument', ['filter' => 'jwtAuth']);
    $routes->delete('customer/payment-instruments/(:num)', 'Api\V1\CustomerApiController::deletePaymentInstrument/$1', ['filter' => 'jwtAuth']);
    $routes->post('customer/payment-instruments/(:num)/default', 'Api\V1\CustomerApiController::setDefaultInstrument/$1', ['filter' => 'jwtAuth']);

    // Vendor app — JWT; tenant-isolated to the owned vendor
    $routes->post('auth/vendor/refresh', 'Api\V1\VendorApiController::refresh', ['filter' => 'jwtAuth']);
    $routes->get('vendor/pos/shops', 'Api\V1\VendorPosController::shops', ['filter' => 'jwtAuth']);
    $routes->get('vendor/pos/catalog/(:num)', 'Api\V1\VendorPosController::catalogBootstrap/$1', ['filter' => 'jwtAuth']);
    $routes->get('vendor/pos/masters/(:num)', 'Api\V1\VendorPosController::masters/$1',          ['filter' => 'jwtAuth']);
    $routes->post('vendor/pos/sale',          'Api\V1\VendorPosController::createSale',          ['filter' => 'jwtAuth']);
    $routes->get('vendor/dashboard',       'Api\V1\VendorApiController::dashboard',      ['filter' => 'jwtAuth']);
    $routes->get('vendor/dashboard/chart', 'Api\V1\VendorApiController::dashboardChart', ['filter' => 'jwtAuth']);
    $routes->get('vendor/analytics',       'Api\V1\VendorApiController::analytics',      ['filter' => 'jwtAuth']);
    $routes->get('vendor/shops', 'Api\V1\VendorApiController::shops', ['filter' => 'jwtAuth']);
    $routes->post('vendor/shops/(:num)/open',  'Api\V1\VendorApiController::openShop/$1',  ['filter' => 'jwtAuth']);
    $routes->post('vendor/shops/(:num)/close', 'Api\V1\VendorApiController::closeShop/$1', ['filter' => 'jwtAuth']);
    $routes->get('vendor/products',              'Api\V1\VendorApiController::products',      ['filter' => 'jwtAuth']);
    $routes->post('vendor/products',             'Api\V1\VendorApiController::createProduct', ['filter' => 'jwtAuth']);
    $routes->get('vendor/products/(:num)/variants',  'Api\V1\VendorApiController::variants/$1',        ['filter' => 'jwtAuth']);
    $routes->post('vendor/products/(:num)/variants', 'Api\V1\VendorApiController::addVariant/$1',      ['filter' => 'jwtAuth']);
    $routes->put('vendor/products/(:num)',             'Api\V1\VendorApiController::updateProduct/$1',     ['filter' => 'jwtAuth']);
    $routes->delete('vendor/products/(:num)',          'Api\V1\VendorApiController::deleteProduct/$1',     ['filter' => 'jwtAuth']);
    $routes->post('vendor/products/bulk',              'Api\V1\VendorApiController::bulkProducts',         ['filter' => 'jwtAuth']);
    $routes->post('vendor/products/(:num)/publish',   'Api\V1\VendorApiController::publishProduct/$1',   ['filter' => 'jwtAuth']);
    $routes->post('vendor/products/(:num)/unpublish', 'Api\V1\VendorApiController::unpublishProduct/$1', ['filter' => 'jwtAuth']);
    $routes->put('vendor/products/(:num)/variants/(:num)/price', 'Api\V1\VendorApiController::updateVariantPrice/$1/$2', ['filter' => 'jwtAuth']);
    $routes->delete('vendor/products/(:num)/variants/(:num)', 'Api\V1\VendorApiController::deleteVariant/$1/$2', ['filter' => 'jwtAuth']);
    $routes->post('vendor/products/(:num)/media',     'Api\V1\VendorApiController::uploadProductImage/$1', ['filter' => 'jwtAuth']);
    $routes->get('vendor/lookup/categories', 'Api\V1\VendorApiController::lookupCategories', ['filter' => 'jwtAuth']);
    $routes->get('vendor/inventory', 'Api\V1\VendorApiController::inventory', ['filter' => 'jwtAuth']);
    $routes->post('vendor/inventory/receive',       'Api\V1\VendorApiController::inventoryReceive',  ['filter' => 'jwtAuth']);
    $routes->post('vendor/inventory/adjust',        'Api\V1\VendorApiController::inventoryAdjust',   ['filter' => 'jwtAuth']);
    $routes->post('vendor/inventory/reorder-level', 'Api\V1\VendorApiController::setReorderLevel',   ['filter' => 'jwtAuth']);
    $routes->get('vendor/inventory/ledger',         'Api\V1\VendorApiController::inventoryMovements', ['filter' => 'jwtAuth']);
    $routes->get('vendor/orders/export', 'Api\V1\VendorApiController::exportOrders', ['filter' => 'jwtAuth']);
    $routes->get('vendor/orders', 'Api\V1\VendorApiController::orders', ['filter' => 'jwtAuth']);
    $routes->get('vendor/orders/(:num)', 'Api\V1\VendorApiController::order/$1', ['filter' => 'jwtAuth']);
    $routes->post('vendor/orders/(:num)/status',          'Api\V1\VendorApiController::orderStatus/$1',         ['filter' => 'jwtAuth']);
    $routes->post('vendor/orders/(:num)/verify-otp',      'Api\V1\VendorApiController::verifyDeliveryOtp/$1',   ['filter' => 'jwtAuth']);
    $routes->post('vendor/orders/(:num)/assign-delivery', 'Api\V1\VendorApiController::assignOrderDelivery/$1', ['filter' => 'jwtAuth']);
    $routes->post('vendor/orders/(:num)/heartbeat',       'Api\V1\VendorApiController::orderHeartbeat/$1',      ['filter' => 'jwtAuth']);
    $routes->post('vendor/orders/(:num)/regenerate-otp',  'Api\V1\VendorApiController::regenerateOtp/$1',       ['filter' => ['jwtAuth', 'throttle:5,300']]);
    $routes->get('vendor/riders',     'Api\V1\VendorApiController::riders',     ['filter' => 'jwtAuth']);
    $routes->get('vendor/deliveries',          'Api\V1\VendorApiController::deliveries',      ['filter' => 'jwtAuth']);
    $routes->get('vendor/deliveries/reports', 'Api\V1\VendorApiController::deliveryReports', ['filter' => 'jwtAuth']);
    $routes->get('vendor/deliveries/(:num)',  'Api\V1\VendorApiController::delivery/$1',     ['filter' => 'jwtAuth']);
    $routes->post('vendor/deliveries/(:num)/assign',      'Api\V1\VendorApiController::assignRider/$1',     ['filter' => 'jwtAuth']);
    $routes->post('vendor/deliveries/(:num)/auto-assign', 'Api\V1\VendorApiController::autoAssignRider/$1', ['filter' => 'jwtAuth']);
    $routes->post('vendor/deliveries/(:num)/fail',        'Api\V1\VendorApiController::failDelivery/$1',    ['filter' => 'jwtAuth']);
    $routes->get('vendor/deliveries/(:num)/history',     'Api\V1\VendorApiController::deliveryHistory/$1', ['filter' => 'jwtAuth']);
    $routes->get('vendor/refunds',                   'Api\V1\VendorApiController::refunds',       ['filter' => 'jwtAuth']);
    $routes->post('vendor/refunds/(:num)/approve',   'Api\V1\VendorApiController::approveRefund/$1', ['filter' => 'jwtAuth']);
    $routes->post('vendor/refunds/(:num)/reject',    'Api\V1\VendorApiController::rejectRefund/$1',  ['filter' => 'jwtAuth']);
    $routes->post('vendor/purchase',           'Api\V1\VendorApiController::createPurchase', ['filter' => 'jwtAuth']);
    $routes->get('vendor/gst',                'Api\V1\VendorApiController::gstSummary',    ['filter' => 'jwtAuth']);
    $routes->get('vendor/settlements',        'Api\V1\VendorApiController::settlements',   ['filter' => 'jwtAuth']);
    $routes->get('vendor/settlements/(:num)', 'Api\V1\VendorApiController::settlement/$1', ['filter' => 'jwtAuth']);
    $routes->get('vendor/shops/(:num)/hours', 'Api\V1\VendorApiController::shopHours/$1',  ['filter' => 'jwtAuth']);
    $routes->get('vendor/transfers',          'Api\V1\VendorApiController::transfers',     ['filter' => 'jwtAuth']);
    $routes->post('vendor/transfers',              'Api\V1\VendorApiController::createTransfer',          ['filter' => 'jwtAuth']);
    $routes->get('vendor/notifications/pending-rings',     'Api\V1\VendorApiController::pendingRings',              ['filter' => 'jwtAuth']);
    $routes->delete('vendor/notifications/all',            'Api\V1\VendorApiController::deleteAllNotifications',    ['filter' => 'jwtAuth']);
    $routes->get('vendor/notifications',                   'Api\V1\VendorApiController::notifications',              ['filter' => 'jwtAuth']);
    $routes->post('vendor/notifications/(:num)/read',      'Api\V1\VendorApiController::markNotificationRead/$1',  ['filter' => 'jwtAuth']);
    $routes->delete('vendor/notifications/(:num)',          'Api\V1\VendorApiController::deleteNotification/$1',    ['filter' => 'jwtAuth']);
    $routes->post('vendor/notifications/read-all', 'Api\V1\VendorApiController::markAllNotificationsRead', ['filter' => 'jwtAuth']);
    $routes->get('vendor/staff',                   'Api\V1\VendorApiController::staffList',               ['filter' => 'jwtAuth']);
    $routes->post('vendor/staff',                  'Api\V1\VendorApiController::createStaff',             ['filter' => 'jwtAuth']);
    $routes->put('vendor/staff/(:num)',             'Api\V1\VendorApiController::updateStaff/$1',          ['filter' => 'jwtAuth']);
    $routes->delete('vendor/staff/(:num)',          'Api\V1\VendorApiController::deleteStaff/$1',          ['filter' => 'jwtAuth']);
    $routes->post('vendor/device-token',   'Api\V1\VendorApiController::registerDeviceToken',   ['filter' => 'jwtAuth']);
    $routes->delete('vendor/device-token', 'Api\V1\VendorApiController::deregisterDeviceToken', ['filter' => 'jwtAuth']);

    // Commission ledger
    $routes->get('vendor/commission', 'Api\V1\VendorApiController::commissionLedger', ['filter' => 'jwtAuth']);

    // Transfer lifecycle (7 actions)
    $routes->post('vendor/transfers/(:num)/(:alpha)', 'Api\V1\VendorApiController::transferAction/$1/$2', ['filter' => 'jwtAuth']);

    // Shop hours save
    $routes->post('vendor/shops/(:num)/hours', 'Api\V1\VendorApiController::saveShopHours/$1', ['filter' => 'jwtAuth']);

    // Business profile
    $routes->get('vendor/profile', 'Api\V1\VendorApiController::businessProfile',       ['filter' => 'jwtAuth']);
    $routes->put('vendor/profile', 'Api\V1\VendorApiController::updateBusinessProfile',  ['filter' => 'jwtAuth']);

    // Product pricing rules
    $routes->get('vendor/products/(:num)/pricing',                      'Api\V1\VendorApiController::productPricing/$1',        ['filter' => 'jwtAuth']);
    $routes->post('vendor/products/(:num)/pricing/special',             'Api\V1\VendorApiController::addSpecialPrice/$1',       ['filter' => 'jwtAuth']);
    $routes->post('vendor/products/(:num)/pricing/tier',                'Api\V1\VendorApiController::addTierPrice/$1',          ['filter' => 'jwtAuth']);
    $routes->delete('vendor/products/(:num)/pricing/(:alpha)/(:num)',   'Api\V1\VendorApiController::deleteProductPricing/$1/$2/$3', ['filter' => 'jwtAuth']);

    // Approvals / change requests
    $routes->get('vendor/approvals',                      'Api\V1\VendorApiController::approvals',              ['filter' => 'jwtAuth']);
    $routes->post('vendor/approvals/(:num)/approve',      'Api\V1\VendorApiController::approveChangeRequest/$1', ['filter' => 'jwtAuth']);
    $routes->post('vendor/approvals/(:num)/reject',       'Api\V1\VendorApiController::rejectChangeRequest/$1',  ['filter' => 'jwtAuth']);

    // KYC documents
    $routes->get('vendor/documents',                      'Api\V1\VendorApiController::documents',       ['filter' => 'jwtAuth']);
    $routes->post('vendor/documents/presign',             'Api\V1\VendorApiController::documentPresign', ['filter' => 'jwtAuth']);
    $routes->post('vendor/documents/confirm/(:any)',      'Api\V1\VendorApiController::documentConfirm/$1', ['filter' => 'jwtAuth']);

    // Barcode print
    $routes->post('vendor/barcodes/print', 'Api\V1\VendorApiController::printBarcodes', ['filter' => 'jwtAuth']);

    // Delivery Boy app — JWT; rider-scoped to own assignments
    $routes->get('rider/me', 'Api\V1\RiderApiController::me', ['filter' => 'jwtAuth']);
    $routes->get('rider/poll', 'Api\V1\RiderApiController::poll', ['filter' => 'jwtAuth']);
    $routes->post('rider/availability', 'Api\V1\RiderApiController::availability', ['filter' => 'jwtAuth']);
    $routes->post('rider/shift/start', 'Api\V1\RiderApiController::shiftStart', ['filter' => 'jwtAuth']);
    $routes->post('rider/shift/end', 'Api\V1\RiderApiController::shiftEnd', ['filter' => 'jwtAuth']);
    $routes->post('rider/location', 'Api\V1\RiderApiController::location', ['filter' => 'jwtAuth']);
    $routes->get('rider/orders', 'Api\V1\RiderApiController::orders', ['filter' => 'jwtAuth']);
    $routes->get('rider/orders/(:num)', 'Api\V1\RiderApiController::order/$1', ['filter' => 'jwtAuth']);
    $routes->post('rider/orders/(:num)/accept', 'Api\V1\RiderApiController::accept/$1', ['filter' => 'jwtAuth']);
    $routes->post('rider/orders/(:num)/decline', 'Api\V1\RiderApiController::decline/$1', ['filter' => 'jwtAuth']);
    $routes->post('rider/orders/(:num)/status', 'Api\V1\RiderApiController::status/$1', ['filter' => 'jwtAuth']);
    $routes->post('rider/orders/(:num)/pod', 'Api\V1\RiderApiController::pod/$1', ['filter' => 'jwtAuth']);
    $routes->post('rider/orders/(:num)/cod', 'Api\V1\RiderApiController::cod/$1', ['filter' => 'jwtAuth']);
    $routes->get('rider/earnings', 'Api\V1\RiderApiController::earnings', ['filter' => 'jwtAuth']);
    $routes->get('rider/performance', 'Api\V1\RiderApiController::performance', ['filter' => 'jwtAuth']);
    $routes->get('rider/cash', 'Api\V1\RiderApiController::cash', ['filter' => 'jwtAuth']);
    $routes->get('rider/notifications', 'Api\V1\RiderApiController::notifications', ['filter' => 'jwtAuth']);

    // ---- Phase 10: Windows POS sync (offline-first; JWT cashier; shop-scoped) ----
    $routes->post('pos/activate', 'Api\V1\PosController::activate', ['filter' => 'jwtAuth']);
    $routes->get('pos/bootstrap', 'Api\V1\PosController::bootstrap', ['filter' => 'jwtAuth']);
    $routes->get('pos/sync/pull', 'Api\V1\PosController::pull', ['filter' => 'jwtAuth']);
    $routes->post('pos/sync/push', 'Api\V1\PosController::push', ['filter' => 'jwtAuth']);
    $routes->post('pos/shift/open', 'Api\V1\PosController::shiftOpen', ['filter' => 'jwtAuth']);
    $routes->post('pos/shift/close', 'Api\V1\PosController::shiftClose', ['filter' => 'jwtAuth']);
    $routes->get('pos/shift/current', 'Api\V1\PosController::shiftCurrent', ['filter' => 'jwtAuth']);
    $routes->get('pos/customers', 'Api\V1\PosController::customers', ['filter' => 'jwtAuth']);
    $routes->get('pos/scan/(:segment)', 'Api\V1\PosController::scan/$1', ['filter' => 'jwtAuth']);

    // ---- Phase 11: Generic sync engine (POS + apps; any entity) ----
    $routes->get('sync/entities', 'Api\V1\SyncController::entities', ['filter' => 'jwtAuth']);
    $routes->get('sync/pull', 'Api\V1\SyncController::pull', ['filter' => 'jwtAuth']);
    $routes->post('sync/push', 'Api\V1\SyncController::push', ['filter' => 'jwtAuth']);
});
