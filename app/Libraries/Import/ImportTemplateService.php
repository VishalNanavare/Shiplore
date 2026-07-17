<?php

declare(strict_types=1);

namespace App\Libraries\Import;

use Box\Spout\Writer\Common\Creator\WriterEntityFactory;
use Throwable;

/**
 * ImportTemplateService — the single source of truth for the bulk-import column
 * sets and the generator for the downloadable **Excel (.xlsx)** templates.
 *
 * The product template now mirrors the rich product model the panel actually
 * processes (brand, HSN, type, GST type, manufacturer, origin, short/long
 * description …) rather than the old 10-column CSV stub. Each template is a real
 * .xlsx with two sheets: the data sheet (header + a worked example row) and an
 * "Instructions" sheet documenting every column, whether it is required, and the
 * reference codes to use (tax classes, units, category/vendor/brand slugs).
 *
 * Column metadata is pure + unit-tested; the XLSX bytes are produced with the
 * already-bundled box/spout writer (the same library the importer reads with).
 *
 * @see docs/architecture/47-BULK-IMPORT.md
 */
final class ImportTemplateService
{
    /**
     * Ordered column spec per import type: key => [required, description, example].
     * The `key` is the header text written to the file and matched on upload.
     */
    public const COLUMNS = [
        'product' => [
            'vendor'            => [true,  'Vendor slug, display name, or numeric id', 'fresh-foods'],
            'category'          => [true,  "Category slug (must be allowed for the vendor's business type)", 'mens-footwear'],
            'brand'             => [false, 'Brand slug or name (blank = none)', 'nike'],
            'hsn'               => [false, 'HSN/SAC code', '6403'],
            'title'             => [true,  'Product title (max 191 chars)', 'Running Shoe X100'],
            'sku'               => [true,  'Unique stock-keeping unit', 'RS-X100-BLK-9'],
            'barcode'           => [false, 'EAN-13 / UPC / internal code', '8901234567890'],
            'product_type'      => [false, 'simple | variant | bundle | combo | digital | service | subscription | giftcard | downloadable | rental', 'simple'],
            'product_condition' => [false, 'new | used | refurbished', 'new'],
            'gst_type'          => [false, 'inclusive | exclusive', 'inclusive'],
            'tax'               => [true,  'Tax-class code', 'GST18'],
            'unit'              => [true,  'Unit code', 'PCS'],
            'mrp'               => [false, 'Maximum retail price (number ≥ 0)', '1999'],
            'base_price'        => [false, 'Selling price (number ≥ 0)', '1499'],
            'manufacturer'      => [false, 'Manufacturer name', 'Acme Corp'],
            'country_of_origin' => [false, 'Country of origin', 'India'],
            'short_description' => [false, 'One-line summary (max 500 chars)', 'Lightweight everyday running shoe'],
            'description'       => [false, 'Full description', 'Breathable mesh upper with cushioned sole…'],
        ],
        'price' => [
            'sku'        => [true,  'SKU of an existing variant', 'RS-X100-BLK-9'],
            'mrp'        => [false, 'New MRP (blank = leave unchanged)', '2099'],
            'base_price' => [false, 'New selling price (blank = leave unchanged)', '1599'],
        ],
        'stock' => [
            'sku'     => [true, 'SKU of an existing variant', 'RS-X100-BLK-9'],
            'on_hand' => [true, 'On-hand quantity to set', '120'],
        ],
        'customer' => [
            'name'  => [false, 'Customer name', 'Asha Verma'],
            'email' => [true,  'Valid email (used as the login)', 'asha@example.com'],
            'phone' => [false, 'Mobile number', '9812345678'],
        ],
    ];

    /** @return list<string> the header columns for a type (defaults to product). */
    public static function columns(string $type): array
    {
        return array_keys(self::COLUMNS[$type] ?? self::COLUMNS['product']);
    }

    /** @return list<array{0:bool,1:string,2:string}> the spec rows for a type. */
    public static function spec(string $type): array
    {
        return array_values(self::COLUMNS[$type] ?? self::COLUMNS['product']);
    }

    /**
     * Build a real .xlsx template and return its bytes.
     *
     * @param array<string,list<string>> $reference reference code lists keyed by
     *        column (tax, unit, category, vendor, brand) for the Instructions sheet.
     */
    public function xlsx(string $type, array $reference = []): string
    {
        $type    = isset(self::COLUMNS[$type]) ? $type : 'product';
        $columns = self::columns($type);
        $spec    = self::COLUMNS[$type];

        $path   = tempnam(sys_get_temp_dir(), 'imptpl');
        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($path);

        // --- Sheet 1: the data sheet (header + one worked example row) ---
        $writer->getCurrentSheet()->setName(ucfirst($type));
        $writer->addRow(WriterEntityFactory::createRowFromArray($columns));
        $writer->addRow(WriterEntityFactory::createRowFromArray(array_map(static fn (array $m): string => (string) $m[2], array_values($spec))));

        // --- Sheet 2: Instructions ---
        $writer->addNewSheetAndMakeItCurrent();
        $writer->getCurrentSheet()->setName('Instructions');
        $writer->addRow(WriterEntityFactory::createRowFromArray(['Column', 'Required', 'Description', 'Example']));
        foreach ($spec as $key => $meta) {
            $writer->addRow(WriterEntityFactory::createRowFromArray([$key, $meta[0] ? 'YES' : 'optional', $meta[1], (string) $meta[2]]));
        }
        $writer->addRow(WriterEntityFactory::createRowFromArray([]));
        $writer->addRow(WriterEntityFactory::createRowFromArray(['Reference codes — use these exact values']));
        foreach (['tax' => 'Tax-class codes', 'unit' => 'Unit codes', 'category' => 'Category slugs', 'vendor' => 'Vendor slugs', 'brand' => 'Brand slugs'] as $key => $label) {
            $vals = $reference[$key] ?? [];
            if ($vals !== []) {
                $writer->addRow(WriterEntityFactory::createRowFromArray([$label, implode(', ', $vals)]));
            }
        }

        $writer->close();

        try {
            $bytes = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }

        return $bytes;
    }
}
