<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * AwsSettingsRepository — reads the existing `aws_settings` key/value table
 * (name -> key_value) that holds the S3 connection for the test_s3 storage
 * server. Mapped into the shape App\Libraries\Storage\DocumentStorage expects.
 * Resilient: returns empty config (-> dummy mode) if the table is unavailable.
 */
final class AwsSettingsRepository
{
    /** @return array<string,string> name => key_value */
    public function all(): array
    {
        try {
            $rows = Database::connect()->table('aws_settings')->select('name, key_value')->get()->getResultArray();
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['name']] = (string) $r['key_value'];
        }

        return $out;
    }

    /** Full rows for the admin editor. @return list<array<string,mixed>> */
    public function rows(): array
    {
        try {
            return Database::connect()->table('aws_settings')
                ->select('id, name, key_value, updatedOn')->orderBy('id', 'ASC')->get()->getResultArray();
        } catch (Throwable) {
            return [];
        }
    }

    /** Upsert a setting by name. */
    public function set(string $name, string $value, ?int $actorId = null): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $row = $db->table('aws_settings')->select('id')->where('name', $name)->get()->getRowArray();

        if ($row !== null) {
            $db->table('aws_settings')->where('id', $row['id'])->update(['key_value' => $value, 'updatedOn' => $now]);
        } else {
            $db->table('aws_settings')->insert(['name' => $name, 'key_value' => $value, 'createdOn' => $now, 'updatedOn' => $now]);
        }
    }

    /** S3 connection mapped to DocumentStorage's keys. @return array<string,string> */
    public function s3Config(): array
    {
        $s = $this->all();

        return [
            'endpoint'   => $s['endpoint'] ?? '',
            'bucket'     => $s['bucket'] ?? '',
            'region'     => $s['region'] ?? 'us-east-1',
            'access_key' => $s['client_key'] ?? '',
            'secret_key' => $s['client_secret'] ?? '',
            'url'        => $s['url'] ?? '',
            // 'local' forces all uploads/serving through the app's own same-origin
            // endpoint (no S3, no CORS/405). Anything else = use real S3 when set.
            'mode'       => strtolower(trim($s['storage_mode'] ?? 's3')),
        ];
    }
}
