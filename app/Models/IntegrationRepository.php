<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * IntegrationRepository — platform integration accounts (integration_accounts):
 * Firebase, SMTP/email, GST API, SMS, etc. One row per provider; config (incl.
 * credentials) stored in the `config` JSON. upsert is idempotent per provider.
 *
 * @see docs/architecture/11-NOTIFICATION-ARCHITECTURE.md
 */
final class IntegrationRepository
{
    /**
     * Config keys that hold credentials, encrypted at rest — ported from esection, which
     * encrypts its SMTP password for the same reason: a database dump of this table
     * carried the SMTP password, the GST client secret and the Firebase service-account
     * private key as readable JSON, and this project has a 2.2 GB dump in its root.
     *
     * web_api_key is deliberately absent: the login page embeds it in its own HTML for
     * the Firebase JS SDK, so it is public by design and encrypting it would only imply
     * a secrecy it does not have.
     */
    private const SECRET_KEYS = ['password', 'client_secret', 'api_key', 'server_key', 'service_account_json'];

    /**
     * Self-describing marker for an encrypted value. base64 wraps the ciphertext because
     * raw bytes would break json_encode — the same treatment esection documents. Anything
     * WITHOUT the prefix is plaintext and is returned as-is, which is what keeps every
     * row written before this change working.
     */
    private const ENC_PREFIX = 'enc:v1:';

    /** @return array<string,mixed>|null with decoded 'config_arr'. */
    public function get(string $provider): ?array
    {
        try {
            $row = Database::connect()->table('integration_accounts')
                ->where('provider', $provider)->where('owner_type', 'platform')->where('deleted_at', null)
                ->get()->getRowArray();
        } catch (\Throwable $e) {
            // Callers degrade to "not configured", which is the safe behaviour — but it
            // is indistinguishable from a genuinely absent row unless we say so. A
            // transient DB fault here silently disables email/SMS on a system that was
            // working minutes earlier, so it must leave a trace at error level.
            log_message('error', 'IntegrationRepository: could not read provider "' . $provider . '": ' . $e->getMessage());

            return null;
        }

        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) ($row['config'] ?? '{}'), true);
        if (! is_array($decoded)) {
            // Corrupt/truncated JSON degrades to "not configured" the same way; without
            // this the operator sees an integration saved in the admin that never works.
            log_message('error', 'IntegrationRepository: provider "' . $provider . '" has unreadable config JSON.');
            $decoded = [];
        }
        $row['config_arr'] = $this->decryptSecrets($decoded);

        return $row;
    }

    /** @return array<string,mixed> just the config map (empty if unset). */
    public function config(string $provider): array
    {
        $row = $this->get($provider);

        return $row['config_arr'] ?? [];
    }

    /** @param array<string,mixed> $config Idempotent per provider. */
    public function upsert(string $provider, array $config, string $status = 'connected', ?int $actorId = null): bool
    {
        $config   = $this->encryptSecrets($config);
        $db       = Database::connect();
        $existing = $db->table('integration_accounts')
            ->select('id')->where('provider', $provider)->where('owner_type', 'platform')->where('deleted_at', null)
            ->get()->getRowArray();

        if ($existing !== null) {
            return $db->table('integration_accounts')->where('id', $existing['id'])->update([
                'config'     => json_encode($config),
                'status'     => $status,
                'updated_by' => $actorId,
            ]);
        }

        return (bool) $db->table('integration_accounts')->insert([
            'provider'   => $provider,
            'owner_type' => 'platform',
            'config'     => json_encode($config),
            'status'     => $status,
            'created_by' => $actorId,
        ]);
    }

    public function setStatus(string $provider, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('integration_accounts')
            ->where('provider', $provider)->where('owner_type', 'platform')->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }

    /**
     * Encrypt the known secret keys before storage.
     *
     * NO KEY IS REQUIRED. app/Config/Encryption.php ships with an empty key and the live
     * .env sets none, so requiring one — esection's choice — would break saving settings
     * in production the day this deploys. Without an encrypter the value stores plaintext
     * exactly as it always has, with a warning naming the fix (`php spark key:generate`).
     *
     * The prefix check makes the operation idempotent: a value that is already in stored
     * form is never wrapped twice, so a caller passing back what it read cannot corrupt it.
     *
     * @param array<string,mixed> $config
     *
     * @return array<string,mixed>
     */
    private function encryptSecrets(array $config): array
    {
        $encrypter = $this->encrypter();

        foreach (self::SECRET_KEYS as $key) {
            $value = $config[$key] ?? '';
            if (! is_string($value) || $value === '' || str_starts_with($value, self::ENC_PREFIX)) {
                continue;
            }
            if ($encrypter === null) {
                log_message('warning', 'IntegrationRepository: no encryption key configured — "' . $key . '" stored as plaintext. Run "php spark key:generate" to enable encryption at rest.');
                continue;
            }

            try {
                $config[$key] = self::ENC_PREFIX . base64_encode($encrypter->encrypt($value));
            } catch (\Throwable $e) {
                // A failed encrypt keeps the plaintext rather than losing the credential.
                log_message('warning', 'IntegrationRepository: could not encrypt "' . $key . '" (' . $e->getMessage() . ') — stored as plaintext.');
            }
        }

        return $config;
    }

    /**
     * Decrypt any value carrying the prefix; plaintext passes through untouched.
     *
     * MUST NEVER THROW. config('firebase') runs on the login page and config('smtp') on
     * password reset — a rotated or lost key has to degrade that one value to '' with an
     * error logged, not take authentication down.
     *
     * @param array<string,mixed> $config
     *
     * @return array<string,mixed>
     */
    private function decryptSecrets(array $config): array
    {
        foreach ($config as $key => $value) {
            if (! is_string($value) || ! str_starts_with($value, self::ENC_PREFIX)) {
                continue;
            }

            try {
                $raw = base64_decode(substr($value, strlen(self::ENC_PREFIX)), true);
                if ($raw === false) {
                    throw new \RuntimeException('not base64');
                }
                $encrypter = $this->encrypter();
                if ($encrypter === null) {
                    throw new \RuntimeException('no encryption key configured');
                }
                $config[$key] = (string) $encrypter->decrypt($raw);
            } catch (\Throwable $e) {
                log_message('error', 'IntegrationRepository: could not decrypt "' . $key . '" (' . $e->getMessage() . ') — it may have been saved under a previous encryption key. Re-enter it in the admin panel.');
                $config[$key] = '';
            }
        }

        return $config;
    }

    /** The shared encrypter, or null when none is configured — never an exception. */
    private function encrypter(): ?object
    {
        try {
            return service('encrypter');
        } catch (\Throwable) {
            return null;
        }
    }
}
