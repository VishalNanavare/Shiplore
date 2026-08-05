<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * SettingsRepository — read-only view of platform settings, feature flags and
 * GST configuration (admin oversight; edits land in a later iteration).
 *
 * @see docs/architecture/24-ADMIN-PANEL.md (System Settings)
 */
final class SettingsRepository
{
    /**
     * Optional modules that an admin may hide system-wide (data is kept; the menu
     * + page access are gated). Key = sidebar slug = module flag.
     */
    public const OPTIONAL_MODULES = ['promotions' => 'Promotions', 'coupons' => 'Coupons'];

    /** @return list<array<string,mixed>> */
    public function settings(): array
    {
        return Database::connect()->table('settings')
            ->select('id, namespace, `key` AS skey, value, value_type, scope_type, status', false)
            ->orderBy('namespace', 'ASC')->orderBy('`key`', 'ASC', false)
            ->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    public function featureFlags(): array
    {
        return Database::connect()->table('feature_flags')
            ->select('id, `key` AS fkey, description, is_enabled, status', false)
            ->orderBy('`key`', 'ASC', false)
            ->get()->getResultArray();
    }

    /** Per-request memo of the max delivery radius (deliverability loops call this per variant). */
    private ?float $maxRadiusMemo = null;

    /** Platform-wide max delivery radius (km) a vendor may set. Default 5. */
    public function deliveryMaxRadiusKm(): float
    {
        if ($this->maxRadiusMemo !== null) {
            return $this->maxRadiusMemo;
        }
        try {
            $row = Database::connect()->table('settings')
                ->select('value')->where('namespace', 'delivery')->where('`key`', 'max_radius_km')
                ->get()->getRowArray();
        } catch (\Throwable) {
            return 5.0;
        }
        $v = $row !== null ? (float) json_decode((string) $row['value'], true) : 5.0;

        return $this->maxRadiusMemo = ($v > 0 ? $v : 5.0);
    }

    /** Upsert the platform max delivery radius (clamped to a sane 0.5–100 km). */
    public function setDeliveryMaxRadiusKm(float $km, ?int $actorId = null): void
    {
        $km = max(0.5, min(100.0, $km));
        $this->maxRadiusMemo = null; // invalidate the per-request memo after a change
        $db = Database::connect();
        $row = $db->table('settings')
            ->select('id')->where('namespace', 'delivery')->where('`key`', 'max_radius_km')
            ->get()->getRowArray();

        if ($row !== null) {
            $db->table('settings')->where('id', $row['id'])->update(['value' => json_encode($km), 'updated_by' => $actorId]);
        } else {
            $db->table('settings')->insert([
                'scope_type' => 'system', 'namespace' => 'delivery', 'key' => 'max_radius_km',
                'value' => json_encode($km), 'value_type' => 'decimal', 'status' => 'active', 'created_by' => $actorId,
            ]);
        }
    }

    /** @return array<string,mixed>|null */
    public function gstConfig(): ?array
    {
        $row = Database::connect()->table('gst_config')
            ->orderBy('id', 'ASC')->get()->getRowArray();

        return $row ?: null;
    }

    // ---- generic scalar settings (JSON-encoded value) ---------------------

    /**
     * Per-request memo, keyed "namespace|key". brandName()/logoUrl()/moduleEnabled()
     * are called 38 times across 20 files — several from inside views/partials that
     * render on every request (layouts/store, _store_header, _store_footer,
     * layouts/rider, _sidebar, _head, monline/_layout) — so an unmemoised get() was
     * a fresh SELECT for the identical row on every one of those calls.
     */
    private array $memo = [];

    /** Read a scalar setting (JSON-decoded), or $default when absent. */
    public function get(string $namespace, string $key, mixed $default = null): mixed
    {
        $namespace = $this->norm($namespace);
        $key       = $this->norm($key);
        $memoKey   = $namespace . '|' . $key;

        if (! array_key_exists($memoKey, $this->memo)) {
            try {
                $row = Database::connect()->table('settings')->select('value')
                    ->where('namespace', $namespace)
                    ->where("`key` = '{$key}'", null, false)
                    ->get()->getRowArray();
            } catch (\Throwable) {
                // A transient DB fault must not pin a default for the rest of the
                // request — deliberately not memoised, unlike a real miss below.
                return $default;
            }
            // A miss is memoised too (as null), so 6 calls for an absent key still
            // cost one round trip, not six.
            $this->memo[$memoKey] = $row !== null ? json_decode((string) $row['value'], true) : null;
        }

        return $this->memo[$memoKey] ?? $default;
    }

    /** Upsert a scalar setting. */
    public function set(string $namespace, string $key, mixed $value, string $type = 'string', ?int $actorId = null): void
    {
        $namespace = $this->norm($namespace);
        $key       = $this->norm($key);
        unset($this->memo[$namespace . '|' . $key]);
        $db        = Database::connect();
        $row       = $db->table('settings')->select('id')
            ->where('namespace', $namespace)->where("`key` = '{$key}'", null, false)->get()->getRowArray();

        if ($row !== null) {
            $db->table('settings')->where('id', $row['id'])->update(['value' => json_encode($value), 'updated_by' => $actorId]);
        } else {
            $db->table('settings')->insert([
                'scope_type' => 'system', 'namespace' => $namespace, 'key' => $key,
                'value' => json_encode($value), 'value_type' => $type, 'status' => 'active', 'created_by' => $actorId,
            ]);
        }
    }

    /** Platform brand / system name shown on documents + chrome. Default "Shiplore". */
    public function brandName(): string
    {
        $v = trim((string) $this->get('system', 'brand_name', 'Shiplore'));

        return $v !== '' ? $v : 'Shiplore';
    }

    /** URL (or asset path) for the platform logo; falls back to the built-in SVG. */
    public function logoUrl(): string
    {
        $v = trim((string) $this->get('system', 'brand_logo_url', ''));

        return $v !== '' ? $v : base_url('assets/images/logo.png');
    }

    /** Is an optional module switched on? (default on). */
    public function moduleEnabled(string $module): bool
    {
        return (bool) $this->get('modules', $this->norm($module) . '_enabled', true);
    }

    /** Sanitise a namespace/key to a safe token (used in raw SQL above). */
    private function norm(string $s): string
    {
        return (string) preg_replace('/[^a-z0-9_.]/i', '', $s);
    }
}
