<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Catalog\BundlePricing;
use Config\Database;

/**
 * ProductTypeRepository — config for the specialized product types: bundle/combo
 * components, digital downloads + license keys, subscription plans and gift-card
 * settings. The product's `product_type` chooses which applies.
 *
 * @see App\Libraries\Catalog\BundlePricing
 */
final class ProductTypeRepository
{
    // ---- Bundle / Combo -----------------------------------------------------

    /** @return list<array<string,mixed>> components with their variant labels + prices */
    public function bundleItems(int $bundleProductId): array
    {
        return Database::connect()->table('product_bundle_items pbi')
            ->select('pbi.id, pbi.item_variant_id, pbi.qty, pbi.is_optional, pbi.price_contribution, pv.sku, pv.base_price, p.title')
            ->join('product_variants pv', 'pv.id = pbi.item_variant_id', 'left')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->where('pbi.bundle_product_id', $bundleProductId)->where('pbi.deleted_at', null)
            ->orderBy('pbi.sort_order')->get()->getResultArray();
    }

    public function addBundleItem(int $bundleProductId, int $itemVariantId, float $qty, ?float $contribution, bool $optional, ?int $actorId = null): bool
    {
        if ($itemVariantId <= 0 || $qty <= 0) {
            return false;
        }

        return (bool) Database::connect()->table('product_bundle_items')->insert([
            'uuid' => $this->uuid(), 'bundle_product_id' => $bundleProductId, 'item_variant_id' => $itemVariantId,
            'qty' => $qty, 'is_optional' => $optional ? 1 : 0, 'price_contribution' => $contribution,
            'created_by' => $actorId,
        ]);
    }

    public function deleteBundleItem(int $id, int $bundleProductId): bool
    {
        return Database::connect()->table('product_bundle_items')->where('id', $id)->where('bundle_product_id', $bundleProductId)->update(['deleted_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array{price:float,components_total:float,savings:float} */
    public function bundleEffective(int $bundleProductId, string $mode, float $fixedPrice): array
    {
        $components = array_map(static fn ($i) => [
            'qty' => (float) $i['qty'], 'unit_price' => (float) $i['base_price'],
            'price_contribution' => $i['price_contribution'] !== null ? (float) $i['price_contribution'] : null,
        ], $this->bundleItems($bundleProductId));

        return BundlePricing::effective($mode, $fixedPrice, $components);
    }

    // ---- Digital downloads + license keys -----------------------------------

    /** @return list<array<string,mixed>> */
    public function downloads(int $productId): array
    {
        return Database::connect()->table('product_downloads')->where('product_id', $productId)->where('deleted_at', null)->orderBy('sort_order')->get()->getResultArray();
    }

    public function addDownload(int $productId, array $d, ?int $actorId = null): bool
    {
        if (trim((string) ($d['label'] ?? '')) === '') {
            return false;
        }

        return (bool) Database::connect()->table('product_downloads')->insert([
            'uuid' => $this->uuid(), 'product_id' => $productId, 'media_id' => ($d['media_id'] ?? null) ?: null,
            'label' => mb_substr((string) $d['label'], 0, 191),
            'download_limit' => ($d['download_limit'] ?? '') !== '' ? (int) $d['download_limit'] : null,
            'expiry_days' => ($d['expiry_days'] ?? '') !== '' ? (int) $d['expiry_days'] : null,
            'created_by' => $actorId,
        ]);
    }

    public function deleteDownload(int $id, int $productId): bool
    {
        return Database::connect()->table('product_downloads')->where('id', $id)->where('product_id', $productId)->update(['deleted_at' => date('Y-m-d H:i:s')]);
    }

    /** Add license keys (one per line). @return int number added */
    public function addLicenseKeys(int $productId, string $blob, ?int $actorId = null): int
    {
        $keys = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $blob) ?: [])));
        $n = 0;
        $db = Database::connect();
        foreach ($keys as $k) {
            $db->table('product_license_keys')->insert(['uuid' => $this->uuid(), 'product_id' => $productId, 'license_key' => mb_substr($k, 0, 191), 'status' => 'available', 'created_by' => $actorId]);
            $n++;
        }

