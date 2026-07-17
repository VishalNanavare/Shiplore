<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/** Admin\NotificationController — read-only notifications log. notification.send. */
final class NotificationController extends BaseController
{
    public function index()
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'notification.send')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return view('admin/notifications/index', [
            'title'         => 'Notifications · Admin',
            'pageTitle'     => 'Notifications',
            'active'        => 'notifications',
            'userName'      => session()->get('user_name') ?: 'User',
            'notifications' => service('notificationRepository')->list(),
        ]);
    }
}
