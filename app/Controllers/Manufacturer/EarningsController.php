<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

/**
 * Manufacturer\EarningsController — what this manufacturer has been paid for.
 *
 * The vendor panel carries six Finance screens (Settlements, Commission, Commission
 * Holds, Invoices, Credit Notes, GST). The manufacturer panel carried none, so a
 * manufacturer could sell through the platform and never see a rupee of it. This is the
 * first of that group and the one that matters: money in.
 *
 * READ-ONLY on purpose. It reports what the purchase orders already say rather than
 * creating settlement rows, because a settlement is a payout run — a period, a status, a
 * bank reference — and neither the payout cycle nor a B2B commission rate has been
 * decided. Reporting from the orders cannot drift from them; a second ledger could.
 *
 * @see \App\Controllers\Vendor\SettlementController the vendor counterpart
 */
final class EarningsController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.invoice.view')) {
            return $denied;
        }

        $repo    = service('manufacturerEarningsRepository');
        $policy  = service('b2bPolicy');
        $mid     = (int) $this->manufacturerId();
        $summary = $repo->summary($mid);

        // Commission is applied from the configured rate, which defaults to zero. That
        // default is the honest one: "nothing configured" and "we take nothing" produce
        // the same number, so an unconfigured screen shows the manufacturer their gross
        // rather than a figure somebody invented. The view says which is in force.
        $commission = $policy->commissionOn($summary['earned']);

        return $this->render('manufacturer/earnings/index', 'earnings', 'Earnings', [
            'summary'    => $summary,
            'orders'     => $repo->earnedOrders($mid),
            'commission' => $commission,
            'net'        => round($summary['earned'] - $commission, 2),
            'policy'     => $policy,
        ]);
    }
}