        return $n;
    }

    /** @return array{available:int,assigned:int} */
    public function licenseStats(int $productId): array
    {
        $db = Database::connect();

        return [
            'available' => $db->table('product_license_keys')->where('product_id', $productId)->where('status', 'available')->countAllResults(),
            'assigned'  => $db->table('product_license_keys')->where('product_id', $productId)->where('status', 'assigned')->countAllResults(),
        ];
    }

    // ---- Subscription plans -------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function plans(int $productId): array
    {
        return Database::connect()->table('product_subscription_plans')->where('product_id', $productId)->where('deleted_at', null)->orderBy('price')->get()->getResultArray();
    }

    public function addPlan(int $productId, array $d, ?int $actorId = null): bool
    {
        if (trim((string) ($d['name'] ?? '')) === '') {
            return false;
        }

        return (bool) Database::connect()->table('product_subscription_plans')->insert([
            'uuid' => $this->uuid(), 'product_id' => $productId, 'name' => mb_substr((string) $d['name'], 0, 120),
            'interval_unit' => in_array($d['interval_unit'] ?? '', ['daily', 'weekly', 'monthly', 'yearly'], true) ? $d['interval_unit'] : 'monthly',
            'price' => (float) ($d['price'] ?? 0), 'billing_cycles' => ($d['billing_cycles'] ?? '') !== '' ? (int) $d['billing_cycles'] : null,
            'trial_days' => (int) ($d['trial_days'] ?? 0), 'auto_renew' => ! empty($d['auto_renew']) ? 1 : 0,
            'status' => 'active', 'created_by' => $actorId,
        ]);
    }

    public function deletePlan(int $id, int $productId): bool
    {
        return Database::connect()->table('product_subscription_plans')->where('id', $id)->where('product_id', $productId)->update(['deleted_at' => date('Y-m-d H:i:s')]);
    }

    // ---- Gift card ----------------------------------------------------------

    /** @return array<string,mixed> */
    public function giftConfig(int $productId): array
    {
        $row = Database::connect()->table('gift_card_products')->where('product_id', $productId)->get()->getRowArray() ?: [];
        $row['denominations'] = isset($row['denominations_json']) ? (json_decode((string) $row['denominations_json'], true) ?: []) : [];

        return $row;
    }

    public function setGiftConfig(int $productId, array $d, ?int $actorId = null): bool
    {
        $denoms = array_values(array_filter(array_map('floatval', array_map('trim', explode(',', (string) ($d['denominations'] ?? '')))), static fn ($v) => $v > 0));
        $payload = [
            'denominations_json' => $denoms === [] ? null : json_encode($denoms),
            'min_amount' => ($d['min_amount'] ?? '') !== '' ? (float) $d['min_amount'] : null,
            'max_amount' => ($d['max_amount'] ?? '') !== '' ? (float) $d['max_amount'] : null,
            'validity_days' => ($d['validity_days'] ?? '') !== '' ? (int) $d['validity_days'] : null,
            'is_reloadable' => ! empty($d['is_reloadable']) ? 1 : 0, 'updated_by' => $actorId,
        ];
        $db = Database::connect();
        if ($db->table('gift_card_products')->where('product_id', $productId)->countAllResults()) {
            return $db->table('gift_card_products')->where('product_id', $productId)->update($payload);
        }

        return (bool) $db->table('gift_card_products')->insert($payload + ['product_id' => $productId, 'created_by' => $actorId]);
    }

    // ---- Rental (P8) --------------------------------------------------------

    /** @return array<string,mixed> */
    public function rentalTerms(int $productId): array
    {
        return Database::connect()->table('product_rental_terms')->where('product_id', $productId)->get()->getRowArray() ?: [];
    }

    public function setRentalTerms(int $productId, array $d, ?int $actorId = null): bool
    {
        $payload = [
            'price_per_day' => (float) ($d['price_per_day'] ?? 0), 'deposit' => (float) ($d['deposit'] ?? 0),
            'min_days' => max(1, (int) ($d['min_days'] ?? 1)),
            'max_days' => ($d['max_days'] ?? '') !== '' ? (int) $d['max_days'] : null, 'updated_by' => $actorId,
        ];
        $db = Database::connect();
        if ($db->table('product_rental_terms')->where('product_id', $productId)->countAllResults()) {
            return $db->table('product_rental_terms')->where('product_id', $productId)->update($payload);
        }

        return (bool) $db->table('product_rental_terms')->insert($payload + ['product_id' => $productId, 'created_by' => $actorId]);
    }

    /** Variants the vendor can use as bundle components (own products). @return list<array<string,mixed>> */
    public function vendorVariants(int $vendorId, int $excludeProductId = 0): array
    {
        $b = Database::connect()->table('product_variants pv')
            ->select('pv.id, pv.sku, pv.base_price, p.title')
            ->join('products p', 'p.id = pv.product_id', 'left')
            ->where('pv.vendor_id', $vendorId)->where('pv.deleted_at', null)->where('p.deleted_at', null);
        if ($excludeProductId > 0) {
            $b->where('pv.product_id !=', $excludeProductId);
        }

        return $b->orderBy('p.title')->limit(500)->get()->getResultArray();
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
