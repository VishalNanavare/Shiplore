<?php

declare(strict_types=1);

namespace App\Libraries\Catalog;

/**
 * BarcodeLabelService — Phase S3 (closes GAP-064): renders a printable label
 * sheet for product variants. Pure HTML generation (no I/O) so it is unit
 * tested; the controller feeds the output to DocumentPdfService (the X2c PDF
 * pipeline) to stream a PDF.
 *
 * The barcode glyph uses the "Libre Barcode 128" CSS class — when that web
 * font is installed the code renders scannable; otherwise the human-readable
 * number always prints. Each label may be repeated `qty` times.
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §10
 */
final class BarcodeLabelService
{
    /**
     * Expand the requested labels into a flat list of cells (qty copies each).
     *
     * @param  list<array{title?:string,sku?:string,mrp?:string|float,barcode?:string,qty?:int}> $labels
     * @return list<array{title:string,sku:string,mrp:string,barcode:string}>
     */
    public static function expand(array $labels): array
    {
        $cells = [];
        foreach ($labels as $l) {
            $qty = max(1, (int) ($l['qty'] ?? 1));
            $mrp = $l['mrp'] ?? '';
            for ($i = 0; $i < $qty; $i++) {
                $cells[] = [
                    'title'   => (string) ($l['title'] ?? ''),
                    'sku'     => (string) ($l['sku'] ?? ''),
                    'mrp'     => ($mrp !== '' && $mrp !== null) ? number_format((float) $mrp, 2) : '',
                    'barcode' => (string) ($l['barcode'] ?? ''),
                ];
            }
        }

        return $cells;
    }

    /**
     * A printable A4 grid of labels. Self-contained HTML for dompdf.
     *
     * @param list<array{title?:string,sku?:string,mrp?:string|float,barcode?:string,qty?:int}> $labels
     */
    public static function sheetHtml(array $labels, int $columns = 3): string
    {
        $columns = max(1, min(5, $columns));
        $cells   = self::expand($labels);
        $width   = (int) floor(100 / $columns);
        $e       = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $out = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;margin:8px}'
            . 'table{width:100%;border-collapse:collapse}'
            . 'td{width:' . $width . '%;border:1px dashed #bbb;padding:6px;text-align:center;vertical-align:top}'
            . '.t{font-size:9px;font-weight:bold;height:22px;overflow:hidden}'
            . '.s{font-size:8px;color:#444}'
            . '.m{font-size:10px;font-weight:bold}'
            . '.bc{font-family:"Libre Barcode 128",monospace;font-size:28px;line-height:1}'
            . '.bn{font-size:9px;letter-spacing:1px}'
            . '</style></head><body><table><tr>';

        if ($cells === []) {
            return $out . '<td>No labels.</td></tr></table></body></html>';
        }

        foreach ($cells as $i => $c) {
            if ($i > 0 && $i % $columns === 0) {
                $out .= '</tr><tr>';
            }
            $out .= '<td>'
                . '<div class="t">' . $e($c['title']) . '</div>'
                . ($c['sku'] !== '' ? '<div class="s">' . $e($c['sku']) . '</div>' : '')
                . ($c['mrp'] !== '' ? '<div class="m">MRP ' . $e($c['mrp']) . '</div>' : '')
                . ($c['barcode'] !== '' ? '<div class="bc">*' . $e($c['barcode']) . '*</div><div class="bn">' . $e($c['barcode']) . '</div>' : '')
                . '</td>';
        }

        // pad the final row so the grid stays rectangular
        $rem = count($cells) % $columns;
        if ($rem !== 0) {
            $out .= str_repeat('<td></td>', $columns - $rem);
        }

        return $out . '</tr></table></body></html>';
    }
}
