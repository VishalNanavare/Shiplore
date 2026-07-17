<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Catalog\Barcode;
use Config\Database;

/**
 * ProductBarcodeRepository — many barcodes per product/variant. Validates the
 * symbology + check digit (via Barcode), enforces global value-uniqueness and a
 * single primary per scope (a variant, or the product itself for simple
 * products), and resolves a scanned value to a sellable variant for POS.
 *
 * @see App\Libraries\Catalog\Barcode
 */
final class ProductBarcodeRepository
{
    /** @return list<array<string,mixed>> all barcodes for a product (incl. per-variant) */
    public function forProduct(int $productId): array
    {
        return Database::connect()->table('product_barcodes pb')
            ->select('pb.id, pb.variant_id, pb.barcode_type, pb.barcode_value, pb.pack_level, pb.is_primary, pb.status, pv.sku')
            ->join('product_variants pv', 'pv.id = pb.variant_id', 'left')
            ->where('pb.product_id', $productId)->where('pb.deleted_at', null)
            ->orderBy('pb.is_primary', 'DESC')->orderBy('pb.id')->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    public function forVariant(int $variantId): array
    {
        return Database::connect()->table('product_barcodes')
            ->where('variant_id', $variantId)->where('deleted_at', null)
            ->orderBy('is_primary', 'DESC')->orderBy('id')->get()->getResultArray();
    }

    /**
     * Add a barcode. variantId NULL => product-level (simple products).
     * @return array{ok:bool, message:string, id:int|null}
     */
    public function add(int $productId, ?int $variantId, string $type, string $value, string $packLevel = 'unit', bool $isPrimary = false, ?int $actorId = null): array
    {
        $v = Barcode::validate($type, $value);
        if (! $v['ok']) {
            return ['ok' => false, 'message' => $v['message'], 'id' => null];
        }
        $db = Database::connect();
        // The same barcode may map to several items (different pack/price) — the POS
        // shows a picker. We only block an exact duplicate on the SAME variant scope.
        $dupe = $db->table('product_barcodes')->where('barcode_value', $v['value'])->where('deleted_at', null);
        $variantId !== null ? $dupe->where('variant_id', $variantId) : $dupe->where('variant_id', null)->where('product_id', $productId);
        if ($dupe->countAllResults() > 0) {
            return ['ok' => false, 'message' => 'That barcode is already on this item.', 'id' => null];
        }
        if ($isPrimary) {
            $this->clearPrimary($productId, $variantId, $db);
        }
        $db->table('product_barcodes')->insert([
            'uuid' => $this->uuid(), 'product_id' => $productId, 'variant_id' => $variantId,
            'barcode_type' => in_array($type, Barcode::TYPES, true) ? $type : 'CUSTOM',
            'barcode_value' => $v['value'], 'pack_level' => in_array($packLevel, ['unit', 'retail', 'master'], true) ? $packLevel : 'unit',
            'is_primary' => $isPrimary ? 1 : 0, 'status' => 'active', 'created_by' => $actorId,
        ]);

        return ['ok' => true, 'message' => '', 'id' => (int) $db->insertID()];
    }

    /**
     * Replace all barcodes for a scope (variant, or the product's default variant
     * when $variantId is null) with the submitted list. Hard-deletes then re-adds
     * so re-saving the same values doesn't trip the unique index.
     * @param list<array{type?:string,value?:string,pack_level?:string,is_primary?:bool}> $rows
     * @return array{added:int, errors:list<string>}
     */
    public function replaceForVariant(int $productId, ?int $variantId, array $rows, ?int $actorId = null): array
    {
        $db = Database::connect();
        if ($variantId === null) {
            $def = $db->table('product_variants')->select('id')->where('product_id', $productId)->where('is_default', 1)->where('deleted_at', null)->get()->getRowArray();
            $variantId = $def ? (int) $def['id'] : null;
        }
        $b = $db->table('product_barcodes')->where('product_id', $productId);
        $variantId !== null ? $b->where('variant_id', $variantId) : $b->where('variant_id', null);
        $b->delete();   // hard delete frees the unique values

        $added = 0;
        $errors = [];
        foreach ($rows as $row) {
            if (trim((string) ($row['value'] ?? '')) === '') {
                continue;
            }
            $res = $this->add($productId, $variantId, (string) ($row['type'] ?? 'CUSTOM'), (string) $row['value'], (string) ($row['pack_level'] ?? 'unit'), ! empty($row['is_primary']), $actorId);
            $res['ok'] ? $added++ : $errors[] = $row['value'] . ': ' . $res['message'];
        }

        return ['added' => $added, 'errors' => $errors];
    }

    /**
     * Parse the product form's barcode arrays and save them to the scope.
     * Expects barcode_value[], barcode_type[], barcode_pack[] and barcode_primary
     * (the index of the primary row).
     * @param array<string,mixed> $post
     */
    public function saveFromForm(int $productId, ?int $variantId, array $post, ?int $actorId = null): array
    {
        $values = (array) ($post['barcode_value'] ?? []);
        $types  = (array) ($post['barcode_type'] ?? []);
        $packs  = (array) ($post['barcode_pack'] ?? []);
        $primary = isset($post['barcode_primary']) && $post['barcode_primary'] !== '' ? (int) $post['barcode_primary'] : 0;

        $rows = [];
        foreach (array_values($values) as $i => $val) {
            $rows[] = ['type' => $types[$i] ?? 'CUSTOM', 'value' => $val, 'pack_level' => $packs[$i] ?? 'unit', 'is_primary' => $i === $primary];
        }

        return $this->replaceForVariant($productId, $variantId, $rows, $actorId);
    }

    public function setPrimary(int $barcodeId, ?int $actorId = null): bool
    {
        $db  = Database::connect();
        $row = $db->table('product_barcodes')->select('product_id, variant_id')->where('id', $barcodeId)->get()->getRowArray();
        if ($row === null) {
            return false;
        }
        $this->clearPrimary((int) $row['product_id'], $row['variant_id'] !== null ? (int) $row['variant_id'] : null, $db);

        return $db->table('product_barcodes')->where('id', $barcodeId)->update(['is_primary' => 1, 'updated_by' => $actorId]);
    }

    public function delete(int $barcodeId): bool
    {
        return Database::connect()->table('product_barcodes')->where('id', $barcodeId)->update(['deleted_at' => date('Y-m-d H:i:s')]);
    }

    /** Find the barcode's owning product (for tenant checks). @return array<string,mixed>|null */
    public function owner(int $barcodeId): ?array
    {
        return Database::connect()->table('product_barcodes pb')
            ->select('pb.id, pb.product_id, p.vendor_id')
            ->join('products p', 'p.id = pb.product_id', 'left')
            ->where('pb.id', $barcodeId)->get()->getRowArray() ?: null;
    }

    /**
     * POS scan: resolve a value to a sellable variant. Product-level barcodes
     * fall through to the product's default variant.
     * @return array<string,mixed>|null
     */
    public function resolve(string $value): ?array
    {
        $db  = Database::connect();
        $row = $db->table('product_barcodes pb')
            ->select('pb.product_id, pb.variant_id, pb.barcode_type, pb.pack_level, p.title, p.vendor_id')
            ->join('products p', 'p.id = pb.product_id', 'left')
            ->where('pb.barcode_value', strtoupper(trim($value)))->where('pb.status', 'active')->where('pb.deleted_at', null)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $row['resolved_variant_id'] = $this->variantOf($row, $db);

        return $row;
    }

    /**
     * Resolve a scanned barcode to ALL matching items (optionally for one vendor).
     * When more than one comes back, the POS shows a conflict picker so the
     * cashier chooses the right pack/price. @return list<array<string,mixed>>
     */
    public function resolveAll(string $value, ?int $vendorId = null, ?int $shopId = null): array
    {
        $db = Database::connect();
        $b  = $db->table('product_barcodes pb')
            ->select('pb.product_id, pb.variant_id, pb.barcode_type, pb.pack_level, p.title, p.vendor_id')
            ->join('products p', 'p.id = pb.product_id', 'left')
            ->where('pb.barcode_value', strtoupper(trim($value)))->where('pb.status', 'active')->where('pb.deleted_at', null);
        if ($vendorId !== null) {
            $b->where('p.vendor_id', $vendorId);
        }
        if ($shopId !== null) {
            // S2: a scan only resolves products listed in the current shop.
            $b->join('product_shops ps', 'ps.product_id = pb.product_id AND ps.shop_id = ' . (int) $shopId . " AND ps.status = 'active'", 'inner');
        }
        $rows = $b->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['resolved_variant_id'] = $this->variantOf($row, $db);
        }

        return $rows;
    }

    /** Product-level barcodes (variant_id null) fall to the product's default variant. */
    private function variantOf(array $row, object $db): ?int
    {
        if ($row['variant_id'] !== null) {
            return (int) $row['variant_id'];
        }
        $def = $db->table('product_variants')->select('id')->where('product_id', (int) $row['product_id'])->where('is_default', 1)->where('deleted_at', null)->get()->getRowArray();

        return $def ? (int) $def['id'] : null;
    }

    /** Set the primary flag to 0 for the given scope (variant or product-level). */
    private function clearPrimary(int $productId, ?int $variantId, object $db): void
    {
        $b = $db->table('product_barcodes')->where('product_id', $productId)->where('deleted_at', null);
        if ($variantId !== null) {
            $b->where('variant_id', $variantId);
        } else {
            $b->where('variant_id', null);
        }
        $b->update(['is_primary' => 0]);
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
