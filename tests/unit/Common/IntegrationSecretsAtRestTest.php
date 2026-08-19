<?php

declare(strict_types=1);

use App\Models\IntegrationRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

/**
 * Secrets in integration_accounts are ENCRYPTED AT REST — ported from esection.
 *
 * The config column held the SMTP password, GST client secret and the Firebase
 * service-account private key as plaintext JSON. A database dump — and this project has a
 * 2.2 GB one sitting in its root — carried every one of them. esection encrypts before
 * storing; now this repository does too, transparently: upsert() encrypts the known secret
 * keys, get()/config() decrypt them, and no caller changes.
 *
 * Two constraints shaped the design:
 *
 * - NO ENCRYPTION KEY MAY BE REQUIRED. app/Config/Encryption.php has an empty key and the
 *   live server's .env sets none, so a hard requirement would break saving settings in
 *   production the day this deploys. When no encrypter is available the value is stored
 *   plaintext, exactly as before, with a warning in the log. Run `php spark key:generate`
 *   to turn encryption on.
 *
 * - EXISTING PLAINTEXT ROWS MUST KEEP WORKING. Encrypted values are self-describing
 *   ("enc:v1:" + base64 ciphertext); anything without the prefix is returned as-is. The
 *   ciphertext is base64-wrapped because raw binary would break json_encode — the same
 *   reason esection wraps it.
 *
 * - DECRYPTION FAILURE MUST NEVER THROW. config('smtp') sits on the password-reset path
 *   and the login page's firebase lookup; a rotated key must degrade that one value to ''
 *   with an error logged, not take the login page down.
 */
final class IntegrationSecretsAtRestTest extends CIUnitTestCase
{
    private const PROVIDER = 'enc-probe';

    protected function setUp(): void
    {
        parent::setUp();

        // The shared :memory: database outlives this file. The table is created only if
        // missing, rows are namespaced by provider and removed in tearDown, so nothing
        // here can flip another file's behaviour — the repository already degrades a
        // missing table and an empty table to the same "not configured".
        $db = Database::connect();
        $db->query('CREATE TABLE IF NOT EXISTS ' . $db->DBPrefix . 'integration_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT, owner_type TEXT, config TEXT, status TEXT,
            created_by INTEGER, updated_by INTEGER, deleted_at TEXT
        )');
    }

    protected function tearDown(): void
    {
        Database::connect()->table('integration_accounts')->like('provider', self::PROVIDER, 'after')->delete();
        Services::reset();
        parent::tearDown();
    }

    /** A real encrypter with a throwaway key, injected as the shared service. */
    private function withEncrypter(): void
    {
        $cfg      = new \Config\Encryption();
        $cfg->key = 'k5/Ml4kkyGqE2xRfLcqYzXeXwUgQzT1e0hC7VdPjK9M='; // fixed test key, base64 32 bytes
        $cfg->key = base64_decode($cfg->key);

        Services::injectMock('encrypter', Services::encrypter($cfg, false));
    }

    private function rawConfig(string $provider): string
    {
        $row = Database::connect()->table('integration_accounts')
            ->where('provider', $provider)->get()->getRowArray();

        return (string) ($row['config'] ?? '');
    }

    // ---------------------------------------------------------------- encrypted path

    public function testASavedPasswordIsNotStoredInPlaintext(): void
    {
        $this->withEncrypter();

        (new IntegrationRepository())->upsert(self::PROVIDER, ['host' => 'smtp.example.com', 'password' => 'sup3r-s3cret']);

        $raw = $this->rawConfig(self::PROVIDER);
        $this->assertStringNotContainsString('sup3r-s3cret', $raw, 'the credential must not appear in the stored JSON');
        $this->assertStringContainsString('enc:v1:', $raw, 'the stored value must carry the self-describing prefix');
    }

    public function testConfigReturnsTheDecryptedValue(): void
    {
        $this->withEncrypter();
        $repo = new IntegrationRepository();

        $repo->upsert(self::PROVIDER, ['host' => 'smtp.example.com', 'password' => 'sup3r-s3cret']);

        $this->assertSame('sup3r-s3cret', $repo->config(self::PROVIDER)['password'], 'callers must see the plaintext — no caller changes');
    }

    /** Only the secret keys are encrypted; the rest of the config stays inspectable. */
    public function testNonSecretKeysStayPlaintext(): void
    {
        $this->withEncrypter();

        (new IntegrationRepository())->upsert(self::PROVIDER, ['host' => 'smtp.example.com', 'password' => 'x']);

        $this->assertStringContainsString('smtp.example.com', $this->rawConfig(self::PROVIDER));
    }

    /** Every secret key the specs define is covered, not just the SMTP password. */
    public function testTheServiceAccountJsonAndClientSecretAreEncryptedToo(): void
    {
        $this->withEncrypter();

        (new IntegrationRepository())->upsert(self::PROVIDER, [
            'client_secret'        => 'gst-secret-1',
            'service_account_json' => '{"private_key":"firebase-key-1"}',
        ]);

        $raw = $this->rawConfig(self::PROVIDER);
        $this->assertStringNotContainsString('gst-secret-1', $raw);
        $this->assertStringNotContainsString('firebase-key-1', $raw);
    }

