<?php

declare(strict_types=1);

namespace App\Libraries\Import;

use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Database;
use Throwable;

/**
 * ImportService — synchronous bulk import (CSV/XLSX via box/spout). Reads rows,
 * validates each per type, inserts valid rows, and records a full audit trail in
 * import_jobs + import_rows. Product rows are category-gated to the vendor's
 * business type (same rule as the product form). Row validation is pure + tested.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md
 */
final class ImportService
{
    public const TYPES = ['product', 'price', 'stock', 'customer'];

    /** Pure: validate one product row against resolved lookups. @return array{ok:bool,errors:list<string>,data:array<string,mixed>} */
    public static function validateProductRow(array $row, array $ctx): array
    {
        $errors = [];
        $g = static fn (string $k): string => strtolower(trim((string) ($row[$k] ?? '')));
        $s = static fn (string $k): string => trim((string) ($row[$k] ?? ''));
        $vendorKey = $g('vendor');
        $catKey    = $g('category');
        $taxKey    = strtoupper(trim((string) ($row['tax'] ?? '')));
        $unitKey   = $g('unit');
        $brandKey  = $g('brand');
        $hsnKey    = strtoupper(trim((string) ($row['hsn'] ?? '')));
        $sku       = trim((string) ($row['sku'] ?? ''));

        foreach (['vendor', 'category', 'title', 'sku', 'tax', 'unit'] as $req) {
            if (trim((string) ($row[$req] ?? '')) === '') {
                $errors[] = "Missing {$req}";
            }
        }

        $vendor = $ctx['vendors'][$vendorKey] ?? null;
        if ($vendor === null) {
            $errors[] = "Unknown vendor '{$vendorKey}'";
        }
        $catId = $ctx['cats'][$catKey] ?? null;
        if ($catId === null) {
            $errors[] = "Unknown category '{$catKey}'";
        } elseif ($vendor !== null && ! in_array((int) $catId, array_map('intval', $vendor['allowed_cats'] ?? []), true)) {
            $errors[] = "Category '{$catKey}' not allowed for vendor's business type";
        }
        $taxId  = $ctx['tax'][$taxKey] ?? null;
        $unitId = $ctx['units'][$unitKey] ?? null;
        if ($taxId === null) { $errors[] = "Unknown tax class '{$taxKey}'"; }
        if ($unitId === null) { $errors[] = "Unknown unit '{$unitKey}'"; }
        // brand + HSN are optional, but a value that is supplied must resolve
        $brandId = $brandKey !== '' ? ($ctx['brands'][$brandKey] ?? null) : null;
        if ($brandKey !== '' && $brandId === null) { $errors[] = "Unknown brand '{$brandKey}'"; }
        $hsnId = $hsnKey !== '' ? ($ctx['hsn'][$hsnKey] ?? null) : null;
        if ($hsnKey !== '' && $hsnId === null) { $errors[] = "Unknown HSN/SAC code '{$hsnKey}'"; }
        if ($sku !== '' && isset($ctx['skus'][strtolower($sku)])) { $errors[] = "Duplicate SKU '{$sku}'"; }
        foreach (['mrp', 'base_price'] as $f) {
            $v = (string) ($row[$f] ?? '');
            if ($v !== '' && (! is_numeric($v) || (float) $v < 0)) {
                $errors[] = ucfirst($f) . ' must be a non-negative number';
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => []];
        }

        // The rich pass-through fields are consumed by createWithVariant()'s
        // headerColumns()/saveContent() — invalid enums are sanitised there.
        return ['ok' => true, 'errors' => [], 'data' => [
            'vendor_id' => (int) $vendor['id'], 'category_id' => (int) $catId, 'tax_class_id' => (int) $taxId,
            'unit_id' => (int) $unitId, 'title' => trim((string) $row['title']), 'sku' => $sku,
            'barcode' => $s('barcode') ?: null,
            'brand_id' => $brandId !== null ? (int) $brandId : null,
            'hsn_sac_id' => $hsnId !== null ? (int) $hsnId : null,
            'mrp' => (string) ($row['mrp'] ?? '0'), 'base_price' => (string) ($row['base_price'] ?? '0'),
            'product_type' => $s('product_type'), 'product_condition' => $s('product_condition'),
            'gst_type' => $s('gst_type'), 'manufacturer' => $s('manufacturer'),
            'country_of_origin' => $s('country_of_origin'),
            'short_description' => $s('short_description'), 'description' => $s('description'),
        ]];
    }

