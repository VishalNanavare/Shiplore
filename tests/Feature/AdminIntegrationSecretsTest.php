<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * How the admin integration screens handle SECRETS — ported from esection's scars.
 *
 * Three behaviours, each of which esection learned the hard way and Shiplore lacked:
 *
 * 1. A saved secret is never echoed back into the form HTML. The SMTP password (and the
 *    Firebase service-account private key) sat in the page source of the settings screen
 *    for anyone with view-source, a proxy log, or a shoulder. A password input does not
 *    need its value to keep it — blank now means "keep what is saved".
 *
 * 2. Blank-means-keep, for secret fields ONLY. Non-secret fields keep their existing
 *    semantics (blank clears), pinned here so the new rule cannot quietly widen.
 *
 * 3. ALL whitespace is stripped from password fields on save. Google displays an App
 *    Password as four spaced groups ("abcd efgh ijkl mnop") purely for readability; the
 *    spaces are not part of the credential, and pasting them yields a 19-character string
 *    that fails with exactly the same 535 as a genuinely wrong password. esection's
 *    comment records that its deployment was burned by precisely this. The strip does NOT
 *    apply to the service-account JSON, whose whitespace is meaningful.
 */
final class AdminIntegrationSecretsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $repo;
    private object $aws;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');

        $this->repo = new class {
            /** @var array<string,mixed>|null captured by upsert */
            public ?array $captured = null;

            public function get(string $p): ?array
            {
                return ['provider' => $p, 'status' => 'connected', 'config' => '{}', 'config_arr' => $this->config($p)];
            }

            public function config(string $p): array
            {
                if ($p === 'firebase') {
                    return ['project_id' => 'proj', 'web_api_key' => 'webkey', 'sender_id' => '1',
                        'service_account_json' => '{"private_key":"SECRETJSONKEY"}'];
                }

                return ['protocol' => 'smtp', 'host' => 'smtp.example.com', 'port' => '587', 'username' => 'u@example.com',
                    'password' => 'storedpass123', 'encryption' => 'tls', 'from_email' => 'no-reply@example.com', 'test_to' => ''];
            }

            public function upsert(string $p, array $c, string $s = 'connected', ?int $a = null): bool
            {
                $this->captured = $c;

                return true;
            }

            public function setStatus(string $p, string $s, ?int $a = null): bool { return true; }
        };
        Services::injectMock('integrationRepository', $this->repo);

        $this->aws = new class {
            /** @var list<array{0:string,1:string}> captured set() calls */
            public array $sets = [];

            public function rows(): array
            {
                return [
                    ['id' => 1, 'name' => 'endpoint', 'key_value' => 'https://s3.example', 'updated_at' => '2026-08-01 00:00'],
                    ['id' => 2, 'name' => 'client_secret', 'key_value' => 's3cretvalue99', 'updated_at' => '2026-08-01 00:00'],
                ];
            }

            public function set(string $name, string $value, ?int $userId = null): bool
            {
                $this->sets[] = [$name, $value];

                return true;
            }
        };
        Services::injectMock('awsSettingsRepository', $this->aws);
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function grant(): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => ['integration.manage'], 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Admin', 'principal_type' => 'platform'];
    }

    /** POST the email settings form with every field present, overriding what the test cares about. */
    private function saveEmail(array $over = []): void
    {
        $data = $over + [
            'protocol' => 'smtp', 'host' => 'smtp.example.com', 'port' => '587', 'username' => 'u@example.com',
            'password' => '', 'encryption' => 'tls', 'from_email' => 'no-reply@example.com', 'from_name' => '', 'test_to' => '',
        ];
        $data[csrf_token()] = csrf_hash();

        $this->withSession(service('session')->get() + $this->sess())->post('admin/integrations/email', $data);
    }

    // ------------------------------------------------------------- no echo

    public function testTheSavedSmtpPasswordIsNotEchoedIntoTheFormHtml(): void
    {
        $this->grant();

        $r = $this->withSession($this->sess())->get('admin/integrations/email');

        // Entities decoded first: esc(..., 'attr') encodes even spaces (&#x20;), so a
        // literal search for the placeholder fails against correct output — the same trap
        // the compose-screen prefill test hit.
        $html = html_entity_decode((string) $r->response()->getBody(), ENT_QUOTES | ENT_HTML5);

        $this->assertStringNotContainsString('storedpass123', $html, 'the saved password must never reach the page source');
        // Positive anchor so this cannot pass by rendering nothing at all.
        $this->assertStringContainsString('leave blank to keep', $html, 'the operator is told a value is saved');
    }

    public function testTheSavedServiceAccountJsonIsNotEchoedEither(): void
    {
        $this->grant();

        $r    = $this->withSession($this->sess())->get('admin/integrations/firebase');
        $html = (string) $r->response()->getBody();

        $this->assertStringNotContainsString('SECRETJSONKEY', $html, 'the service-account private key is the most sensitive value on any of these screens');
    }

    /** Non-secret values still prefill — the fix must not blank the whole form. */
    public function testNonSecretValuesStillPrefill(): void
    {
        $this->grant();

        $r = $this->withSession($this->sess())->get('admin/integrations/email');

        $this->assertStringContainsString('smtp.example.com', (string) $r->response()->getBody());
    }

    // ------------------------------------------------------------- blank keeps

    public function testABlankPasswordFieldKeepsTheStoredValue(): void
    {
        $this->grant();

        $this->saveEmail(['password' => '']);

        $this->assertNotNull($this->repo->captured);
        $this->assertSame('storedpass123', $this->repo->captured['password'], 'blank means keep — saving other settings must not wipe the password');
    }

    public function testATypedPasswordReplacesTheStoredValue(): void
    {
        $this->grant();

        $this->saveEmail(['password' => 'brand-new-pass']);

        $this->assertSame('brand-new-pass', $this->repo->captured['password']);
    }

    /** Blank-means-keep is for secrets ONLY; a cleared non-secret field stays cleared. */
    public function testABlankNonSecretFieldStillClears(): void
    {
        $this->grant();

        $this->saveEmail(['host' => '']);

        $this->assertSame('', $this->repo->captured['host'], 'non-secret semantics must not change');
    }

    public function testABlankServiceAccountJsonKeepsTheStoredValue(): void
    {
        $this->grant();

        $data = ['project_id' => 'proj', 'web_api_key' => 'webkey', 'sender_id' => '1',
            'app_id' => '', 'server_key' => '', 'service_account_json' => ''];
        $data[csrf_token()] = csrf_hash();
        $this->withSession(service('session')->get() + $this->sess())->post('admin/integrations/firebase', $data);

        $this->assertSame('{"private_key":"SECRETJSONKEY"}', $this->repo->captured['service_account_json']);
    }

    // ------------------------------------------------------------- whitespace

    /**
     * THE GOOGLE APP PASSWORD TRAP. Pasted as displayed — "abcd efgh ijkl mnop" — the
     * spaces make it a 19-character credential Gmail rejects with the same 535 as a wrong
     * password. Nothing on any screen can tell the operator what happened.
     */
    public function testAllWhitespaceIsStrippedFromAPastedPassword(): void
    {
        $this->grant();

        $this->saveEmail(['password' => ' abcd efgh ijkl mnop ']);

        $this->assertSame('abcdefghijklmnop', $this->repo->captured['password']);
    }

    /** A password of only whitespace is blank after the strip — so it keeps, not saves. */
    public function testAWhitespaceOnlyPasswordCountsAsBlank(): void
    {
        $this->grant();

        $this->saveEmail(['password' => '   ']);

        $this->assertSame('storedpass123', $this->repo->captured['password']);
    }

    /** The strip must NOT touch the service-account JSON — its whitespace is meaningful. */
    public function testServiceAccountJsonWhitespaceIsPreserved(): void
    {
        $this->grant();

        $json = "{\n  \"private_key\": \"line one\"\n}";
        $data = ['project_id' => 'proj', 'web_api_key' => 'webkey', 'sender_id' => '1',
            'app_id' => '', 'server_key' => '', 'service_account_json' => $json];
        $data[csrf_token()] = csrf_hash();
        $this->withSession(service('session')->get() + $this->sess())->post('admin/integrations/firebase', $data);

        $this->assertSame($json, $this->repo->captured['service_account_json']);
    }

    // ------------------------------------------------------------- unsaved changes

    /**
     * "Test connection" must not silently test SETTINGS THAT ARE NOT SAVED.
     *
     * Save and Test are two submit buttons on one form. Change the Transport dropdown,
     * click Test, and the form posts to the test endpoint — which reads the SAVED config
     * from the database and never sees the dropdown. The operator watches a test of the
     * old transport and is told the old transport failed, with nothing on screen
     * suggesting their change was ignored.
     *
     * This cost a real diagnosis session: after establishing beyond doubt that the host
     * intercepts SMTP and that sendmail was the fix, the next test still reported
     * 'Could not send via "smtp"' — because the dropdown had been changed but not saved.
     */
    public function testTestingWithAnUnsavedTransportIsRefused(): void
    {
        $this->grant();

        $data = ['protocol' => 'sendmail', 'host' => 'smtp.example.com', 'from_email' => 'no-reply@example.com'];
        $data[csrf_token()] = csrf_hash();
        $this->withSession(service('session')->get() + $this->sess())->post('admin/integrations/email/test', $data);

        $error = (string) session()->getFlashdata('error');
        $this->assertStringContainsString('sendmail', $error, 'name what is on screen');
        $this->assertStringContainsString('smtp', $error, 'and what is actually saved');
        // 'Save settings', not 'save' — the loose form is satisfied by the phrase
        // "is what has been saved" elsewhere in the same message, so it passed with the
        // instruction deleted. A mutation run caught it.
        $this->assertStringContainsString('Save settings', $error, 'and what to do about it');
    }

    /** A matching transport tests normally — the guard must not block the ordinary case. */
    public function testTestingWithTheSavedTransportProceeds(): void
    {
        $this->grant();

        $data = ['protocol' => 'smtp', 'test_to' => 'someone@example.com'];
        $data[csrf_token()] = csrf_hash();
        $this->withSession(service('session')->get() + $this->sess())->post('admin/integrations/email/test', $data);

        // Asserted against text the guard REALLY emits. The first version looked for
        // 'not been saved', which appears nowhere — the message says "is what has been
        // saved" — so it passed whether or not the guard fired.
        $this->assertStringNotContainsString(
            'is what has been saved',
            (string) session()->getFlashdata('error'),
            'an unchanged transport must not be reported as unsaved',
        );
    }

    /** A form that posts no protocol at all (other providers) must not trip the guard. */
    public function testAProviderWithoutATransportFieldIsUnaffected(): void
    {
        $this->grant();

        $data = ['provider_name' => 'x', 'base_url' => 'https://x', 'client_id' => 'c', 'client_secret' => ''];
        $data[csrf_token()] = csrf_hash();
        $this->withSession(service('session')->get() + $this->sess())->post('admin/integrations/gst-api/test', $data);

        $this->assertStringNotContainsString('is what has been saved', (string) session()->getFlashdata('error'));
    }

    /**
     * A fresh install posting a NON-DEFAULT transport is told to save first.
     *
     * This reverses an earlier decision, deliberately. The first version stayed silent
     * when nothing was saved, reasoning that the missing-required-fields check would
     * explain things - but with nothing saved the test RUNS WITH THE DEFAULT "smtp"
     * while the screen says "sendmail", which is the exact shape of the live incident:
     * the operator watches smtp fail and believes sendmail was tested. An audit ranked
     * this hole first among the guard's gaps.
     */
    public function testAFreshInstallPostingADifferentTransportIsToldToSaveFirst(): void
    {
        $this->grant();
        Services::injectMock('integrationRepository', new class {
            public function get(string $p): ?array { return null; }
            public function config(string $p): array { return []; }
            public function upsert(string $p, array $c, string $s = 'connected', ?int $a = null): bool { return true; }
            public function setStatus(string $p, string $s, ?int $a = null): bool { return true; }
        });

        $data = ['protocol' => 'sendmail', 'from_email' => 'no-reply@example.com'];
        $data[csrf_token()] = csrf_hash();
        $this->withSession(service('session')->get() + $this->sess())->post('admin/integrations/email/test', $data);

        $error = (string) session()->getFlashdata('error');
        $this->assertStringContainsString('No transport has been saved yet', $error);
        $this->assertStringContainsString('Save settings', $error);
    }

    /** Posting the default itself on a fresh install is NOT a mismatch - nothing differs. */
    public function testAFreshInstallPostingTheDefaultTransportIsNotBlocked(): void
    {
        $this->grant();
        Services::injectMock('integrationRepository', new class {
            public function get(string $p): ?array { return null; }
            public function config(string $p): array { return []; }
            public function upsert(string $p, array $c, string $s = 'connected', ?int $a = null): bool { return true; }
            public function setStatus(string $p, string $s, ?int $a = null): bool { return true; }
        });

        $data = ['protocol' => 'smtp', 'from_email' => 'no-reply@example.com'];
        $data[csrf_token()] = csrf_hash();
        $this->withSession(service('session')->get() + $this->sess())->post('admin/integrations/email/test', $data);

        $this->assertStringNotContainsString('No transport has been saved yet', (string) session()->getFlashdata('error'));
    }

    // ------------------------------------------------------------- select whitelist

    /**
     * Select fields only accept their declared options; anything else keeps the stored
     * value. A missing or empty "protocol" used to persist '' - which every reader
     * coerces to smtp - so a truncated POST or a stale form could silently flip the
     * transport back while reporting "settings saved".
     */
    public function testAnUnknownTransportValueKeepsTheStoredOne(): void
    {
        $this->grant();

        $this->saveEmail(['protocol' => 'carrier-pigeon']);

        $this->assertSame('smtp', $this->repo->captured['protocol'], 'an out-of-whitelist value must not persist');
    }

    public function testABlankTransportKeepsTheStoredOne(): void
    {
        $this->grant();

        $this->saveEmail(['protocol' => '']);

        $this->assertSame('smtp', $this->repo->captured['protocol'], "'' reads back as smtp everywhere - it must never be stored");
    }

    public function testAValidTransportChangeIsSaved(): void
    {
        $this->grant();

        $this->saveEmail(['protocol' => 'sendmail']);

        $this->assertSame('sendmail', $this->repo->captured['protocol']);
    }

    // ------------------------------------------------------------- failed writes

    /**
     * A failed database write must not be reported as saved.
     *
     * save() used to discard upsert()'s boolean and flash "settings saved."
     * unconditionally - so a write that failed (poisoned JSON, schema drift, vanished
     * row) looked identical to success, and the operator had no reason to doubt it.
     */
    public function testAFailedWriteIsNotReportedAsSaved(): void
    {
        $this->grant();
        Services::injectMock('integrationRepository', new class {
            public function get(string $p): ?array { return null; }
            public function config(string $p): array { return []; }
            public function upsert(string $p, array $c, string $s = 'connected', ?int $a = null): bool { return false; }
            public function setStatus(string $p, string $s, ?int $a = null): bool { return true; }
        });

        $this->saveEmail(['protocol' => 'sendmail']);

        $this->assertNull(session()->getFlashdata('success'), 'no success flash for a failed write');
        $this->assertStringContainsStringIgnoringCase('could not be saved', (string) session()->getFlashdata('error'));
    }

    // ------------------------------------------------------------- AWS screen

    public function testTheAwsSecretIsNotEchoedIntoTheFormHtml(): void
    {
        $this->grant();

        $r = $this->withSession($this->sess())->get('admin/integrations/aws');

        $this->assertStringNotContainsString('s3cretvalue99', (string) $r->response()->getBody());
    }

    public function testABlankAwsSecretIsSkippedOnSaveRatherThanWiped(): void
    {
        $this->grant();

        $data = ['name' => ['endpoint', 'client_secret'], 'value' => ['https://s3.new', '']];
        $data[csrf_token()] = csrf_hash();
        $this->withSession(service('session')->get() + $this->sess())->post('admin/integrations/aws/save', $data);

        $this->assertSame([['endpoint', 'https://s3.new']], $this->aws->sets, 'the blank secret must not be written; the non-secret must');
    }

    /** Skip-on-blank is for secrets ONLY — clearing a non-secret row must still write. */
    public function testABlankNonSecretAwsValueStillWrites(): void
    {
        $this->grant();

        $data = ['name' => ['endpoint'], 'value' => ['']];
        $data[csrf_token()] = csrf_hash();
        $this->withSession(service('session')->get() + $this->sess())->post('admin/integrations/aws/save', $data);

        $this->assertSame([['endpoint', '']], $this->aws->sets, 'blank non-secret values keep their existing clear semantics');
    }

    public function testATypedAwsSecretIsSaved(): void
    {
        $this->grant();

        $data = ['name' => ['client_secret'], 'value' => ['fresh-secret']];
        $data[csrf_token()] = csrf_hash();
        $this->withSession(service('session')->get() + $this->sess())->post('admin/integrations/aws/save', $data);

        $this->assertSame([['client_secret', 'fresh-secret']], $this->aws->sets);
    }
}
