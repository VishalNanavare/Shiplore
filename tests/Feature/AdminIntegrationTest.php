<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Hardening — Admin integration panels: Payment Gateways (list/add/enable/test)
 * and Firebase/Email/GST-API config. RBAC (integration.manage) + CSRF.
 * Repos mocked; the real PaymentGatewayManager (pure) drives validation.
 */
final class AdminIntegrationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function grant(array $permissions): void
    {
        Services::injectMock('capabilityRepository', new class ($permissions) {
            public function __construct(private array $perms) {}
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => $this->perms, 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Super Admin', 'principal_type' => 'platform'];
    }

    private function postSession(): array
    {
        return service('session')->get() + $this->sess();
    }

    private function gatewayMock(): void
    {
        Services::injectMock('paymentGatewayRepository', new class {
            public function list(): array { return [['id' => 1, 'provider' => 'payu', 'channel' => 'online', 'status' => 'active', 'priority' => 1, 'config' => '{}', 'updated_at' => '2026-06-08 10:00']]; }
            public function findById(int $id): ?array { return $id === 1 ? ['id' => 1, 'provider' => 'payu', 'channel' => 'online', 'status' => 'active', 'priority' => 1, 'config' => '{}', 'config_arr' => ['merchant_key' => 'K', 'merchant_salt' => 'S', 'auth_header' => 'A']] : null; }
            public function create(string $p, string $c, string $s, int $pr, array $cfg, ?int $a = null): bool { return true; }
            public function update(int $id, string $c, string $s, int $pr, array $cfg, ?int $a = null): bool { return true; }
            public function updateStatus(int $id, string $s, ?int $a = null): bool { return true; }
            public function delete(int $id, ?int $a = null): bool { return true; }
        });
    }

    public function testGatewayListRenders(): void
    {
        $this->grant(['integration.manage']);
        $this->gatewayMock();
        $r = $this->withSession($this->sess())->get('admin/payment-gateways');
        $r->assertStatus(200);
        $this->assertStringContainsString('PayU', (string) $r->getBody());
    }

    public function testGatewayNewFormRenders(): void
    {
        $this->grant(['integration.manage']);
        $this->gatewayMock();
        $r = $this->withSession($this->sess())->get('admin/payment-gateways/new');
        $r->assertStatus(200);
        $this->assertStringContainsString('Merchant Salt', (string) $r->getBody()); // PayU field
    }

    public function testGatewayStoreCreates(): void
    {
        $this->grant(['integration.manage']);
        $this->gatewayMock();
        $data = [csrf_token() => csrf_hash(), 'provider' => 'razorpay', 'channel' => 'online', 'status' => 'sandbox', 'priority' => '2', 'config' => ['key_id' => 'rzp', 'key_secret' => 'sec', 'webhook_secret' => 'wh']];
        $r = $this->withSession($this->postSession())->post('admin/payment-gateways/store', $data);
        $r->assertRedirect();
        $this->assertStringContainsString('admin/payment-gateways', $r->getRedirectUrl());
    }

    public function testGatewayTestUsesManager(): void
    {
        $this->grant(['integration.manage']);
        $this->gatewayMock();
        $r = $this->withSession($this->postSession())->post('admin/payment-gateways/1/test', [csrf_token() => csrf_hash()]);
        $r->assertRedirect(); // PayU config has all fields -> success flash
    }

    public function testGatewayDenied(): void
    {
        $this->grant(['shop.view']);
        $this->withSession($this->sess())->get('admin/payment-gateways')->assertRedirect();
    }

    private function integrationMock(): void
    {
        Services::injectMock('integrationRepository', new class {
            public function get(string $p): ?array { return ['provider' => $p, 'status' => 'connected', 'config' => '{}', 'config_arr' => ['project_id' => 'demo']]; }
            public function config(string $p): array { return ['project_id' => 'demo', 'web_api_key' => 'k', 'sender_id' => '1']; }
            public function upsert(string $p, array $c, string $s = 'connected', ?int $a = null): bool { return true; }
            public function setStatus(string $p, string $s, ?int $a = null): bool { return true; }
        });
    }

    public function testFirebaseFormRenders(): void
    {
        $this->grant(['integration.manage']);
        $this->integrationMock();
        $r = $this->withSession($this->sess())->get('admin/integrations/firebase');
        $r->assertStatus(200);
        $this->assertStringContainsString('Project ID', (string) $r->getBody());
    }

    public function testEmailSettingsSave(): void
    {
        $this->grant(['integration.manage']);
        $this->integrationMock();
        $data = [csrf_token() => csrf_hash(), 'host' => 'smtp.x', 'port' => '587', 'username' => 'u', 'password' => 'p', 'from_email' => 'a@b.c'];
        $r = $this->withSession($this->postSession())->post('admin/integrations/email', $data);
        $r->assertRedirect();
        $this->assertStringContainsString('admin/integrations/email', $r->getRedirectUrl());
    }

    public function testEmailTestSendsRealEmail(): void
    {
        $this->grant(['integration.manage']);
        Services::injectMock('integrationRepository', new class {
            public function get(string $p): ?array { return ['provider' => $p, 'status' => 'connected', 'config' => '{}', 'config_arr' => []]; }
            public function config(string $p): array { return ['host' => 'smtp.gmail.com', 'port' => '587', 'username' => 'u@example.com', 'password' => 'app-pass', 'encryption' => 'tls', 'from_email' => 'u@example.com']; }
            public function upsert(string $p, array $c, string $s = 'connected', ?int $a = null): bool { return true; }
            public function setStatus(string $p, string $s, ?int $a = null): bool { return true; }
        });
        // The mock must answer the FULL interface the controller calls, not just send().
        // It previously stopped at send(), so adding the port/encryption pre-check broke
        // this test with "Call to undefined method" — the mock had silently drifted
        // behind the real Mailer. Note the config above is 587/tls, a correct pair, so
        // hasCryptoMismatch() is false here and the send still happens.
        $mailer = new class {
            public array $sent = [];
            public function configured(): bool { return true; }
            public function hasCryptoMismatch(): bool { return false; }
            public function impliedCrypto(): ?string { return 'tls'; }
            public function lastError(): string { return ''; }
            public function diagnose(): string { return ''; }
            public function send(string $to, string $subject, string $body): bool { $this->sent[] = $to; return true; }
        };
        Services::injectMock('mailer', $mailer);

        $r = $this->withSession($this->postSession())->post('admin/integrations/email/test', [csrf_token() => csrf_hash()]);

        $r->assertRedirect();
        $this->assertCount(1, $mailer->sent, 'the Test button actually sends an email via the configured SMTP');
        $this->assertSame('u@example.com', $mailer->sent[0]);
    }

    public function testGstApiTest(): void
    {
        $this->grant(['integration.manage']);
        $this->integrationMock();
        $r = $this->withSession($this->postSession())->post('admin/integrations/gst-api/test', [csrf_token() => csrf_hash()]);
        $r->assertRedirect();
    }

    public function testUnknownIntegrationIs404(): void
    {
        $this->grant(['integration.manage']);
        $this->integrationMock();
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->withSession($this->sess())->get('admin/integrations/telegram');
    }

    public function testIntegrationDenied(): void
    {
        $this->grant(['shop.view']);
        $this->withSession($this->sess())->get('admin/integrations/firebase')->assertRedirect();
    }
}