    /** @return array{ok:bool,reason?:string,job_id?:int,total?:int,imported?:int,errors?:int} */
    public function run(UploadedFile $file, string $type, ?int $actorId): array
    {
        if (! in_array($type, self::TYPES, true)) {
            return ['ok' => false, 'reason' => 'Invalid import type.'];
        }
        if (! $file->isValid()) {
            return ['ok' => false, 'reason' => 'Invalid file upload.'];
        }
        try {
            $rows = $this->readRows($file);
        } catch (Throwable) {
            return ['ok' => false, 'reason' => 'Could not read the file (use CSV or XLSX).'];
        }
        if ($rows === []) {
            return ['ok' => false, 'reason' => 'The file has no data rows.'];
        }

        $repo  = service('importJobRepository');
        $jobId = $repo->create($type, $actorId, count($rows));
        $ctx   = $this->context($type);
        $imported = 0; $errors = 0;

        foreach ($rows as $i => $row) {
            $res = $this->validateRow($type, $row, $ctx);
            if ($res['ok'] && $this->insertRow($type, $res['data'], $actorId)) {
                if (! empty($res['data']['sku'])) {
                    $ctx['skus'][strtolower((string) $res['data']['sku'])] = true;
                }
                $repo->addRow($jobId, $i + 1, $row, 'imported', []);
                $imported++;
            } else {
                $repo->addRow($jobId, $i + 1, $row, 'invalid', $res['errors'] ?? ['Insert failed']);
                $errors++;
            }
        }
        $repo->finalize($jobId, $imported, $errors, 'completed');

        return ['ok' => true, 'job_id' => $jobId, 'total' => count($rows), 'imported' => $imported, 'errors' => $errors];
    }

