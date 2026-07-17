<?php

declare(strict_types=1);

namespace App\Libraries\Reports;

use Throwable;

/**
 * ReportExportService — Phase X5: the async export engine over export_jobs.
 * The worker (sync:work, queue `export`) calls run(); output lands as a
 * private media asset and downloads via /media/{uuid}. toCsv() is pure.
 *
 * Report types: `sales` today; the registry pattern makes inventory / GST /
 * settlement / returns additions one entry each.
 */
final class ReportExportService
{
    /** Execute one queued export job. @return array{ok:bool,reason?:string,rows?:int} */
    public function run(int $exportJobId): array
    {
        $jobs = service('exportJobRepository');
        $job  = $jobs->find($exportJobId);
        if ($job === null) {
            return ['ok' => false, 'reason' => 'job not found'];
        }
        if ($job['status'] === 'completed') {
            return ['ok' => true, 'rows' => (int) $job['row_count']]; // idempotent replay
        }
        $jobs->markProcessing($exportJobId);

        try {
            [$header, $rows] = $this->build((string) $job['type'], (array) $job['params']);
            $csv    = self::toCsv($header, $rows);
            $stored = service('mediaService')->storeBytes($csv, 'csv', 'text/csv', 'export', $exportJobId, $job['requested_by'] !== null ? (int) $job['requested_by'] : null);
            if (! ($stored['ok'] ?? false)) {
                $jobs->markFailed($exportJobId);

                return ['ok' => false, 'reason' => 'storage failed'];
            }
            $jobs->markCompleted($exportJobId, (int) $stored['id'], count($rows));

            return ['ok' => true, 'rows' => count($rows)];
        } catch (Throwable $e) {
            $jobs->markFailed($exportJobId);

            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /** @return array{0:list<string>,1:list<array<int|string,mixed>>} */
    private function build(string $type, array $params): array
    {
        switch ($type) {
            case 'sales':
                $rows = service('reportRepository')->exportRows(
                    (string) ($params['from'] ?? date('Y-m-01')),
                    (string) ($params['to'] ?? date('Y-m-d')),
                );

                return [['order_no', 'sub_order_no', 'vendor', 'taxable', 'cgst', 'sgst', 'igst', 'grand_total', 'status', 'created_at'], $rows];

            default:
                throw new \InvalidArgumentException("Unknown export type '{$type}'.");
        }
    }

    /** Pure: rows → RFC-4180-safe CSV (quotes, commas, newlines escaped). */
    public static function toCsv(array $header, array $rows): string
    {
        $esc = static function (mixed $v): string {
            $s = (string) ($v ?? '');
            if ($s !== '' && (str_contains($s, '"') || str_contains($s, ',') || str_contains($s, "\n") || str_contains($s, "\r"))) {
                return '"' . str_replace('"', '""', $s) . '"';
            }

            return $s;
        };

        $out = implode(',', array_map($esc, $header)) . "\r\n";
        foreach ($rows as $row) {
            $out .= implode(',', array_map($esc, array_values((array) $row))) . "\r\n";
        }

        return $out;
    }
}
