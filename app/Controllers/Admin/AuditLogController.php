<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/** Admin\AuditLogController — read-only audit trail. audit.view. */
final class AuditLogController extends BaseController
{
    public function index()
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'audit.view')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        $action = $this->request->getGet('action');

        return view('admin/audit-logs/index', [
            'title'     => 'Audit Logs · Admin',
            'pageTitle' => 'Audit Logs',
            'active'    => 'audit-logs',
            'userName'  => session()->get('user_name') ?: 'User',
            'logs'      => service('auditLogRepository')->list(is_string($action) ? $action : null),
        ]);
    }
}
