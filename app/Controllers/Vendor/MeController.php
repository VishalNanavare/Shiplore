<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\HTTP\RedirectResponse;

/** Vendor\MeController — any vendor-panel user (owner or staff) edits their own personal profile. */
final class MeController extends BaseVendorController
{
    public function index(): string
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $uid  = (int) session()->get('user_id');
        $user = service('adminUserRepository')->find($uid);

        return $this->render('vendor/me/index', '', 'My Profile', ['user' => $user]);
    }

    public function save(): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $uid   = (int) session()->get('user_id');
        $name  = trim((string) $this->request->getPost('name'));
        $email = trim((string) $this->request->getPost('email'));

        if ($name === '') {
            return redirect()->to('vendor/me')->withInput()->with('error', 'Name is required.');
        }
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return redirect()->to('vendor/me')->withInput()->with('error', 'Enter a valid email address.');
        }

        $ok = service('adminUserRepository')->updateProfile($uid, $name, $email);
        if (! $ok) {
            return redirect()->to('vendor/me')->withInput()->with('error', 'That email is already in use by another account.');
        }

        session()->set('user_name', $name);

        return redirect()->to('vendor/me')->with('status', 'Profile updated successfully.');
    }

    public function savePassword(): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        $uid     = (int) session()->get('user_id');
        $current = (string) $this->request->getPost('current_password');
        $new     = (string) $this->request->getPost('new_password');
        $confirm = (string) $this->request->getPost('confirm_password');
        $user    = service('adminUserRepository')->find($uid);

        if (! password_verify($current, (string) ($user['password_hash'] ?? ''))) {
            return redirect()->to('vendor/me#password')->with('pwd_error', 'Current password is incorrect.');
        }
        if (strlen($new) < 8) {
            return redirect()->to('vendor/me#password')->with('pwd_error', 'New password must be at least 8 characters.');
        }
        if ($new !== $confirm) {
            return redirect()->to('vendor/me#password')->with('pwd_error', 'Passwords do not match.');
        }

        service('adminUserRepository')->updatePassword($uid, password_hash($new, PASSWORD_BCRYPT));
        session()->regenerate(true);

        return redirect()->to('vendor/me')->with('status', 'Password changed successfully.');
    }
}
