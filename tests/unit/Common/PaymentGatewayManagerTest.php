<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Payment/PaymentGatewayInterface.php';
require_once __DIR__ . '/../../../app/Libraries/Payment/AbstractGateway.php';
require_once __DIR__ . '/../../../app/Libraries/Payment/Gateways/PayuGateway.php';
require_once __DIR__ . '/../../../app/Libraries/Payment/Gateways/RazorpayGateway.php';
require_once __DIR__ . '/../../../app/Libraries/Payment/Gateways/StripeGateway.php';
require_once __DIR__ . '/../../../app/Libraries/Payment/PaymentGatewayManager.php';

use App\Libraries\Payment\PaymentGatewayManager;

/**
 * Payment gateway driver layer — provider registry + config validation/test.
 * @see docs/architecture/12-PAYMENT-ARCHITECTURE.md
 */
final class PaymentGatewayManagerTest extends TestCase
{
    public function testRegistersPayuRazorpayStripe(): void
    {
        $providers = (new PaymentGatewayManager())->availableProviders();
        $this->assertArrayHasKey('payu', $providers);
        $this->assertArrayHasKey('razorpay', $providers);
        $this->assertArrayHasKey('stripe', $providers);
        $this->assertSame('PayU', $providers['payu']);
    }

    public function testValidPayuConfigPassesTest(): void
    {
        $result = (new PaymentGatewayManager())->test('payu', [
            'merchant_key' => 'KEY123', 'merchant_salt' => 'SALT456', 'auth_header' => 'AUTH',
        ]);
        $this->assertTrue($result['ok']);
    }

    public function testMissingFieldFailsTestWithMessage(): void
    {
        $result = (new PaymentGatewayManager())->test('payu', ['merchant_key' => 'KEY123']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Merchant Salt', $result['message']);
    }

    public function testUnknownProviderIsRejected(): void
    {
        $result = (new PaymentGatewayManager())->test('paypal', []);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Unknown', $result['message']);
    }

    public function testFieldsAreExposedForFormRendering(): void
    {
        $fields = (new PaymentGatewayManager())->fields('razorpay');
        $keys   = array_column($fields, 'key');
        $this->assertContains('key_id', $keys);
        $this->assertContains('key_secret', $keys);
    }
}
