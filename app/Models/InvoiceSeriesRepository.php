<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use DateTimeImmutable;

/**
 * InvoiceSeriesRepository — gapless GST document numbering, per shop per
 * Indian financial year (Apr–Mar) per doc type (X2b).
 *
 * IMPORTANT: call next() INSIDE the caller's DB transaction — the row is
 * locked with SELECT … FOR UPDATE, so the sequence advances only when the
 * surrounding document insert commits (gapless by construction).
 *
 * Number shape: {prefix}/{fy}/{00001}; the default prefix embeds the shop
 * ("INV-S3") so the global UNIQUE on invoices.invoice_no can never collide
 * across shops.
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §11 I1
 */
final class InvoiceSeriesRepository
{
    private const DEFAULT_PREFIX = [
        'tax_invoice'    => 'INV',
        'bill_of_supply' => 'BOS',
        'credit_note'    => 'CN',
        'debit_note'     => 'DN',
        'pos_receipt'    => 'POS',
    ];

    public function next(?int $shopId, string $docType, ?DateTimeImmutable $at = null): string
    {
        $at = $at ?? new DateTimeImmutable('now');
        $fy = self::fyLabel($at);
        $db = Database::connect();

        $row = $db->query(
            'SELECT * FROM invoice_series WHERE shop_id <=> ? AND fy = ? AND doc_type = ? FOR UPDATE',
            [$shopId, $fy, $docType],
        )->getRowArray();

        if ($row === null) {
            $db->table('invoice_series')->insert(['shop_id' => $shopId, 'fy' => $fy, 'doc_type' => $docType, 'next_no' => 1]);
            $row = $db->query(
                'SELECT * FROM invoice_series WHERE shop_id <=> ? AND fy = ? AND doc_type = ? FOR UPDATE',
                [$shopId, $fy, $docType],
            )->getRowArray();
        }

        $seq = (int) $row['next_no'];
        $db->table('invoice_series')->where('id', $row['id'])->update(['next_no' => $seq + 1]);

        $prefix = $row['prefix'] !== null && $row['prefix'] !== ''
            ? (string) $row['prefix']
            : self::defaultPrefix($docType, $shopId);

        return self::format($prefix, $fy, $seq);
    }

    /** Pure: Indian financial year label (Apr–Mar), e.g. 2026-04-01 → "2026-27". */
    public static function fyLabel(DateTimeImmutable $at): string
    {
        $y = (int) $at->format('Y');
        $m = (int) $at->format('n');
        $startYear = $m >= 4 ? $y : $y - 1;

        return sprintf('%d-%02d', $startYear, ($startYear + 1) % 100);
    }

    /** Pure: document number shape. */
    public static function format(string $prefix, string $fy, int $seq): string
    {
        return $prefix . '/' . $fy . '/' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    /** Pure: default prefix embeds the shop for cross-shop uniqueness. */
    public static function defaultPrefix(string $docType, ?int $shopId): string
    {
        $abbr = self::DEFAULT_PREFIX[$docType] ?? 'DOC';

        return $shopId !== null ? $abbr . '-S' . $shopId : $abbr;
    }
}
