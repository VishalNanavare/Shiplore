<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

/** Vendor\SettlementController — the vendor's own settlements + commission ledger. */
final class SettlementController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        return $this->render('vendor/settlements/index', 'settlements', 'Settlements', [
            'settlements' => service('vendorSettlementRepository')->list($this->vendorId()),
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        $repo       = service('vendorSettlementRepository');
        $settlement = $repo->findById($id, $this->vendorId());
        if ($settlement === null) {
            return redirect()->to('vendor/settlements')->with('error', 'Settlement not found.');
        }

        return $this->render('vendor/settlements/show', 'settlements', 'Settlement', [
            'settlement' => $settlement,
            'lines'      => $repo->lines($id),
        ]);
    }

    public function commission()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }

        return $this->render('vendor/commission/index', 'commission', 'Commission', [
            'ledger'    => service('vendorSettlementRepository')->commissionLedger($this->vendorId()),
            'effective' => service('commissionService')->forVendor($this->vendorId()),
        ]);
    }
}
