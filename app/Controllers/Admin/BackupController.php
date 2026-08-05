<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/** Admin\BackupController — read-only backup/restore run log. backup.view. */
final class BackupController extends BaseController
{
    public function index()
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), 'backup.view')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return view('admin/backups/index', [
            'title'     => 'Backups · Admin',
            'pageTitle' => 'Backup & Restore Logs',
            'active'    => 'backups',
            'userName'  => session()->get('user_name') ?: 'User',
            'backups'   => service('backupRepository')->list(),
        ]);
    }
}
