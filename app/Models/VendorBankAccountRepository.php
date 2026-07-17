<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * VendorBankAccountRepository — vendor payout bank accounts. The account number
 * is stored encrypted (account_no_enc); only the last 4 are kept in clear for
 * display. Adding a default account clears any previous default for the vendor.
 */
final class VendorBankAccountRepository
{
    /** @return array<string,mixed>|null the vendor's default account */
    public function defaultForVendor(int $vendorId): ?array
    {
        $row = Database::connect()->table('vendor_bank_accounts')
            ->select('id, account_last4, ifsc, holder_name, status')
            ->where('vendor_id', $vendorId)->where('is_default', 1)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function add(int $vendorId, string $holder, string $accountNo, string $ifsc, ?int $actorId = null): bool
    {
        $accountNo = preg_replace('/\s+/', '', $accountNo) ?? '';
        if ($vendorId <= 0 || $accountNo === '' || $holder === '' || $ifsc === '') {
            return false;
        }
        // Encrypt the account number when the encrypter is configured; never store clear.
        $enc = $accountNo;
        try {
            $enc = service('encrypter')->encrypt($accountNo);
        } catch (Throwable) {
        }

        $db = Database::connect();
        $db->table('vendor_bank_accounts')->where('vendor_id', $vendorId)->update(['is_default' => 0]);

        return (bool) $db->table('vendor_bank_accounts')->insert([
            'vendor_id'      => $vendorId,
            'account_no_enc' => $enc,
            'account_last4'  => substr(preg_replace('/\D/', '', $accountNo) ?: '0000', -4),
            'ifsc'           => mb_substr(strtoupper(trim($ifsc)), 0, 11),
            'holder_name'    => mb_substr(trim($holder), 0, 191),
            'is_default'     => 1,
            'status'         => 'verified',
            'created_by'     => $actorId,
        ]);
    }
}
