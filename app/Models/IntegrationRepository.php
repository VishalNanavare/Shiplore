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
        $row['config_arr'] = $decoded;

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
}