    /** @return list<array<string,string>> header-keyed rows */
    private function readRows(UploadedFile $file): array
    {
        $reader = strtolower((string) $file->getClientExtension()) === 'csv'
            ? ReaderEntityFactory::createCSVReader()
            : ReaderEntityFactory::createXLSXReader();
        $reader->open($file->getTempName());

        $header = []; $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $n = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $n++;
                $cells = array_map(static fn ($c) => is_string($c) ? trim($c) : $c, $row->toArray());
                if ($n === 1) {
                    $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $cells);
                    continue;
                }
                if (count(array_filter($cells, static fn ($c) => $c !== '' && $c !== null)) === 0) {
                    continue;
                }
                $assoc = [];
                foreach ($header as $idx => $h) {
                    $assoc[$h] = (string) ($cells[$idx] ?? '');
                }
                $rows[] = $assoc;
            }
            break; // first sheet only
        }
        $reader->close();

        return $rows;
    }

    /** @return array<string,mixed> lookups for the type */
    private function context(string $type): array
    {
        $db  = Database::connect();
        $ctx = ['skus' => []];
        if ($type === 'product') {
            $vendors = [];
            foreach ($db->table('vendors')->select('id, slug, display_name, business_type_id')->where('deleted_at', null)->get()->getResultArray() as $v) {
                $allowed = $v['business_type_id'] !== null
                    ? array_column($db->table('business_type_category_map')->select('category_id')->where('business_type_id', $v['business_type_id'])->get()->getResultArray(), 'category_id')
                    : array_column($db->table('categories')->select('id AS category_id')->where('status', 'active')->get()->getResultArray(), 'category_id');
                $entry = ['id' => (int) $v['id'], 'allowed_cats' => $allowed];
                $vendors[strtolower((string) $v['slug'])]         = $entry;
                $vendors[strtolower((string) $v['display_name'])] = $entry;
                $vendors[(string) $v['id']]                       = $entry;
            }
            $ctx['vendors'] = $vendors;
            $ctx['cats']    = $this->map($db->table('categories')->select('id, slug')->where('deleted_at', null)->get()->getResultArray(), 'slug', 'id');
            $ctx['tax']     = $this->map($db->table('tax_classes')->select('id, code')->get()->getResultArray(), 'code', 'id', true);
            $ctx['units']   = $this->map($db->table('units')->select('id, code')->get()->getResultArray(), 'code', 'id');
            // brand: slug OR name → id ; hsn: code → id (both optional on a row)
            $ctx['brands'] = [];
            foreach ($db->table('brands')->select('id, slug, name')->where('status', 'active')->get()->getResultArray() as $b) {
                $ctx['brands'][strtolower((string) $b['slug'])] = (int) $b['id'];
                $ctx['brands'][strtolower((string) $b['name'])] = (int) $b['id'];
            }
            $ctx['hsn'] = $this->map($db->table('hsn_sac_codes')->select('id, code')->where('status', 'active')->get()->getResultArray(), 'code', 'id', true);
        }

        return $ctx;
    }

    /** @return array<string,mixed> */
    private function map(array $rows, string $keyCol, string $valCol, bool $upper = false): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = $upper ? strtoupper((string) $r[$keyCol]) : strtolower((string) $r[$keyCol]);
            $out[$k] = $r[$valCol];
        }

        return $out;
    }

    /** @return array{ok:bool,errors:list<string>,data:array<string,mixed>} */
    private function validateRow(string $type, array $row, array $ctx): array
    {
        if ($type === 'product') {
            return self::validateProductRow($row, $ctx);
        }
        // price/stock/customer: light validation (sku/email presence)
        if ($type === 'customer') {
            $email = trim((string) ($row['email'] ?? ''));

            return filter_var($email, FILTER_VALIDATE_EMAIL)
                ? ['ok' => true, 'errors' => [], 'data' => ['email' => $email, 'name' => trim((string) ($row['name'] ?? 'Customer')), 'phone' => trim((string) ($row['phone'] ?? ''))]]
                : ['ok' => false, 'errors' => ['Valid email required'], 'data' => []];
        }
        $sku = trim((string) ($row['sku'] ?? ''));

        return $sku !== ''
            ? ['ok' => true, 'errors' => [], 'data' => $row + ['sku' => $sku]]
            : ['ok' => false, 'errors' => ['Missing sku'], 'data' => []];
    }

    /** @param array<string,mixed> $data */
    private function insertRow(string $type, array $data, ?int $actorId): bool
    {
        try {
            $db = Database::connect();
            switch ($type) {
                case 'product':
                    $vid = (int) ($data['vendor_id'] ?? 0);
                    if (! array_key_exists('shop_ids', $data) && $vid > 0) {
                        // No per-row shop in the CSV — list it in all the vendor's shops so it shows in POS.
                        $data['shop_ids'] = array_map(static fn ($s) => (int) $s['id'], service('productShopRepository')->shopsForVendor($vid));
                    }
                    return service('adminProductRepository')->createWithVariant($data, $actorId) !== null;
                case 'price':
                    return $db->table('product_variants')->where('sku', $data['sku'])->where('deleted_at', null)
                        ->update(array_filter(['mrp' => $data['mrp'] ?? null, 'base_price' => $data['base_price'] ?? null], static fn ($v) => $v !== null && $v !== ''));
                case 'stock':
                    $v = $db->table('product_variants')->select('id')->where('sku', $data['sku'])->get()->getRowArray();
                    if ($v === null) { return false; }
                    return $db->table('inventory')->where('variant_id', $v['id'])->set('on_hand', (string) (float) ($data['on_hand'] ?? 0))->update();
                case 'customer':
                    $db->table('users')->insert(['uuid' => bin2hex(random_bytes(18)), 'principal_type' => 'customer', 'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?: null, 'status' => 'active']);
                    $uid = (int) $db->insertID();
                    return (bool) $db->table('customers')->insert(['uuid' => bin2hex(random_bytes(18)), 'user_id' => $uid, 'lifetime_value' => 0, 'status' => 'active']);
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }
}
