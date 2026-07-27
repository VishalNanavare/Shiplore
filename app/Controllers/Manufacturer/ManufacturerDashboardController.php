<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

/**
 * Manufacturer\ManufacturerDashboardController — landing page for the manufacturer panel.
 *
 * This is where Auth\LoginController::landingFor() sends a 'manufacturer' principal, and
 * where App\Filters\WebAuthFilter::LANDING redirects one on a principal-type mismatch —
 * so the route must always resolve, or those redirects dead-end.
 */
final class ManufacturerDashboardController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->guard('mfg.dashboard.view')) {
            return $denied;
        }

        $id   = (int) $this->manufacturerId();
        $repo = service('manufacturerDashboardRepository');

        return $this->render('manufacturer/dashboard', 'dashboard', 'Dashboard', [
            'metrics'        => $repo->metrics($id),
            'recentProducts' => $repo->recentProducts($id),
        ]);
    }
}
