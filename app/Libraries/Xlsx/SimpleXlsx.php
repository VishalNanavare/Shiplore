<?php

namespace App\Libraries\Xlsx;

class SimpleXlsx
{
    public static function build(array $headers, array $rows): string
    {
        $sharedStrings = [];
        $ssi = [];
        $addStr = static function (string $s) use (&$sharedStrings, &$ssi): int {
            if (!isset($ssi[$s])) {
                $ssi[$s] = count($sharedStrings);
                $sharedStrings[] = $s;
            }
            return $ssi[$s];
        };

        $sheetRows = '';
        $rowNum = 1;

        $cells = '';
        foreach ($headers as $ci => $h) {
            $col = self::colLetter($ci);
            $idx = $addStr((string) $h);
            $cells .= "<c r=\"{$col}{$rowNum}\" t=\"s\"><v>{$idx}</v></c>";
        }
        $sheetRows .= "<row r=\"{$rowNum}\">{$cells}</row>";
        $rowNum++;

        foreach ($rows as $row) {
            $cells = '';
            $ci = 0;
            foreach (array_values($row) as $val) {
                $col = self::colLetter($ci++);
                if (is_numeric($val) && $val !== '') {
                    $cells .= "<c r=\"{$col}{$rowNum}\"><v>" . htmlspecialchars((string) $val, ENT_XML1) . "</v></c>";
                } else {
                    $idx = $addStr((string) $val);
                    $cells .= "<c r=\"{$col}{$rowNum}\" t=\"s\"><v>{$idx}</v></c>";
                }
            }
            $sheetRows .= "<row r=\"{$rowNum}\">{$cells}</row>";
            $rowNum++;
        }

        $lastCol = count($headers) > 0 ? self::colLetter(count($headers) - 1) : 'A';
        $lastRow = max(1, $rowNum - 1);
        $dim = "A1:{$lastCol}{$lastRow}";

        $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">';
        foreach ($sharedStrings as $s) {
            $ssXml .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
        }
        $ssXml .= '</sst>';

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="' . $dim . '"/><sheetData>' . $sheetRows . '</sheetData></worksheet>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '</styleSheet>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->addFromString('xl/sharedStrings.xml', $ssXml);
        $zip->addFromString('xl/styles.xml', $styles);
        $zip->close();
        $bytes = file_get_contents($tmp);
        unlink($tmp);
        return $bytes;
    }

    private static function colLetter(int $idx): string
    {
        $idx++;
        $s = '';
        while ($idx > 0) {
            $s   = chr(65 + ($idx - 1) % 26) . $s;
            $idx = (int) (($idx - 1) / 26);
        }
        return $s;
    }
}
