<?php

declare(strict_types=1);

use App\Libraries\PasswordResetService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Phase 5 (web) — forgot/reset password flow: request page, send (no
 * enumeration), reset page, valid reset (password updated + token consumed),
 * invalid token (no update). CSRF enforced; repos mocked.
 */
final class AuthResetTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $users;
    private object $resets;
    private object $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new class {
            public ?int $updatedId = null;
            public function findByEmail(string $email): ?array
            {
                return $email === 'admin@platform.local' ? ['id' => 1, 'name' => 'Admin', 'email' => $email, 'status' => 'active'] : null;
            }
            public function updatePassword(int $userId, string $hash): bool { $this->updatedId = $userId; return true; }
        };
        $this->resets = new class {
            public array $row = []; // set per test
            public ?int $consumed = null;
            public function store(string $i, string $h, int $e): void {}
            public function findLatestReset(string $i): ?array { return $this->row ?: null; }
            public function consume(int $id): void { $this->consumed = $id; }
        };

        // Declares the WHOLE Mailer API, not just send(). A double that covers only what
        // the controller calls today breaks the day a controller calls one more method,
        // and it breaks pointing at the controller rather than at itself.
        // MailerMockDriftTest enforces this.
        $this->mailer = new class {
            public array $sent = [];
            public bool $ok = true;
            public function configured(): bool { return true; }
            public function lastError(): string { return $this->ok ? '' : 'send failed'; }
            public function diagnose(): string { return ''; }
            public function impliedCrypto(): ?string { return null; }
            public function hasCryptoMismatch(): bool { return false; }
            public function send(string $to, string $subject, string $body): bool
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
                return $this->ok;
            }
        };

        Services::injectMock('userRepository', $this->users);
        Services::injectMock('passwordResetRepository', $this->resets);
        Services::injectMock('mailer', $this->mailer);
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function postCsrf(string $uri, array $data)
    {
        $data[csrf_token()] = csrf_hash();
        return $this->withSession(service('session')->get())->post($uri, $data);
    }

    public function testForgotPageRenders(): void
    {
        $this->get('forgot-password')->assertStatus(200);
    }

    public function testSendResetRedirectsUniformly(): void
    {
        $result = $this->postCsrf('forgot-password', ['email' => 'admin@platform.local']);
        $result->assertRedirect();
        $this->assertStringContainsString('forgot-password', $result->getRedirectUrl());
    }

    public function testSendResetEmailsTheLink(): void
    {
        $this->postCsrf('forgot-password', ['email' => 'admin@platform.local'])->assertRedirect();

        $this->assertCount(1, $this->mailer->sent, 'a reset email is sent to a known user');
        $this->assertSame('admin@platform.local', $this->mailer->sent[0]['to']);
        $this->assertStringContainsString('reset-password', $this->mailer->sent[0]['body']);
    }

    public function testNoEmailForUnknownAddress(): void
    {
        $this->postCsrf('forgot-password', ['email' => 'nobody@nowhere.test'])->assertRedirect();

        $this->assertCount(0, $this->mailer->sent, 'no email (no user enumeration) for an unknown address');
    }

    public function testResetPageRenders(): void
    {
        $this->get('reset-password?email=admin@platform.local&token=abc')->assertStatus(200);
    }

    public function testValidResetUpdatesPassword(): void
    {
        $pair = (new PasswordResetService())->generate();
        $this->resets->row = ['id' => 7, 'code_hash' => $pair['hash'], 'expires_at' => date('Y-m-d H:i:s', time() + 1800)];

        $this->postCsrf('reset-password', [
            'email' => 'admin@platform.local', 'token' => $pair['token'],
            'password' => 'newpassw0rd', 'password_confirm' => 'newpassw0rd',
        ])->assertRedirect();

        $this->assertSame(1, $this->users->updatedId);   // password was updated
        $this->assertSame(7, $this->resets->consumed);   // token consumed (single-use)
    }

    public function testInvalidTokenDoesNotUpdatePassword(): void
    {
        $this->resets->row = ['id' => 7, 'code_hash' => hash('sha256', 'real-token'), 'expires_at' => date('Y-m-d H:i:s', time() + 1800)];

        $this->postCsrf('reset-password', [
            'email' => 'admin@platform.local', 'token' => 'WRONG-TOKEN',
            'password' => 'newpassw0rd', 'password_confirm' => 'newpassw0rd',
        ])->assertRedirect();

        $this->assertNull($this->users->updatedId); // unchanged
    }
}
