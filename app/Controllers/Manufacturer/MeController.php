<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Manufacturer\MeController — any manufacturer-panel user (owner or unit staff)
 * edits their own login: name, email, password.
 *
 * Deliberately NOT permission-gated. This is the acting user's own account, not
 * tenant data, so requireManufacturer() is the whole gate — exactly as on
 * Vendor\MeController. Gating it on mfg.profile.* would lock a store keeper out of
 * changing their own password, since that permission is about the BUSINESS profile
 * (ProfileController) rather than the person.
 *
 * `adminUserRepository` is party-agnostic: it is keyed on users.id and knows nothing
 * about vendors or manufacturers, so it is reused rather than forked.
 *
 * @see \App\Controllers\Vendor\MeController — the vendor counterpart
 */
final class MeController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }

        return $this->render('manufacturer/me/index', '', 'My Profile', [
            'user' => service('adminUserRepository')->find((int) session()->get('user_id')),
        ]);
    }

    public function save(): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }

        $uid   = (int) session()->get('user_id');
        $name  = trim((string) $this->request->getPost('name'));
        $email = trim((string) $this->request->getPost('email'));

        if ($name === '') {
            return redirect()->to('manufacturer/me')->withInput()->with('error', 'Name is required.');
        }
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return redirect()->to('manufacturer/me')->withInput()->with('error', 'Enter a valid email address.');
        }

        if (! service('adminUserRepository')->updateProfile($uid, $name, $email)) {
            return redirect()->to('manufacturer/me')->withInput()->with('error', 'That email is already in use by another account.');
        }

        session()->set('user_name', $name);

        return redirect()->to('manufacturer/me')->with('status', 'Profile updated successfully.');
    }

    public function savePassword(): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }

        $uid     = (int) session()->get('user_id');
        $current = (string) $this->request->getPost('current_password');
        $new     = (string) $this->request->getPost('new_password');
        $confirm = (string) $this->request->getPost('confirm_password');
        $user    = service('adminUserRepository')->find($uid);

        if (! password_verify($current, (string) ($user['password_hash'] ?? ''))) {
            return redirect()->to('manufacturer/me#password')->with('pwd_error', 'Current password is incorrect.');
        }
        if (strlen($new) < 8) {
            return redirect()->to('manufacturer/me#password')->with('pwd_error', 'New password must be at least 8 characters.');
        }
        if ($new !== $confirm) {
            return redirect()->to('manufacturer/me#password')->with('pwd_error', 'Passwords do not match.');
        }

        service('adminUserRepository')->updatePassword($uid, password_hash($new, PASSWORD_BCRYPT));
        // Re-issue the session id: a password change must not leave an older captured
        // session usable. Same reasoning as the vendor panel.
        session()->regenerate(true);

        return redirect()->to('manufacturer/me')->with('status', 'Password changed successfully.');
    }
}
