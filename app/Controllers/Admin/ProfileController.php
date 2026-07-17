<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/** Admin\ProfileController — logged-in platform user edits their own name, email and password. */
final class ProfileController extends BaseController
{
    public function index(): string
    {
        $uid  = (int) session()->get('user_id');
        $user = service('adminUserRepository')->find($uid);

        return view('admin/profile/index', [
            'title'     => 'My Profile · ' . service('settingsRepository')->brandName(),
            'pageTitle' => 'My Profile',
            'active'    => '',
            'userName'  => session()->get('user_name') ?: 'User',
            'user'      => $user,
        ]);
    }

    public function save(): RedirectResponse
    {
        $uid   = (int) session()->get('user_id');
        $name  = trim((string) $this->request->getPost('name'));
        $email = trim((string) $this->request->getPost('email'));

        if ($name === '') {
            return redirect()->to('admin/profile')->withInput()->with('error', 'Name is required.');
        }
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return redirect()->to('admin/profile')->withInput()->with('error', 'Enter a valid email address.');
        }

        $ok = service('adminUserRepository')->updateProfile($uid, $name, $email);
        if (! $ok) {
            return redirect()->to('admin/profile')->withInput()->with('error', 'That email is already in use by another account.');
        }

        session()->set('user_name', $name);

        return redirect()->to('admin/profile')->with('status', 'Profile updated successfully.');
    }

    public function savePassword(): RedirectResponse
    {
        $uid     = (int) session()->get('user_id');
        $current = (string) $this->request->getPost('current_password');
        $new     = (string) $this->request->getPost('new_password');
        $confirm = (string) $this->request->getPost('confirm_password');

        $user = service('adminUserRepository')->find($uid);

        if (! password_verify($current, (string) ($user['password_hash'] ?? ''))) {
            return redirect()->to('admin/profile#password')->with('pwd_error', 'Current password is incorrect.');
        }
        if (strlen($new) < 8) {
            return redirect()->to('admin/profile#password')->with('pwd_error', 'New password must be at least 8 characters.');
        }
        if ($new !== $confirm) {
            return redirect()->to('admin/profile#password')->with('pwd_error', 'Passwords do not match.');
        }

        service('adminUserRepository')->updatePassword($uid, password_hash($new, PASSWORD_BCRYPT));

        return redirect()->to('admin/profile')->with('status', 'Password changed successfully.');
    }
}
