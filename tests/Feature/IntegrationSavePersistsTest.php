<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;

/**
 * The Transport dropdown, saved END TO END — through the real repository into a real
 * database row and back out through the send path.
 *
 * Every other test of this screen mocks integrationRepository, which proves the
 * controller's intent and nothing about persistence. That gap mattered during a live
 * outage: after the diagnosis said "switch Transport to sendmail", a send still reported
 * 'Could not send via "smtp"', and with only mock-based tests there was no way to say
 * whether the save→database→read chain was broken in code or the save had simply never
 * happened on the server. This test is the differential: while it is green, a saved
 * transport that fails to persist is an OPERATIONS problem (not saved, stale deploy,
 * wrong row edited by hand), not a code problem.
 */
final class IntegrationSavePersistsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');

        $db = Database::connect();
        $db->query('CREATE TABLE IF NOT EXISTS ' . $db->DBPrefix . 'integration_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT, owner_type TEXT, config TEXT, status TEXT,
            created_by INTEGER, updated_by INTEGER, deleted_at TEXT
        )');
        // The row the operator actually has: transport smtp, pointing at Gmail.
        $db->table('integration_accounts')->insert([
            'provider'   => 'smtp',
            'owner_type' => 'platform',
            'config'     => json_encode(['protocol' => 'smtp', 'host' => 'smtp.gmail.com', 'port' => '587',
                'username' => 'u@example.com', 'password' => 'old-pass', 'encryption' => 'tls',
                'from_email' => 'no-reply@example.com', 'from_name' => '', 'test_to' => '']),
            'status'     => 'connected',
        ]);

        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => ['integration.manage'], 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    protected function tearDown(): void
    {
        Database::connect()->table('integration_accounts')->where('provider', 'smtp')->delete();
        Services::reset();
        parent::tearDown();
    }

    private function sess(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Admin', 'principal_type' => 'platform'];
    }

    public function testSavingTheTransportPersistsToTheDatabaseAndTheSendPathReadsIt(): void
    {
        // 1. Save the form exactly as the operator would: dropdown moved to sendmail,
        //    password left blank (the form no longer echoes it).
        $data = [
            'protocol' => 'sendmail', 'host' => 'smtp.gmail.com', 'port' => '587',
            'username' => 'u@example.com', 'password' => '', 'encryption' => 'tls',
            'from_email' => 'no-reply@example.com', 'from_name' => '', 'test_to' => 'me@example.com',
        ];
        $data[csrf_token()] = csrf_hash();
        $this->withSession(service('session')->get() + $this->sess())->post('admin/integrations/email', $data);

        // 2. The DATABASE ROW — not a mock — now says sendmail, and blank-means-keep
        //    preserved the password rather than wiping it.
        $raw = Database::connect()->table('integration_accounts')
            ->where('provider', 'smtp')->get()->getRowArray();
        $stored = json_decode((string) $raw['config'], true);

        $this->assertSame('sendmail', $stored['protocol'], 'the saved transport must be what the dropdown said');
        $this->assertSame('old-pass', $stored['password'], 'a blank password field must keep the stored one');

        // 3. The SEND PATH reads it back: a fresh mailer built from the repository must
        //    identify as sendmail. The send itself fails on this machine (no sendmail
        //    binary on Windows) — what is being proven is which transport is attempted.
        Services::resetSingle('mailer');
        Services::resetSingle('integrationRepository');

        $sendData = ['to' => 'someone@example.com', 'subject' => 'probe', 'message' => 'probe'];
        $sendData[csrf_token()] = csrf_hash();
        $this->withSession(service('session')->get() + $this->sess())
            ->post('admin/integrations/email/compose', $sendData);

        $flash = (string) (session()->getFlashdata('error') ?? session()->getFlashdata('success'));
        $this->assertStringNotContainsString('via "smtp"', $flash, 'the send path must no longer be on smtp');
    }
}
