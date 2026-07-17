<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * CreditRepository — customer credit (udhaar) ledger for the POS. Lists
 * outstanding balances for a vendor and records repayments (reducing the
 * balance and clearing the credit). Tenant-scoped by vendor_id.
 */
final class CreditRepository
{
    /** @return list<array<string,mixed>> outstanding credits (balance > 0) with customer + invoice */
    public function outstanding(int $vendorId, int $limit = 200): array
    {
        return Database::connect()->table('customer_credits cc')
            ->select('cc.id, cc.amount, cc.balance, cc.due_date, cc.status, cc.created_at, u.name AS customer, u.phone, ps.server_invoice_no')
            ->join('customers c', 'c.id = cc.customer_id', 'left')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->join('pos_sales ps', 'ps.id = cc.pos_sale_id', 'left')
            ->where('cc.vendor_id', $vendorId)->where('cc.balance >', 0)
            ->orderBy('cc.due_date', 'ASC')->orderBy('cc.created_at', 'ASC')->limit($limit)
            ->get()->getResultArray();
    }

    /** @return array{outstanding:float,count:int} vendor credit summary */
    public function summary(int $vendorId): array
    {
        $row = Database::connect()->table('customer_credits')
            ->selectSum('balance', 'total')->selectCount('id', 'cnt')
            ->where('vendor_id', $vendorId)->where('balance >', 0)->get()->getRowArray();

        return ['outstanding' => (float) ($row['total'] ?? 0), 'count' => (int) ($row['cnt'] ?? 0)];
    }

    /** Record a repayment against a credit; reduces the balance and updates status. @return array{ok:bool,message:string} */
    public function repay(int $creditId, int $vendorId, float $amount, string $method = 'cash', string $reference = '', ?int $actorId = null): array
    {
        if ($amount <= 0) {
            return ['ok' => false, 'message' => 'Enter a repayment amount.'];
        }
        $db = Database::connect();
        $credit = $db->table('customer_credits')->where('id', $creditId)->where('vendor_id', $vendorId)->get()->getRowArray();
        if ($credit === null) {
            return ['ok' => false, 'message' => 'Credit not found.'];
        }
        $balance = (float) $credit['balance'];
        if ($balance <= 0) {
            return ['ok' => false, 'message' => 'This credit is already cleared.'];
        }
        $pay = min($amount, $balance);
        $newBalance = round($balance - $pay, 2);
        $db->transBegin();
        try {
            $db->table('credit_repayments')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'customer_credit_id' => $creditId, 'amount' => number_format($pay, 2, '.', ''),
                'method' => in_array($method, ['cash', 'card', 'upi', 'wallet'], true) ? $method : 'cash',
                'reference' => mb_substr($reference, 0, 120) ?: null, 'created_by' => $actorId,
            ]);
            $db->table('customer_credits')->where('id', $creditId)->update([
                'balance' => number_format($newBalance, 2, '.', ''),
                'status' => $newBalance <= 0 ? 'cleared' : 'partial', 'updated_by' => $actorId,
            ]);
            $db->transComplete();

            return $db->transStatus()
                ? ['ok' => true, 'message' => 'Repayment of ₹' . number_format($pay, 2) . ' recorded.' . ($newBalance <= 0 ? ' Credit cleared.' : '')]
                : ['ok' => false, 'message' => 'Could not record the repayment.'];
        } catch (\Throwable) {
            $db->transRollback();

            return ['ok' => false, 'message' => 'Server error.'];
        }
    }

    /** Find or create a POS customer by mobile (name + mobile mandatory for udhaar). @return int|null customer id */
    public function findOrCreateCustomer(string $name, string $mobile, string $email = '', ?int $actorId = null): ?int
    {
        $mobile = preg_replace('/[^0-9+]/', '', $mobile);
        if ($name === '' || $mobile === '') {
            return null;
        }
        $db = Database::connect();
        $user = $db->table('users')->select('id')->where('phone', $mobile)->where('deleted_at', null)->get()->getRowArray();
        if ($user !== null) {
            $c = $db->table('customers')->select('id')->where('user_id', (int) $user['id'])->where('deleted_at', null)->get()->getRowArray();
            if ($c !== null) {
                return (int) $c['id'];
            }
            $userId = (int) $user['id'];
        } else {
            $db->table('users')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'principal_type' => 'customer', 'name' => mb_substr($name, 0, 191),
                'phone' => $mobile, 'email' => $email ?: null, 'status' => 'active', 'created_by' => $actorId,
            ]);
            $userId = (int) $db->insertID();
        }
        $db->table('customers')->insert(['uuid' => bin2hex(random_bytes(18)), 'user_id' => $userId, 'status' => 'active', 'created_by' => $actorId]);

        return (int) $db->insertID();
    }
}
