<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    public static function tokenService($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('tokenService');
        }

        return new \App\Libraries\TokenService();
    }

    public static function policyEngine($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('policyEngine');
        }

        return new \App\Libraries\PolicyEngine();
    }

    public static function capabilityResolver($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('capabilityResolver');
        }

        return new \App\Libraries\CapabilityResolver();
    }

    public static function requestAuthenticator($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('requestAuthenticator');
        }

        return new \App\Libraries\RequestAuthenticator(
            static::tokenService(),
            static::capabilityResolver(),
        );
    }

    public static function capabilityRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('capabilityRepository');
        }

        return new \App\Models\CapabilityRepository();
    }

    public static function scopeContext($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('scopeContext');
        }

        return new \App\Libraries\ScopeContext();
    }

    public static function webAuthenticator($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('webAuthenticator');
        }

        return new \App\Libraries\WebAuthenticator();
    }

    public static function userRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('userRepository');
        }

        return new \App\Models\UserRepository();
    }

    public static function loginAttemptRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('loginAttemptRepository');
        }

        return new \App\Models\LoginAttemptRepository();
    }

    public static function vendorRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('vendorRepository');
        }

        return new \App\Models\VendorRepository();
    }

    public static function dashboardRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('dashboardRepository');
        }

        return new \App\Models\DashboardRepository();
    }

    public static function shopRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('shopRepository');
        }

        return new \App\Models\ShopRepository();
    }

    public static function orderRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('orderRepository');
        }

        return new \App\Models\OrderRepository();
    }

    public static function paymentRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('paymentRepository');
        }

        return new \App\Models\PaymentRepository();
    }

    public static function refundRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('refundRepository');
        }

        return new \App\Models\RefundRepository();
    }

    public static function settlementRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('settlementRepository');
        }

        return new \App\Models\SettlementRepository();
    }

    public static function settlementAdjustmentRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('settlementAdjustmentRepository') : new \App\Models\SettlementAdjustmentRepository();
    }

    public static function payoutRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('payoutRepository') : new \App\Models\PayoutRepository();
    }

    public static function reconciliationRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('reconciliationRepository') : new \App\Models\ReconciliationRepository();
    }

    public static function vendorBankAccountRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('vendorBankAccountRepository') : new \App\Models\VendorBankAccountRepository();
    }

    public static function commissionRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('commissionRepository');
        }

        return new \App\Models\CommissionRepository();
    }

    public static function customerRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('customerRepository');
        }

        return new \App\Models\CustomerRepository();
    }

    public static function categoryRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('categoryRepository');
        }

        return new \App\Models\CategoryRepository();
    }

    public static function businessTypeRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('businessTypeRepository');
        }

        return new \App\Models\BusinessTypeRepository();
    }

    public static function attributeRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('attributeRepository');
        }

        return new \App\Models\AttributeRepository();
    }

    public static function brandRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('brandRepository') : new \App\Models\BrandRepository();
    }

    public static function promotionRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('promotionRepository') : new \App\Models\PromotionRepository();
    }

    public static function reviewRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('reviewRepository') : new \App\Models\ReviewRepository();
    }

    public static function warehouseRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('warehouseRepository') : new \App\Models\WarehouseRepository();
    }

    public static function transferRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('transferRepository') : new \App\Models\TransferRepository();
    }

    public static function couponRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('couponRepository') : new \App\Models\CouponRepository();
    }

    public static function notificationRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('notificationRepository') : new \App\Models\NotificationRepository();
    }

    public static function auditLogRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('auditLogRepository') : new \App\Models\AuditLogRepository();
    }

    public static function deliveryRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('deliveryRepository') : new \App\Models\DeliveryRepository();
    }

    public static function importJobRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('importJobRepository') : new \App\Models\ImportJobRepository();
    }

    public static function settingsRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('settingsRepository') : new \App\Models\SettingsRepository();
    }

    public static function reportRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('reportRepository') : new \App\Models\ReportRepository();
    }

    public static function supportTicketRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('supportTicketRepository') : new \App\Models\SupportTicketRepository();
    }

    public static function backupRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('backupRepository') : new \App\Models\BackupRepository();
    }

    // ---- Mobile API (Phase 9) ----
    public static function apiAuthRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('apiAuthRepository') : new \App\Models\ApiAuthRepository();
    }

    public static function riderRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('riderRepository') : new \App\Models\RiderRepository();
    }

    public static function riderPayoutPlanRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('riderPayoutPlanRepository') : new \App\Models\RiderPayoutPlanRepository();
    }

    public static function riderDocumentRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('riderDocumentRepository') : new \App\Models\RiderDocumentRepository();
    }

    public static function deliveryService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('deliveryService') : new \App\Libraries\Delivery\DeliveryService();
    }

    public static function deliveryTrackingRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('deliveryTrackingRepository') : new \App\Models\DeliveryTrackingRepository();
    }

    public static function codCollectionRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('codCollectionRepository') : new \App\Models\CodCollectionRepository();
    }

    public static function riderSettlementRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('riderSettlementRepository') : new \App\Models\RiderSettlementRepository();
    }

    public static function riderRouteService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('riderRouteService') : new \App\Libraries\Delivery\RiderRouteService();
    }

    public static function posSyncRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('posSyncRepository') : new \App\Models\PosSyncRepository();
    }

    // ---- Access control (Batch 6) ----
    public static function roleRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('roleRepository') : new \App\Models\RoleRepository();
    }

    public static function adminUserRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('adminUserRepository') : new \App\Models\AdminUserRepository();
    }

    // ---- Media (Batch 1) ----
    public static function mediaService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('mediaService') : new \App\Libraries\Media\MediaService();
    }

    public static function mediaRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('mediaRepository') : new \App\Models\MediaRepository();
    }

    public static function adminProductRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('adminProductRepository') : new \App\Models\AdminProductRepository();
    }

    public static function productVariantRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('productVariantRepository') : new \App\Models\ProductVariantRepository();
    }

    public static function inventoryService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('inventoryService') : new \App\Libraries\Inventory\InventoryService();
    }

    public static function transferService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('transferService') : new \App\Libraries\Inventory\TransferService();
    }

    public static function posSaleRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('posSaleRepository') : new \App\Models\PosSaleRepository();
    }

    public static function creditRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('creditRepository') : new \App\Models\CreditRepository();
    }

    public static function posReturnRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('posReturnRepository') : new \App\Models\PosReturnRepository();
    }

    public static function posDeliveryRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('posDeliveryRepository') : new \App\Models\PosDeliveryRepository();
    }

    public static function posReportRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('posReportRepository') : new \App\Models\PosReportRepository();
    }

    public static function pricingRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('pricingRepository') : new \App\Models\PricingRepository();
    }

    public static function productTypeRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('productTypeRepository') : new \App\Models\ProductTypeRepository();
    }

    public static function aiProductAssistant($getShared = true)
    {
        return $getShared ? static::getSharedInstance('aiProductAssistant') : new \App\Libraries\Ai\AiProductAssistant();
    }

    public static function productBarcodeRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('productBarcodeRepository') : new \App\Models\ProductBarcodeRepository();
    }

    public static function catalogLookupRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('catalogLookupRepository') : new \App\Models\CatalogLookupRepository();
    }

    public static function productShopRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('productShopRepository') : new \App\Models\ProductShopRepository();
    }

    public static function importService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('importService') : new \App\Libraries\Import\ImportService();
    }

    public static function importTemplateService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('importTemplateService') : new \App\Libraries\Import\ImportTemplateService();
    }

    // ---- Refund processing (Phase 14) ----
    public static function refundService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('refundService') : new \App\Libraries\Payment\RefundService();
    }

    public static function settlementService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('settlementService') : new \App\Libraries\Settlement\SettlementService();
    }

    // ---- Notifications (Phase 15) ----
    public static function notificationService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('notificationService') : new \App\Libraries\Notify\NotificationService();
    }

    public static function deviceTokenRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('deviceTokenRepository') : new \App\Models\DeviceTokenRepository();
    }

    public static function bannerRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('bannerRepository') : new \App\Models\BannerRepository();
    }

    /** SMTP mailer built from the saved Email/SMTP integration (admin Integrations panel). */
    public static function mailer($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('mailer');
        }

        return new \App\Libraries\Notify\Mailer(static::integrationRepository()->config('smtp'));
    }

    /** Verifies Firebase Phone-Auth ID tokens against the saved Firebase project. */
    public static function firebaseVerifier($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('firebaseVerifier');
        }

        return new \App\Libraries\Firebase\FirebaseIdTokenVerifier(
            (string) (static::integrationRepository()->config('firebase')['project_id'] ?? '')
        );
    }

    public static function awsSettingsRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('awsSettingsRepository') : new \App\Models\AwsSettingsRepository();
    }

    public static function mediaLibraryRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('mediaLibraryRepository') : new \App\Models\MediaLibraryRepository();
    }

    /** Presigned-URL document storage; S3 connection from the aws_settings table (else a local dummy). */
    public static function documentStorage($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('documentStorage');
        }

        return new \App\Libraries\Storage\DocumentStorage(static::awsSettingsRepository()->s3Config());
    }

    // ---- Delivery operations (Phase 12) ----
    public static function vendorDeliveryRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('vendorDeliveryRepository') : new \App\Models\VendorDeliveryRepository();
    }

    public static function deliveryAssignmentService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('deliveryAssignmentService') : new \App\Libraries\Delivery\DeliveryAssignmentService();
    }

    public static function riderDispatchService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('riderDispatchService') : new \App\Libraries\Delivery\RiderDispatchService();
    }

    // ---- Sync engine (Phase 11) ----
    public static function syncEngineRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('syncEngineRepository') : new \App\Models\SyncEngineRepository();
    }

    public static function jobQueueRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('jobQueueRepository') : new \App\Models\JobQueueRepository();
    }

    public static function webhookDispatcher($getShared = true)
    {
        return $getShared ? static::getSharedInstance('webhookDispatcher') : new \App\Libraries\Sync\WebhookDispatcher();
    }

    // ---- Storefront (Phase 8) ----
    public static function locationService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('locationService') : new \App\Libraries\Store\LocationService();
    }

    public static function cartService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('cartService') : new \App\Libraries\Store\CartService();
    }

    public static function storeCatalogRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('storeCatalogRepository') : new \App\Models\StoreCatalogRepository();
    }

    public static function facetCache($getShared = true)
    {
        return $getShared ? static::getSharedInstance('facetCache') : new \App\Libraries\Store\FacetCache();
    }

    public static function storeShopRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('storeShopRepository') : new \App\Models\StoreShopRepository();
    }

    public static function storeOrderRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('storeOrderRepository') : new \App\Models\StoreOrderRepository();
    }

    public static function storeCouponRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('storeCouponRepository') : new \App\Models\StoreCouponRepository();
    }

    public static function storeWishlistRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('storeWishlistRepository') : new \App\Models\StoreWishlistRepository();
    }

    public static function storeCustomerRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('storeCustomerRepository') : new \App\Models\StoreCustomerRepository();
    }

    // ---- Runtime engines (GST / commission / registration) ----
    public static function gstCalculator($getShared = true)
    {
        return $getShared ? static::getSharedInstance('gstCalculator') : new \App\Libraries\Tax\GstCalculator();
    }

    public static function commissionResolver($getShared = true)
    {
        return $getShared ? static::getSharedInstance('commissionResolver') : new \App\Libraries\Commission\CommissionResolver();
    }

    public static function commissionRateRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('commissionRateRepository') : new \App\Models\CommissionRateRepository();
    }

    public static function commissionService($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('commissionService');
        }

        return new \App\Libraries\Commission\CommissionService(static::commissionRateRepository(), static::commissionResolver());
    }

    public static function otpService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('otpService') : new \App\Libraries\Otp\OtpService();
    }

    public static function smsSender($getShared = true)
    {
        return $getShared ? static::getSharedInstance('smsSender') : new \App\Libraries\Notify\SmsSender();
    }

    /** Live Razorpay client built from the active 'razorpay' gateway config. */
    public static function razorpayClient($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('razorpayClient');
        }
        $config = [];
        foreach (static::paymentGatewayRepository()->active('online') as $gw) {
            if (($gw['provider'] ?? '') === 'razorpay') {
                $config = json_decode((string) ($gw['config'] ?? '{}'), true) ?: [];
                break;
            }
        }

        return new \App\Libraries\Payment\RazorpayClient($config);
    }

    /** Live PayU client built from the active 'payu' gateway config (mobile checkout). */
    public static function payuClient($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('payuClient');
        }
        $config = [];
        foreach (static::paymentGatewayRepository()->active('online') as $gw) {
            if (($gw['provider'] ?? '') === 'payu') {
                $config = json_decode((string) ($gw['config'] ?? '{}'), true) ?: [];
                break;
            }
        }

        return new \App\Libraries\Payment\PayuClient($config);
    }

    public static function gstVerificationService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('gstVerificationService') : new \App\Libraries\Gst\GstVerificationService();
    }

    public static function registrationRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('registrationRepository') : new \App\Models\RegistrationRepository();
    }

    // ---- Integrations (hardening) ----
    public static function paymentGatewayManager($getShared = true)
    {
        return $getShared ? static::getSharedInstance('paymentGatewayManager') : new \App\Libraries\Payment\PaymentGatewayManager();
    }

    public static function paymentGatewayRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('paymentGatewayRepository') : new \App\Models\PaymentGatewayRepository();
    }

    public static function integrationRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('integrationRepository') : new \App\Models\IntegrationRepository();
    }

    // ---- Order claim + escalation (Phase 16) ----
    public static function orderClaimService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('orderClaimService') : new \App\Libraries\Orders\OrderClaimService();
    }

    public static function escalationService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('escalationService') : new \App\Libraries\Orders\EscalationService();
    }

    // ---- Manufacturer panel ----
    // Separate from the vendor repositories on purpose: every lookup is additionally
    // constrained to vendors.party_type='manufacturer', which is what stops the two
    // panels resolving each other's tenants out of the shared `vendors` table.
    public static function manufacturerAccountRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('manufacturerAccountRepository') : new \App\Models\ManufacturerAccountRepository();
    }

    public static function manufacturerUnitRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('manufacturerUnitRepository') : new \App\Models\ManufacturerUnitRepository();
    }

    public static function manufacturerProductRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('manufacturerProductRepository') : new \App\Models\ManufacturerProductRepository();
    }

    public static function manufacturerStaffRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('manufacturerStaffRepository') : new \App\Models\ManufacturerStaffRepository();
    }

    public static function manufacturerInventoryService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('manufacturerInventoryService') : new \App\Libraries\Inventory\ManufacturerInventoryService();
    }

    public static function manufacturerDashboardRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('manufacturerDashboardRepository') : new \App\Models\ManufacturerDashboardRepository();
    }

    public static function manufacturerRegistrationRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('manufacturerRegistrationRepository') : new \App\Models\ManufacturerRegistrationRepository();
    }

    // ---- monline B2B marketplace ----
    public static function purchaseOrderRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('purchaseOrderRepository') : new \App\Models\PurchaseOrderRepository();
    }

    public static function monlineCatalogRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('monlineCatalogRepository') : new \App\Models\MonlineCatalogRepository();
    }

    public static function monlineCart($getShared = true)
    {
        return $getShared ? static::getSharedInstance('monlineCart') : new \App\Libraries\Monline\MonlineCart();
    }

    public static function monlineLocationService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('monlineLocationService') : new \App\Libraries\Monline\BuyerLocationService();
    }

    // ---- Vendor panel (Phase 7) ----
    public static function vendorAccountRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('vendorAccountRepository') : new \App\Models\VendorAccountRepository();
    }

    public static function vendorDashboardRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('vendorDashboardRepository') : new \App\Models\VendorDashboardRepository();
    }

    public static function vendorShopRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('vendorShopRepository') : new \App\Models\VendorShopRepository();
    }

    public static function vendorProductRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('vendorProductRepository') : new \App\Models\VendorProductRepository();
    }

    public static function vendorOrderRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('vendorOrderRepository') : new \App\Models\VendorOrderRepository();
    }

    public static function vendorSettlementRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('vendorSettlementRepository') : new \App\Models\VendorSettlementRepository();
    }

    public static function vendorStaffRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('vendorStaffRepository') : new \App\Models\VendorStaffRepository();
    }

    public static function vendorRefundRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('vendorRefundRepository') : new \App\Models\VendorRefundRepository();
    }

    public static function vendorNotificationRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('vendorNotificationRepository') : new \App\Models\VendorNotificationRepository();
    }

    public static function vendorGstRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('vendorGstRepository') : new \App\Models\VendorGstRepository();
    }

    public static function vendorInventoryRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('vendorInventoryRepository') : new \App\Models\VendorInventoryRepository();
    }

    public static function supplierRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('supplierRepository') : new \App\Models\SupplierRepository();
    }

    public static function purchaseInvoiceRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('purchaseInvoiceRepository') : new \App\Models\PurchaseInvoiceRepository();
    }

    public static function purchaseInvoiceService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('purchaseInvoiceService') : new \App\Libraries\Purchase\PurchaseInvoiceService();
    }

    public static function vendorTransferRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('vendorTransferRepository') : new \App\Models\VendorTransferRepository();
    }

    public static function vendorWarehouseRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('vendorWarehouseRepository') : new \App\Models\VendorWarehouseRepository();
    }

    public static function productApprovalRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('productApprovalRepository');
        }

        return new \App\Models\ProductApprovalRepository();
    }

    public static function passwordResetService($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('passwordResetService');
        }

        return new \App\Libraries\PasswordResetService();
    }

    public static function passwordResetRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('passwordResetRepository');
        }

        return new \App\Models\PasswordResetRepository();
    }

    // ---- Scheduler (Phase X0) ----
    public static function scheduledTaskRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('scheduledTaskRepository') : new \App\Models\ScheduledTaskRepository();
    }

    public static function taskRunner($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('taskRunner');
        }

        $runner = new \App\Libraries\Tasks\TaskRunner(static::scheduledTaskRepository());
        // Handlers registered by the features that own them:
        $runner->register('governance.expire_sla', static fn () => static::changeRequestEngine()->expireDue(new \DateTimeImmutable('now')));
        $runner->register('commission.promote_due', static fn () => static::commissionHoldService()->promoteDue(new \DateTimeImmutable('now')));

        return $runner;
    }

    // ---- Invoicing (Phase X2b) ----
    public static function invoiceSeriesRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('invoiceSeriesRepository') : new \App\Models\InvoiceSeriesRepository();
    }

    public static function invoiceService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('invoiceService') : new \App\Libraries\Invoicing\InvoiceService();
    }

    // ---- Rider payouts + async exports (Phase X5) ----
    public static function riderPayoutRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('riderPayoutRepository') : new \App\Models\RiderPayoutRepository();
    }

    public static function riderPayoutService($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('riderPayoutService');
        }

        return new \App\Libraries\Delivery\RiderPayoutService(
            static fn (int $riderUserId) => static::riderPayoutRepository()->planForRiderUser($riderUserId),
            static fn (array $entry) => static::riderPayoutRepository()->insertEntry($entry),
            static fn (int $riderUserId, string $monthStart) => static::riderPayoutRepository()->monthlyDelivered($riderUserId, $monthStart),
        );
    }

    public static function exportJobRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('exportJobRepository') : new \App\Models\ExportJobRepository();
    }

    public static function reportExportService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('reportExportService') : new \App\Libraries\Reports\ReportExportService();
    }

    // ---- Returns pipeline (Phase X4) ----
    public static function returnRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('returnRepository') : new \App\Models\ReturnRepository();
    }

    // ---- Combos (Phase S3) ----
    public static function comboRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('comboRepository') : new \App\Models\ComboRepository();
    }

    // ---- POS billing (shop receipt settings) ----
    public static function shopBillSettingsRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('shopBillSettingsRepository') : new \App\Models\ShopBillSettingsRepository();
    }

    // ---- Documents & PDFs (Phase X2c) ----
    public static function documentPdfService($getShared = true)
    {
        return $getShared ? static::getSharedInstance('documentPdfService') : new \App\Libraries\Pdf\DocumentPdfService();
    }

    public static function invoiceRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('invoiceRepository') : new \App\Models\InvoiceRepository();
    }

    public static function creditNoteRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('creditNoteRepository') : new \App\Models\CreditNoteRepository();
    }

    // ---- Commission hold window (Phase X2a) ----
    public static function commissionLedgerRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('commissionLedgerRepository') : new \App\Models\CommissionLedgerRepository();
    }

    public static function returnPolicyRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('returnPolicyRepository') : new \App\Models\ReturnPolicyRepository();
    }

    public static function commissionHoldService($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('commissionHoldService');
        }

        return new \App\Libraries\Commission\CommissionHoldService(
            static::commissionLedgerRepository(),
            static fn (?int $vendorId, ?int $categoryId) => static::returnPolicyRepository()->windowDays($vendorId, $categoryId),
            static function (int $settlementId, string $amount, string $reason): void {
                // Post-settlement return → clawback adjustment on the original batch.
                \Config\Database::connect()->table('settlement_adjustments')->insert([
                    'settlement_id' => $settlementId, 'type' => 'manual', 'amount' => $amount,
                    'reason' => mb_substr('Return clawback — ' . $reason, 0, 191), 'status' => 'applied',
                ]);
            },
            static fn (array $entry) => static::auditWriter()->log($entry),
        );
    }

    // ---- Governance: change-request engine (Phase X1) ----
    public static function auditWriter($getShared = true)
    {
        return $getShared ? static::getSharedInstance('auditWriter') : new \App\Libraries\Audit\AuditWriter();
    }

    /** Persistence for Admin\Concerns\MasterCrud (audit L12) — see App\Models\MasterRepository. */
    public static function masterRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('masterRepository') : new \App\Models\MasterRepository();
    }

    public static function changeRequestRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('changeRequestRepository') : new \App\Models\ChangeRequestRepository();
    }

    public static function approvalRuleRepository($getShared = true)
    {
        return $getShared ? static::getSharedInstance('approvalRuleRepository') : new \App\Models\ApprovalRuleRepository();
    }

    public static function governanceAppliers($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('governanceAppliers');
        }

        $registry = new \App\Libraries\Governance\ApplierRegistry();
        // X3 appliers: each calls the SAME repository the direct (owner) path
        // uses, so approved and direct writes can never diverge (G7).

        $registry->register('shop.settings', static function (array $req): void {
            $ok = static::vendorShopRepository()->update(
                (int) $req['entity_id'], (int) $req['vendor_id'],
                (array) ($req['payload_new']['fields'] ?? []), (int) $req['requested_by'],
            );
            if (! $ok) {
                throw new \RuntimeException('shop settings update failed');
            }
        });

        $registry->register('shop.hours', static function (array $req): void {
            $ok = static::vendorShopRepository()->saveHours(
                (int) $req['entity_id'], (int) $req['vendor_id'],
                (array) ($req['payload_new']['rows'] ?? []), (int) $req['requested_by'],
            );
            if (! $ok) {
                throw new \RuntimeException('saveHours failed');
            }
        });

        $registry->register('shop.holiday', static function (array $req): void {
            $ok = static::vendorShopRepository()->addHoliday(
                (int) $req['entity_id'], (int) $req['vendor_id'],
                (string) ($req['payload_new']['holiday_date'] ?? ''),
                (string) ($req['payload_new']['reason'] ?? ''), (int) $req['requested_by'],
            );
            if (! $ok) {
                throw new \RuntimeException('addHoliday failed');
            }
        });

        $registry->register('shop.online_status', static function (array $req): void {
            static::vendorShopRepository()->updateStatus(
                (int) $req['entity_id'], (int) $req['vendor_id'],
                (string) ($req['payload_new']['status'] ?? 'active'), (int) $req['requested_by'],
            );
        });

        $registry->register('staff.create', static function (array $req): void {
            $id = static::vendorStaffRepository()->createStaff(
                (int) $req['vendor_id'], (array) ($req['payload_new']['data'] ?? []), (int) $req['requested_by'],
            );
            if (! $id) {
                throw new \RuntimeException('createStaff failed');
            }
        });

        $registry->register('staff.role_change', static function (array $req): void {
            $ok = static::vendorStaffRepository()->updateStaff(
                (int) $req['entity_id'], (int) $req['vendor_id'],
                (array) ($req['payload_new']['data'] ?? []), (int) $req['requested_by'],
            );
            if (! $ok) {
                throw new \RuntimeException('updateStaff failed');
            }
        });

        $registry->register('staff.terminate', static function (array $req): void {
            $to = (string) ($req['payload_new']['status'] ?? 'suspended');
            $ok = static::vendorStaffRepository()->setStatus(
                (int) $req['entity_id'], (int) $req['vendor_id'], $to, (int) $req['requested_by'],
            );
            if (! $ok) {
                throw new \RuntimeException('setStatus failed');
            }
            if ($to === 'suspended' || $to === 'terminated') {
                // GAP-023: revoke live JWTs the moment access ends. The browser session
                // is ended by WebAuthFilter's per-request users.status re-check — the
                // DELETE from `sessions` that used to sit here matched nothing, because
                // CI4 sessions are FileHandler files, not DB rows.
                $staff = static::vendorStaffRepository()->findStaff((int) $req['entity_id'], (int) $req['vendor_id']);
                if ($staff !== null && (int) ($staff['user_id'] ?? 0) > 0) {
                    $db = \Config\Database::connect();
                    $db->table('auth_tokens')->where('user_id', (int) $staff['user_id'])->update(['status' => 'revoked']);
                }
            }
        });

        $registry->register('product.promote_online', static function (array $req): void {
            // POS-only product approved for online sale (AR-12): flip the channel
            // flag; publication still flows through the product-approvals gate.
            \Config\Database::connect()->table('products')
                ->where('id', (int) $req['entity_id'])->where('vendor_id', (int) $req['vendor_id'])
                ->update(['is_online_enabled' => 1]);
        });

        // S2 — product create/edit/submit by a branch manager. Each applier
        // replays the SAME repository call the owner's direct path uses (G7).
        $registry->register('product.submit', static function (array $req): void {
            $ok = static::productApprovalRepository()->submit((int) $req['entity_id'], (int) $req['requested_by']);
            if (! $ok) {
                throw new \RuntimeException('product could not be submitted from its current status');
            }
        });

        $registry->register('product.update', static function (array $req): void {
            $repo    = static::adminProductRepository();
            $product = $repo->findById((int) $req['entity_id']);
            if ($product === null) {
                throw new \RuntimeException('product no longer exists');
            }
            $fields         = (array) ($req['payload_new']['fields'] ?? []);
            $fields['uuid'] = $product['uuid'];
            $repo->update((int) $req['entity_id'], $fields, (int) $req['requested_by']);
        });

        // S4 — inter-shop stock trade. An approved request creates the transfer
        // via the same TransferService the owner's direct path uses (G7).
        $registry->register('transfer.request', static function (array $req): void {
            $p   = (array) ($req['payload_new'] ?? []);
            $res = static::transferService()->request(
                (int) $req['vendor_id'], (int) $p['from_shop_id'], (int) $p['to_shop_id'],
                (int) $p['variant_id'], (float) $p['qty'], (string) ($p['reason'] ?? ''), (int) $req['requested_by'],
            );
            if (! ($res['ok'] ?? false)) {
                throw new \RuntimeException((string) ($res['message'] ?? 'transfer request failed'));
            }
        });

        // S3 — combos. POS-only combo (AR-13) and online combo (AR-14) both
        // create the bundle product once approved (same ComboRepository the
        // owner's direct path uses).
        $registry->register('combo.create_pos', static function (array $req): void {
            $id = static::comboRepository()->create((int) $req['vendor_id'], (array) ($req['payload_new'] ?? []), (int) $req['requested_by']);
            if ($id === null) {
                throw new \RuntimeException('combo creation failed');
            }
        });
        $registry->register('combo.create_online', static function (array $req): void {
            $id = static::comboRepository()->create((int) $req['vendor_id'], (array) ($req['payload_new'] ?? []), (int) $req['requested_by']);
            if ($id === null) {
                throw new \RuntimeException('combo creation failed');
            }
        });

        // S2 — inventory movements proposed by a branch manager post to the
        // ledger only after the vendor approves (same service as the direct path).
        $registry->register('inventory.receive', static function (array $req): void {
            $p  = (array) ($req['payload_new'] ?? []);
            $ok = static::inventoryService()->receive((int) $p['variant_id'], (int) $p['shop_id'], (float) $p['qty'], (float) ($p['cost'] ?? 0), ['batch_no' => $p['batch_no'] ?? null, 'expiry_date' => $p['expiry_date'] ?? null], (int) $req['requested_by']);
            if (! $ok) {
                throw new \RuntimeException('stock receive failed');
            }
        });

        $registry->register('inventory.adjust', static function (array $req): void {
            $p  = (array) ($req['payload_new'] ?? []);
            $ok = static::inventoryService()->adjust((int) $p['variant_id'], (int) $p['shop_id'], (float) $p['delta'], (string) ($p['reason'] ?? ''), (string) ($p['notes'] ?? ''), (int) $req['requested_by']);
            if (! $ok) {
                throw new \RuntimeException('stock adjust failed');
            }
        });

        return $registry;
    }

    public static function changeRequestEngine($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('changeRequestEngine');
        }

        return new \App\Libraries\Governance\ChangeRequestEngine(
            static::changeRequestRepository(),
            static fn (string $entity, string $action, ?int $vendorId, ?int $categoryId) => static::approvalRuleRepository()->resolve($entity, $action, $vendorId, $categoryId),
            static::governanceAppliers(),
            static fn (array $entry) => static::auditWriter()->log($entry),
            static function (string $event, array $req): void {
                $uid = (int) ($req['requested_by'] ?? 0);
                if ($uid > 0) {
                    static::notificationService()->notify($uid, $event, [
                        'request_no' => (string) ($req['request_no'] ?? ''),
                        'action'     => ($req['entity_type'] ?? '') . '.' . ($req['action'] ?? ''),
                        'status'     => (string) ($req['status'] ?? ''),
                    ]);
                }
            },
        );
    }
}
