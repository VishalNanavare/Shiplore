<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

/**
 * Manufacturer\SettlementController — payout runs and what is owed.
 *
 * READ-ONLY. Building a run is a scheduled/admin action, not something a manufacturer
 * triggers for itself: a seller who can create their own payout run can decide when they
 * are owed money, which is a conflict the vendor panel does not have either.
 *
 * @see \App\Controllers\Vendor\SettlementController the vendor counterpart
 */
final class SettlementController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.settlement.view')) {
            return $denied;
        }

        $svc = service('manufacturerSettlementService');
        $mid = (int) $this->manufacturerId();
        $all = $svc->list($mid);

        return $this->render('manufacturer/settlements/index', 'settlements', 'Settlements', [
            'settlements' => $all,
            // 'held' is a real settlement status, so holds are the same list filtered
            // rather than a separate concept with its own table.
            'holds'  => array_values(array_filter($all, static fn ($s) => ($s['status'] ?? '') === 'held')),
            'policy' => service('b2bPolicy'),
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.settlement.view')) {
            return $denied;
        }

        $s = service('manufacturerSettlementService')->find($id, (int) $this->manufacturerId());
        if ($s === null) {
            return redirect()->to('manufacturer/settlements')->with('error', 'Settlement not found.');
        }

        return $this->render('manufacturer/settlements/show', 'settlements', 'Settlement', ['s' => $s]);
    }
}
