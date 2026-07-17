<?php

declare(strict_types=1);

namespace App\Libraries\Payment;

use Config\Database;
use Throwable;

/**
 * RefundService — processes a refund (partial or full) the money-correct way:
 * a balanced double-entry into `ledger_entries`, a GST `credit_note` (+ line)
 * reversing the proportional tax, the `refunds` status, and a customer
 * notification. Idempotent: completing an already-completed refund is a no-op.
 *
 * @see docs/architecture/38-PAYMENTS-REFUNDS-SETTLEMENTS.md
 */
final class RefundService
{
    /** Refund / sub-order amount ratio, clamped to [0,1]. Pure. */
    public static function factor(string $refundAmount, string $subOrderGrand): string
    {
        $grand = (float) $subOrderGrand;
        $f     = $grand > 0 ? min(1.0, (float) $refundAmount / $grand) : 0.0;

        return number_format($f, 4, '.', '');
    }

    /**
     * Proportional GST reversal for the credit note. Pure.
     * @param array<string,mixed> $subOrder
     * @return array{taxable:string,cgst:string,sgst:string,igst:string,grand:string}
     */
    public static function creditNoteTax(array $subOrder, float $factor): array
    {
        $f = static fn (string $k) => number_format(round((float) ($subOrder[$k] ?? 0) * $factor, 2), 2, '.', '');

        return [
            'taxable' => $f('taxable_value'),
            'cgst'    => $f('cgst'),
            'sgst'    => $f('sgst'),
            'igst'    => $f('igst'),
            'grand'   => $f('grand_total'),
        ];
    }