    /** Saving twice must not encrypt the ciphertext again — reads would then fail. */
    public function testResavingAnAlreadyEncryptedValueDoesNotDoubleEncrypt(): void
    {
        $this->withEncrypter();
        $repo = new IntegrationRepository();

        $repo->upsert(self::PROVIDER, ['password' => 'sup3r-s3cret']);
        $stored = $this->rawConfig(self::PROVIDER);
        $encrypted = json_decode($stored, true)['password'];

        // A caller (hypothetically) passing the stored form back in must not wrap it again.
        $repo->upsert(self::PROVIDER, ['password' => $encrypted]);

        $this->assertSame('sup3r-s3cret', $repo->config(self::PROVIDER)['password']);
    }

    // ---------------------------------------------------------------- degraded paths

    /** With no encryption key configured, saving still works — plaintext, as before. */
    public function testWithoutAnEncrypterTheValueStillSavesAndReads(): void
    {
        // No injection: app/Config/Encryption.php has an empty key, so service('encrypter')
        // throws and the repository falls back. This is the live server's situation today.
        $repo = new IntegrationRepository();

        $repo->upsert(self::PROVIDER, ['password' => 'plain-pass']);

        $this->assertSame('plain-pass', $repo->config(self::PROVIDER)['password']);
        $this->assertStringContainsString('plain-pass', $this->rawConfig(self::PROVIDER), 'stored plaintext — the pre-existing behaviour, not an error');
    }

    /** Rows written before this change — plaintext, no prefix — read exactly as before. */
    public function testAPreexistingPlaintextRowStillReads(): void
    {
        $this->withEncrypter();
        Database::connect()->table('integration_accounts')->insert([
            'provider' => self::PROVIDER, 'owner_type' => 'platform',
            'config'   => json_encode(['host' => 'h', 'password' => 'legacy-pass']),
            'status'   => 'connected',
        ]);

        $this->assertSame('legacy-pass', (new IntegrationRepository())->config(self::PROVIDER)['password']);
    }

    /**
     * A value that cannot be decrypted degrades to '' — it must NEVER throw.
     * config('firebase') runs on the login page and config('smtp') on password reset;
     * a rotated key must not take either down.
     */
    public function testACorruptCiphertextDegradesToEmptyNotThrow(): void
    {
        $this->withEncrypter();
        Database::connect()->table('integration_accounts')->insert([
            'provider' => self::PROVIDER, 'owner_type' => 'platform',
            'config'   => json_encode(['host' => 'h', 'password' => 'enc:v1:not-real-ciphertext']),
            'status'   => 'connected',
        ]);

        $cfg = (new IntegrationRepository())->config(self::PROVIDER);

        $this->assertSame('', $cfg['password'], 'unreadable secret degrades to empty');
        $this->assertSame('h', $cfg['host'], 'and the rest of the config survives');
    }

    // ---------------------------------------------------------------- write integrity

    /**
     * A config that cannot be JSON-encoded is REFUSED, not written as garbage.
     *
     * json_encode() returns false on invalid UTF-8, and the query builder writes false
     * as 0 - poisoning the whole config column: every later read degrades to [] and the
     * transport silently reads back as smtp while the UI said "settings saved".
     */
    public function testAConfigThatCannotBeEncodedIsRefusedWithoutDestroyingTheRow(): void
    {
        $repo = new IntegrationRepository();
        $repo->upsert(self::PROVIDER, ['protocol' => 'sendmail', 'host' => 'good']);

        $ok = $repo->upsert(self::PROVIDER, ['protocol' => 'sendmail', 'host' => "bad\xB1byte"]);

        $this->assertFalse($ok, 'an unencodable config must report failure');
        $this->assertSame('good', $repo->config(self::PROVIDER)['host'], 'and the previous row must survive intact');
    }

    /**
     * With duplicate rows, read and write MUST pick the same one - the lowest id.
     *
     * The table has no unique key on (provider, owner_type), and without ORDER BY the
     * "first row" is whatever the engine returns, so a hand-inserted duplicate could be
     * the one that gets written while the other is the one that gets read. During a live
     * incident this is indistinguishable from "the save is being lost".
     */
    public function testWithDuplicateRowsReadAndWriteAgreeOnTheLowestId(): void
    {
        $db = Database::connect();
        $db->table('integration_accounts')->insert(['provider' => self::PROVIDER, 'owner_type' => 'platform',
            'config' => json_encode(['protocol' => 'smtp']), 'status' => 'connected']);
        $db->table('integration_accounts')->insert(['provider' => self::PROVIDER, 'owner_type' => 'platform',
            'config' => json_encode(['protocol' => 'mail']), 'status' => 'connected']);

        $repo = new IntegrationRepository();
        $this->assertSame('smtp', $repo->config(self::PROVIDER)['protocol'], 'read follows the lowest id');

        $repo->upsert(self::PROVIDER, ['protocol' => 'sendmail']);

        $rows = $db->table('integration_accounts')->where('provider', self::PROVIDER)
            ->orderBy('id', 'ASC')->get()->getResultArray();
        $this->assertSame('sendmail', json_decode($rows[0]['config'], true)['protocol'], 'write lands on the same row the read uses');
        $this->assertSame('mail', json_decode($rows[1]['config'], true)['protocol'], 'the duplicate is untouched, not raced');
    }
}
