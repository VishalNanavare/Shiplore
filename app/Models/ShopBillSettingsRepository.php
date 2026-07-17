<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * ShopBillSettingsRepository — the per-shop bill/receipt configuration the POS
 * prints (header note, terms & conditions, footer, show-savings). Stored as
 * namespaced rows in `shop_settings` (namespace = "pos_bill"). forShop() merges
 * those over sensible defaults and bundles the shop's printed identity (name,
 * address, phone, GSTIN, state) so a receipt view needs a single call.
 *
 * defaults()/merge() are pure + unit-tested.
 *
 * @see docs/architecture/48-POS-BILLING.md
 */
final class ShopBillSettingsRepository
{
    private const NS = 'pos_bill';

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'header_note'  => 'Tax Invoice',
            'terms'        => "Goods once sold are taken back only as per store policy.\nThank you for shopping with us!",
            'footer_note'  => 'Visit again!',
            'show_savings' => true,
        ];
    }

    /** Pure: merge stored values over defaults, coercing types. @param array<string,mixed> $stored */
    public static function merge(array $stored): array
    {
        $out = self::defaults();
        foreach ($out as $k => $default) {
            if (! array_key_exists($k, $stored)) {
                continue;
            }
            $out[$k] = is_bool($default) ? (bool) $stored[$k] : (string) $stored[$k];
        }

        return $out;
    }

    /** Bill context for a shop: printed identity + merged settings. @return array<string,mixed> */
    public function forShop(int $shopId): array
    {
        $db   = Database::connect();
        $shop = $db->table('shops')->select('id, name, gstin, address_json, pincode, state_code')
            ->where('id', $shopId)->get()->getRowArray() ?? [];

        $stored = [];
        foreach ($db->table('shop_settings')->where('shop_id', $shopId)->where('namespace', self::NS)->get()->getResultArray() as $r) {
            $stored[(string) $r['key']] = json_decode((string) $r['value'], true);
        }

        $addr = json_decode((string) ($shop['address_json'] ?? '{}'), true) ?: [];

        return [
            'shop_id'     => $shopId,
            'shop_name'   => (string) ($shop['name'] ?? 'Store'),
            'gstin'       => $shop['gstin'] ?? null,
            'phone'       => $addr['phone'] ?? null,
            'state_code'  => $shop['state_code'] ?? null,
            'pincode'     => $shop['pincode'] ?? null,
            'address'     => trim(implode(', ', array_filter([
                $addr['line1'] ?? null, $addr['line2'] ?? null, $addr['city'] ?? null,
            ]))),
            'settings'    => self::merge($stored),
        ];
    }

    /** Upsert the bill settings for a shop. @param array<string,mixed> $in */
    public function save(int $shopId, array $in, ?int $actorId = null): void
    {
        $db     = Database::connect();
        $values = self::merge($in);
        foreach ($values as $key => $value) {
            $row = $db->table('shop_settings')->where('shop_id', $shopId)->where('namespace', self::NS)->where('key', $key)->get()->getRowArray();
            $payload = [
                'value'      => json_encode($value),
                'value_type' => is_bool($value) ? 'bool' : 'string',
                'updated_by' => $actorId,
            ];
            if ($row !== null) {
                $db->table('shop_settings')->where('id', $row['id'])->update($payload);
            } else {
                $db->table('shop_settings')->insert($payload + ['shop_id' => $shopId, 'namespace' => self::NS, 'key' => $key, 'created_by' => $actorId]);
            }
        }
    }
}