    /**
     * Complete a refund: ledger + credit note + notify + mark completed.
     * @return array{ok:bool,reason?:string,credit_note_no?:string}
     */
    public function complete(int $refundId, ?int $actorId = null): array
    {
        $db     = Database::connect();
        $refund = $db->table('refunds')->where('id', $refundId)->get()->getRowArray();
        if ($refund === null) {
            return ['ok' => false, 'reason' => 'refund not found'];
        }
        if ($refund['status'] === 'completed') {
            return ['ok' => true, 'reason' => 'already completed']; // idempotent
        }

        // payment -> order -> first sub-order (for GST reversal basis)
        $row = $db->table('payments p')
            ->select('o.id AS order_id, o.order_no, o.customer_id, so.id AS sub_order_id, so.shop_id, so.taxable_value, so.cgst, so.sgst, so.igst, so.grand_total')
            ->join('orders o', 'o.id = p.order_id', 'left')
            ->join('sub_orders so', 'so.order_id = o.id', 'left')
            ->where('p.id', $refund['payment_id'])->orderBy('so.id', 'ASC')
            ->get()->getRowArray();
        if ($row === null) {
            return ['ok' => false, 'reason' => 'no order/sub-order for payment'];
        }

        $factor = (float) self::factor((string) $refund['amount'], (string) $row['grand_total']);
        $cn     = self::creditNoteTax($row, $factor);
        $tax    = (float) $cn['cgst'] + (float) $cn['sgst'] + (float) $cn['igst'];

        $db->transBegin();
        try {
            // 1) Double-entry: debit Sales (taxable) + GST Payable (tax) ; credit destination (grand)
            $txn  = $this->uuid();
            $dest = stripos((string) $refund['destination'], 'wallet') !== false ? 'CUST_WALLET' : 'CASH';
            $this->entry($db, $txn, 'SALES', 'debit', $cn['taxable'], 'refund', $refundId, 'Refund reversal — ' . $row['order_no']);
            if ($tax > 0) {
                $this->entry($db, $txn, 'GST_PAYABLE', 'debit', number_format($tax, 2, '.', ''), 'refund', $refundId, 'GST reversal — ' . $row['order_no']);
            }
            $this->entry($db, $txn, $dest, 'credit', $refund['amount'], 'refund', $refundId, 'Refund paid — ' . $row['order_no']);

            // 2) GST credit note (+ summary line) — only against a return (GST rule)
            $cnNo = null;
            if ($refund['return_id'] !== null) {
                // X2b: gapless CN numbering from the per-shop/FY series (inside this tx);
                // legacy shape stays the fallback so a series fault can't block a refund.
                try {
                    $cnNo = service('invoiceSeriesRepository')->next(
                        $row['shop_id'] !== null ? (int) $row['shop_id'] : null,
                        'credit_note',
                    );
                } catch (Throwable) {
                    $cnNo = 'CN/' . date('Y') . '/' . str_pad((string) $refundId, 5, '0', STR_PAD_LEFT);
                }
                $db->table('credit_notes')->insert([
                    'uuid' => $this->uuid(), 'return_id' => $refund['return_id'], 'sub_order_id' => $row['sub_order_id'],
                    'credit_note_no' => $cnNo, 'cn_date' => date('Y-m-d'),
                    'taxable_total' => $cn['taxable'], 'cgst_total' => $cn['cgst'], 'sgst_total' => $cn['sgst'],
                    'igst_total' => $cn['igst'], 'cess_total' => '0.00', 'grand_total' => $cn['grand'], 'status' => 'generated',
                ]);
                $cnId = (int) $db->insertID();
                $db->table('credit_note_lines')->insert([
                    'credit_note_id' => $cnId, 'description' => 'Refund for ' . $row['order_no'],
                    'qty' => '1.000', 'taxable_value' => $cn['taxable'], 'tax_rate' => '0.00',
                    'cgst' => $cn['cgst'], 'sgst' => $cn['sgst'], 'igst' => $cn['igst'], 'cess' => '0.00', 'line_total' => $cn['grand'],
                ]);
            }

            // 3) mark refund completed
            $db->table('refunds')->where('id', $refundId)->update(['status' => 'completed', 'updated_by' => $actorId]);

            $db->transComplete();
            if (! $db->transStatus()) {
                return ['ok' => false, 'reason' => 'transaction failed'];
            }
        } catch (Throwable) {
            $db->transRollback();

            return ['ok' => false, 'reason' => 'processing error'];
        }

        // 4) X2a — returned goods never earn platform commission. Routes by
        //    ledger state (on_hold→cancelled, accrued→reversed, settled→clawback).
        //    Best-effort: a hold failure must never block the customer's refund.
        try {
            $itemIds = null;
            if ($refund['return_id'] !== null) {
                $ids = array_column(
                    $db->table('return_items')->select('order_item_id')
                        ->where('return_id', $refund['return_id'])
                        ->where('order_item_id IS NOT NULL', null, false)
                        ->get()->getResultArray(),
                    'order_item_id',
                );
                $itemIds = $ids === [] ? null : array_map('intval', $ids);
            }
            service('commissionHoldService')->cancelForReturn((int) $row['sub_order_id'], $itemIds, 'refund #' . $refundId);
        } catch (Throwable) {
        }

        // 5) notify the customer (best-effort)
        $this->notifyCustomer((int) $row['customer_id'], (string) $row['order_no']);

        return ['ok' => true, 'credit_note_no' => $cnNo];
    }

    private function entry($db, string $txn, string $accountCode, string $direction, string $amount, string $refType, int $refId, string $memo): void
    {
        $acct = $db->table('ledger_accounts')->select('id')->where('code', $accountCode)->get()->getRowArray();
        if ($acct === null) {
            return;
        }
        $db->table('ledger_entries')->insert([
            'uuid' => $this->uuid(), 'txn_group_uuid' => $txn, 'ledger_account_id' => $acct['id'],
            'direction' => $direction, 'amount' => $amount, 'ref_type' => $refType, 'ref_id' => $refId,
            'memo' => mb_substr($memo, 0, 191), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function notifyCustomer(int $customerId, string $orderNo): void
    {
        try {
            $u = Database::connect()->table('customers')->select('user_id')->where('id', $customerId)->get()->getRowArray();
            if ($u !== null) {
                service('notificationService')->notify((int) $u['user_id'], 'refund_update', ['order_no' => $orderNo, 'status' => 'completed']);
            }
        } catch (Throwable) {
        }
    }

    private function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
