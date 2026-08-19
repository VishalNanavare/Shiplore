<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * The compose-a-test-email screen (Admin\IntegrationController::compose/sendTest).
 *
 * "Test connection" proves the transport accepted SOMETHING, with fixed content the
 * operator cannot change. That is not enough to answer the questions that actually come
 * up during an email outage: does a real subject line survive, does the body arrive
 * readable, does delivery to THIS mailbox work rather than the one saved in settings.
 * This screen takes all three from the operator.
 *
 * It is deliberately narrow, because "authenticated admin can send arbitrary mail from
 * the company domain" is a phishing primitive, not just a feature:
 *   - the body is escaped, never passed through as markup
 *   - every send carries a footer naming it as an admin test, with the sender's user id
 *   - it is throttled and permission-gated like the existing test action
 */
final class AdminTestEmailTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');

        Services::injectMock('integrationRepository', new class {
            public function get(string $p): ?array { return ['provider' => $p, 'status' => 'connected', 'config' => '{}', 'config_arr' => []]; }
            public function config(string $p): array
            {
                return ['protocol' => 'smtp', 'host' => 'smtp.example.com', 'port' => '587', 'username' => 'u@example.com',
                    'password' => 'pw', 'encryption' => 'tls', 'from_email' => 'no-reply@example.com', 'test_to' => 'saved@example.com'];
            }
            public function upsert(string $p, array $c, string $s = 'connected', ?int $a = null): bool { return true; }
            public function setStatus(string $p, string $s, ?int $a = null): bool { return true; }
        });

        $this->mailer = new class {
            /** @var list<array{to:string,subject:string,body:string}> */
            public array $sent  = [];
            public bool $ok     = true;
            public bool $badPair = false;
            public function configured(): bool { return true; }
            public function hasCryptoMismatch(): bool { return $this->badPair; }
            public function impliedCrypto(): ?string { return 'tls'; }
            public function lastError(): string { return $this->ok ? '' : 'Connection timed out after 30s'; }
            public function send(string $to, string $subject, string $body): bool
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

                return $this->ok;
            }
        };
        Services::injectMock('mailer', $this->mailer);
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
        return ['isLoggedIn' => true, 'user_id' => 7, 'user_name' => 'Super Admin', 'principal_type' => 'platform'];
    }

    private function send(array $data = [])
    {
        $data += ['to' => 'someone@example.com', 'subject' => 'Hello there', 'message' => 'Plain body'];
        $data[csrf_token()] = csrf_hash();

        return $this->withSession(service('session')->get() + $this->sess())
            ->post('admin/integrations/email/compose', $data);
    }

    // ------------------------------------------------------------------ the screen

    public function testTheComposeScreenRenders(): void
    {
        $this->grant(['integration.manage']);

        $r = $this->withSession($this->sess())->get('admin/integrations/email/compose');

        $r->assertStatus(200);

        // Asserted against the raw body, NOT assertSee(): its second argument is a CSS
        // selector, not a "raw" flag, so assertSee('name="to"', 'raw') looks for a <raw>
        // element, finds none, and the check is decided by the selector rather than the
        // string. The three things the operator asked to control, by form field name.
        $html = (string) $r->response()->getBody();
        $this->assertStringContainsString('name="to"', $html);
        $this->assertStringContainsString('name="subject"', $html);
        $this->assertStringContainsString('name="message"', $html);
    }

    /** The saved test_to prefills the box — the operator should not retype it every time. */
    public function testTheRecipientBoxIsPrefilledFromSettings(): void
    {
        $this->grant(['integration.manage']);

        $r = $this->withSession($this->sess())->get('admin/integrations/email/compose');

        // Entities decoded first: esc(..., 'attr') renders the @ as &#x40;, so a literal
        // search for the address fails against correct output. Decoding states what is
        // actually being asserted — the box is prefilled — without pinning the escaper's
        // choice of entity form.
        $html = html_entity_decode((string) $r->response()->getBody(), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('value="saved@example.com"', $html);
    }

    public function testTheScreenIsPermissionGated(): void
    {
        $this->grant(['some.other.permission']);

        $r = $this->withSession($this->sess())->get('admin/integrations/email/compose');

        $this->assertNotSame(200, $r->response()->getStatusCode(), 'compose must not render without integration.manage');
    }

    public function testSendingIsPermissionGated(): void
    {
        $this->grant(['some.other.permission']);

        $this->send();

        $this->assertSame([], $this->mailer->sent, 'no mail may be sent without integration.manage');
    }

    // ------------------------------------------------------------------ sending

    /** THE POINT OF THE SCREEN: what the operator typed is what gets sent. */
    public function testTheOperatorsSubjectAndRecipientAreUsedVerbatim(): void
    {
        $this->grant(['integration.manage']);

        $this->send(['to' => 'me@example.com', 'subject' => 'Does this arrive?', 'message' => 'Body text']);

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('me@example.com', $this->mailer->sent[0]['to']);
        $this->assertSame('Does this arrive?', $this->mailer->sent[0]['subject'], 'the subject must not be rewritten or prefixed — the operator is testing the subject itself');
        $this->assertStringContainsString('Body text', $this->mailer->sent[0]['body']);
    }

    public function testAnInvalidRecipientIsRefusedWithoutSending(): void
    {
        $this->grant(['integration.manage']);

        $this->send(['to' => 'not-an-address']);

        $this->assertSame([], $this->mailer->sent);
    }

    public function testAnEmptySubjectIsRefusedWithoutSending(): void
    {
        $this->grant(['integration.manage']);

        $this->send(['subject' => '   ']);

        $this->assertSame([], $this->mailer->sent);
    }

    public function testAnEmptyMessageIsRefusedWithoutSending(): void
    {
        $this->grant(['integration.manage']);

        $this->send(['message' => '']);

        $this->assertSame([], $this->mailer->sent);
    }

    /**
     * A failure names the reason.
     *
     * The whole reason lastError() exists: on shared hosting the operator cannot read
     * writable/logs, so a bare "could not send" ends the diagnosis.
     */
    public function testAFailureReportsTheActualReason(): void
    {
        $this->grant(['integration.manage']);
        $this->mailer->ok = false;

        $r = $this->send();

        $this->assertStringContainsString('Connection timed out after 30s', (string) session()->getFlashdata('error'));
        $r->assertRedirect();
    }

    /** The same 465/tls trap the Test button catches — this path must not bypass it. */
    public function testAContradictingPortAndEncryptionIsRefusedBeforeSending(): void
    {
        $this->grant(['integration.manage']);
        $this->mailer->badPair = true;

        $this->send();

        $this->assertSame([], $this->mailer->sent, 'a mismatched pair hangs until timeout — refuse it up front');
    }

    // ------------------------------------------------------------------ containment

    /**
     * The body is ESCAPED.
     *
     * Without this the screen is an authenticated open relay for markup: an arbitrary
     * recipient, an arbitrary subject and a clickable disguised link, sent from the
     * company's own domain and passing SPF/DKIM. That is a better phishing tool than
     * most attackers can build, and the feature does not need raw HTML to do its job —
     * the operator is checking that a message arrives readable, not authoring markup.
     */
    public function testMarkupInTheMessageIsEscapedRatherThanSent(): void
    {
        $this->grant(['integration.manage']);

        $this->send(['message' => '<a href="http://evil.example/login">Reset your password</a>']);

        $body = $this->mailer->sent[0]['body'];
        $this->assertStringNotContainsString('<a href', $body, 'operator input must never become live markup');
        $this->assertStringContainsString('Reset your password', $body, 'but the text itself still has to arrive, or the test proves nothing');
    }

    /** Subject too — a header is a worse place for injected content than a body. */
    public function testMarkupInTheSubjectIsNotSentAsMarkup(): void
    {
        $this->grant(['integration.manage']);

        $this->send(['subject' => "Plain\r\nBcc: victim@example.com"]);

        $this->assertStringNotContainsString("\r", $this->mailer->sent[0]['subject'], 'CRLF in a subject is header injection');
        $this->assertStringNotContainsString("\n", $this->mailer->sent[0]['subject']);
    }

    /**
     * Every send is stamped as a test, with WHO sent it.
     *
     * This is what stops the screen being a covert tool: the recipient can see it came
     * from the admin panel rather than from the business, and the user id ties it to an
     * account. It costs the operator nothing — the subject and the typed body are still
     * theirs, unchanged.
     */
    public function testEverySendCarriesATraceableTestFooter(): void
    {
        $this->grant(['integration.manage']);

        $this->send();

        $body = $this->mailer->sent[0]['body'];
        $this->assertStringContainsStringIgnoringCase('test message', $body);
        // 'user #7', not '#7'. The bare form is satisfied by the footer's own CSS colour
        // (#777), so it passed with the user id deleted — a mutation run caught it.
        $this->assertStringContainsString('user #7', $body, 'the sending admin user id must be on the message');
    }

    /** Newlines survive as line breaks, or a multi-line body arrives as one blob. */
    public function testLineBreaksInTheMessageSurvive(): void
    {
        $this->grant(['integration.manage']);

        $this->send(['message' => "First line\nSecond line"]);

        $this->assertStringContainsString('<br', $this->mailer->sent[0]['body']);
    }

    /** Compose belongs to email only — it makes no sense for the GST or Firebase panels. */
    public function testComposeIsNotAvailableForOtherProviders(): void
    {
        $this->grant(['integration.manage']);

        // Not registered at all, so routing throws rather than returning a status.
        $this->expectException(CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->withSession($this->sess())->get('admin/integrations/gst-api/compose');
    }
}
