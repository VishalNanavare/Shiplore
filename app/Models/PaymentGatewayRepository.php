<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * PaymentGatewayRepository — platform payment-gateway accounts (gateway_accounts).
 * Add / update / enable-disable / delete gateways; expose the active set the
 * payment flow uses. Credentials live in the `config` JSON (production should
 * move secrets to a vault and store only a reference in key_ref).
 *
 * @see docs/architecture/12-PAYMENT-ARCHITECTURE.md
 */
final class PaymentGatewayRepository
{
    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return Database::connect()->table('gateway_accounts')
            ->select('id, provider, channel, status, priority, config, updated_at')
            ->where('owner_type', 'platform')->where('deleted_at', null)
            ->orderBy('priority', 'ASC')->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null config decoded into 'config_arr'. */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('gateway_accounts')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        if ($row === null) {
            return null;
        }
        $row['config_arr'] = json_decode((string) ($row['config'] ?? '{}'), true) ?: [];

        return $row;
    }

    /** Active gateways the payment flow can use, highest priority first. */
    public function active(?string $channel = null): array
    {
        $b = Database::connect()->table('gateway_accounts')
            ->select('id, provider, channel, priority, config')
            ->where('owner_type', 'platform')->where('status', 'active')->where('deleted_at', null)
            ->orderBy('priority', 'ASC');
        if ($channel !== null) {
            $b->where('channel', $channel);
        }

        return $b->get()->getResultArray();
    }

    /** @param array<string,mixed> $config */
    public function create(string $provider, string $channel, string $status, int $priority, array $config, ?int $actorId = null): bool
    {
        return (bool) Database::connect()->table('gateway_accounts')->insert([
            'provider'           => $provider,
            'owner_type'         => 'platform',
            'channel'            => $channel,
            'key_ref'            => $this->primaryRef($config),
            'webhook_secret_ref' => 'stored-in-config',
            'config'             => json_encode($config),
            'priority'           => $priority,
            'status'             => $status,
            'created_by'         => $actorId,
        ]);
    }

    /** @param array<string,mixed> $config */
    public function update(int $id, string $channel, string $status, int $priority, array $config, ?int $actorId = null): bool
    {
        return Database::connect()->table('gateway_accounts')
            ->where('id', $id)->where('deleted_at', null)
            ->update([
                'channel'  => $channel,
                'key_ref'  => $this->primaryRef($config),
                'config'   => json_encode($config),
                'priority' => $priority,
                'status'   => $status,
                'updated_by' => $actorId,
            ]);
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('gateway_accounts')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }

    public function delete(int $id, ?int $actorId = null): bool
    {
        return Database::connect()->table('gateway_accounts')
            ->where('id', $id)
            ->update(['deleted_at' => date('Y-m-d H:i:s'), 'updated_by' => $actorId]);
    }

    /** @param array<string,mixed> $config */
    private function primaryRef(array $config): string
    {
        foreach (['merchant_key', 'key_id', 'publishable_key'] as $k) {
            if (! empty($config[$k])) {
                return mb_substr((string) $config[$k], 0, 120);
            }
        }

        return 'configured';
    }
}
