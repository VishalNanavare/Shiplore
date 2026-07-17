<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * AdminController — authenticated admin pages (protected by the `webAuth` filter).
 * Phase 6 will expand this into the full admin module set; for now it serves the
 * dashboard landing after login.
 */
final class AdminController extends BaseController
{
    public function dashboard(): string
    {
        $repo = service('dashboardRepository');

        return view('admin/dashboard', [
            'title'        => 'Dashboard · Admin',
            'pageTitle'    => 'Dashboard',
            'active'       => 'dashboard',
            'userName'     => session()->get('user_name') ?: 'User',
            'metrics'      => $repo->metrics(),
            'recentOrders' => $repo->recentOrders(),
        ]);
    }
}
