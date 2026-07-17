<?php

declare(strict_types=1);

namespace App\Libraries\Audit;

use Config\Database;

/**
 * AuditWriter — the (previously missing) writer for the append-only,
 * hash-chained audit trail. Every governance decision, override and document
 * action flows through here: actor, scope, IP, device, channel, approval
 * level, reason, plus old/new snapshots into audit_log_details.
 *
 * Chain: row_hash = sha256(prev_hash . canonical-json(row)). The unique key
 * on row_hash makes tampering (or double-insert) detectable; checkpointing
 * lives in audit_checkpoints.
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §14
 */
final class AuditWriter
{
    /**
     * @param array<string,mixed> $e keys: action*, entity_type*, entity_id,
     *   actor_user_id, principal_type, actor_role, scope_type, scope_id,
     *   request_id, ip, user_agent, device_id, channel, approval_level,
     *   reason, result, before, after, metadata
     */
    public function log(array $e): int
    {
        $db   = Database::connect();
        $prev = $db->table('audit_logs')->select('row_hash')->orderBy('id', 'DESC')->get(1)->getRowArray()['row_hash'] ?? null;

        $row = [
            'uuid'                 => $this->uuid(),
            'actor_user_id'        => $e['actor_user_id'] ?? null,
            'actor_principal_type' => $e['principal_type'] ?? 'system',
            'actor_role'           => $e['actor_role'] ?? null,
            'impersonated_by'      => $e['impersonated_by'] ?? null,
            'scope_type'           => $e['scope_type'] ?? 'platform',
            'scope_id'             => $e['scope_id'] ?? null,
            'action'               => (string) $e['action'],
            'entity_type'          => (string) $e['entity_type'],
            'entity_id'            => $e['entity_id'] ?? null,
            'request_id'           => $e['request_id'] ?? null,
            'ip'                   => $e['ip'] ?? null,
            'user_agent'           => isset($e['user_agent']) ? mb_substr((string) $e['user_agent'], 0, 255) : null,
            'device_id'            => $e['device_id'] ?? null,
            'channel'              => $e['channel'] ?? null,
            'approval_level'       => $e['approval_level'] ?? null,
            'reason'               => isset($e['reason']) ? mb_substr((string) $e['reason'], 0, 255) : null,
            'result'               => $e['result'] ?? 'success',
            'created_at'           => date('Y-m-d H:i:s'),
        ];
        $row['prev_hash'] = $prev;
        $row['row_hash']  = self::hashRow($prev, $row);

        $db->table('audit_logs')->insert($row);
        $id = (int) $db->insertID();

        if (array_key_exists('before', $e) || array_key_exists('after', $e) || array_key_exists('metadata', $e)) {
            $db->table('audit_log_details')->insert([
                'audit_log_id' => $id,
                'before'       => isset($e['before']) ? json_encode($e['before']) : null,
                'after'        => isset($e['after']) ? json_encode($e['after']) : null,
                'metadata'     => isset($e['metadata']) ? json_encode($e['metadata']) : null,
            ]);
        }

        return $id;
    }

    /** Pure: deterministic chain hash (prev_hash/row_hash excluded from input). */
    public static function hashRow(?string $prev, array $row): string
    {
        unset($row['prev_hash'], $row['row_hash']);
        ksort($row);

        return hash('sha256', ($prev ?? '') . json_encode($row));
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0F | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
