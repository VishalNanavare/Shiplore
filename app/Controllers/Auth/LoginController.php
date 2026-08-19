<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Libraries\AuthException;

/**
 * LoginController — web session login (Phase 5, web). Renders the themed login,
 * enforces brute-force lockout, verifies credentials via the tested
 * WebAuthenticator + UserRepository, starts a regenerated session, and audits
 * every attempt to `login_attempts` (via LoginAttemptRepository). The POST is
 * CSRF-protected (session token, see Config\Security + the `csrf` route filter).
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §3.4,§7
 */
final class LoginController extends BaseController
{
    private const MAX_FAILS  = 5;   // lock after this many failures
    private const WINDOW_MIN = 15;  // ...within this rolling window (minutes)

    public function show()
    {
        if (session()->get('isLoggedIn')) {
            return redirect()->to($this->landingFor((string) session()->get('principal_type')));
        }

        // The login page must always render — never let an integration lookup break it.
        try {
            $cfg = service('integrationRepository')->config('firebase');
        } catch (\Throwable) {
            $cfg = [];
        }
        $project = trim((string) ($cfg['project_id'] ?? ''));

        return view('auth/login', [
            'title' => 'Sign In · Omnichannel Commerce',
            'fb'    => [
                'apiKey'            => trim((string) ($cfg['web_api_key'] ?? '')),
                'authDomain'        => $project !== '' ? $project . '.firebaseapp.com' : '',
                'projectId'         => $project,
                'messagingSenderId' => trim((string) ($cfg['sender_id'] ?? '')),
                'appId'             => trim((string) ($cfg['app_id'] ?? '')),
            ],
        ]);
    }

    /**
     * Staff land in their own panel.
     *
     * Keep in step with App\Filters\WebAuthFilter::LANDING, which redirects here on a
     * principal-type mismatch.
     *
     * The 'admin/dashboard' default is deliberate and must NOT be tightened to 'login'.
     * attempt() does not gate principal_type, so a customer/rider password login reaches
     * this method; returning 'login' would infinite-loop, because show() calls
     * landingFor() again whenever isLoggedIn is set. Those principals landing on the
     * admin dashboard is a pre-existing oddity, already contained by the admin group's
     * webAuth:platform pin and the per-controller permission guards — it is not made
     * worse here. Fixing it belongs with enforcing auth.enforcePrincipalType.
     */
    private function landingFor(?string $principalType): string
    {
        // ABSOLUTE, on the panel's own host — this used to return a bare relative path.
        //
        // Each of these paths belongs to a subdomain-pinned route group, but both
        // redirect()->to() and site_url() resolve a relative path against the CURRENT
        // host, and SiteURIFactory substitutes the real Host whenever it appears in
        // allowedHostnames. So signing in at manufacturer.<domain> handed a platform
        // admin manufacturer.<domain>/admin/dashboard — a path that host never
        // registers. Our own login response emitted the 404 the operator reported.
        //
        // panel_url() builds <sub>.<base-domain> from the current host and validates it
        // against allowedHostnames, falling back to base_url() — the previous behaviour
        // exactly — when it cannot. All three call sites accept an absolute URL
        // unchanged: two pass it to redirect()->to(), one to site_url().
        //
        // The default arm is untouched: routing a null/customer/rider principal to admin
        // is a separate question that belongs with enforcing auth.enforcePrincipalType,
        // and changing it here would be an unrelated behaviour change on a login path.
        [$sub, $path] = match ($principalType) {
            'vendor'       => ['vendor', 'vendor/dashboard'],
            'manufacturer' => ['manufacturer', 'manufacturer/dashboard'],
            default        => ['admin', 'admin/dashboard'],
        };

        return panel_url($sub, $path);
    }

    public function attempt()
    {
        $login    = trim((string) $this->request->getPost('login'));
        $password = (string) $this->request->getPost('password');
        $attempts = service('loginAttemptRepository');

        // --- Brute-force lockout (per identifier, rolling window) ---
        if ($attempts->recentFailureCount($login, self::WINDOW_MIN) >= self::MAX_FAILS) {
            return redirect()->back()->withInput()
                ->with('error', 'Too many failed attempts. Please try again in a few minutes.');
        }

        $authenticator = service('webAuthenticator');
        $users         = service('userRepository');

        try {
            $user = $authenticator->attempt($login, $password, static fn (string $l): ?array => $users->findByLogin($l));
        } catch (AuthException) {
            $attempts->record($login, false, 'invalid_credentials', null, $this->request->getIPAddress(), (string) $this->request->getUserAgent());

            return redirect()->back()->withInput()->with('error', 'Invalid credentials.');
        }

        $attempts->record($login, true, null, (int) $user['id'], $this->request->getIPAddress(), (string) $this->request->getUserAgent());

        session()->regenerate(true);
        session()->set([
            'isLoggedIn'     => true,
            'user_id'        => (int) $user['id'],
            'user_name'      => $user['name'] ?? '',
            'principal_type' => $user['principal_type'] ?? null,
        ]);

        return redirect()->to($this->landingFor($user['principal_type'] ?? null));
    }

    /**
     * Phone-OTP sign-in. The browser completes Firebase Phone Auth and posts the
     * resulting ID token here (AJAX); we verify it server-side, match the phone to
     * a STAFF account, and start the same session the password path does. Returns
     * JSON so the page can navigate. CSRF-protected.
     */
    public function otpLogin()
    {
        $attempts = service('loginAttemptRepository');
        $ip       = $this->request->getIPAddress();
        $ua       = (string) $this->request->getUserAgent();

        $claims = service('firebaseVerifier')->verify((string) $this->request->getPost('id_token'));
        if ($claims === null) {
            $attempts->record('otp', false, 'invalid_token', null, $ip, $ua);

            return $this->response->setStatusCode(401)
                ->setJSON(['ok' => false, 'message' => 'Could not verify the code. Please try again.', 'csrf' => csrf_hash()]);
        }

        $phone = trim((string) ($claims['phone_number'] ?? ''));
        if ($phone === '') {
            return $this->response->setStatusCode(422)
                ->setJSON(['ok' => false, 'message' => 'The verified token has no phone number.', 'csrf' => csrf_hash()]);
        }

        $user = service('userRepository')->findByPhone($phone);
        if ($user === null) {
            $attempts->record($phone, false, 'no_account', null, $ip, $ua);

            return $this->response->setStatusCode(404)
                ->setJSON(['ok' => false, 'message' => 'No staff account is registered with ' . $phone . '.', 'csrf' => csrf_hash()]);
        }
        // Staff principals only — customers and riders have their own OTP entry points.
        if (! in_array($user['principal_type'] ?? '', ['platform', 'vendor', 'manufacturer'], true)) {
            $attempts->record($phone, false, 'not_staff', (int) $user['id'], $ip, $ua);

            return $this->response->setStatusCode(403)
                ->setJSON(['ok' => false, 'message' => "This number isn't a staff/vendor account.", 'csrf' => csrf_hash()]);
        }
        if (($user['status'] ?? '') !== 'active') {
            $attempts->record($phone, false, 'inactive', (int) $user['id'], $ip, $ua);

            return $this->response->setStatusCode(403)
                ->setJSON(['ok' => false, 'message' => 'This account is not active.', 'csrf' => csrf_hash()]);
        }

        $attempts->record($phone, true, null, (int) $user['id'], $ip, $ua);

        session()->regenerate(true);
        session()->set([
            'isLoggedIn'     => true,
            'user_id'        => (int) $user['id'],
            'user_name'      => $user['name'] ?? '',
            'principal_type' => $user['principal_type'] ?? null,
        ]);

        return $this->response->setJSON([
            'ok'       => true,
            // NOT wrapped in site_url(). landingFor() returns an ABSOLUTE panel URL, and
            // site_url() prepends the base to whatever it is given — it does not detect
            // that its argument is already absolute. Wrapping produced
            // https://admin.<domain>/https:/admin.<domain>/admin/dashboard on the live
            // site: a real 404 that shipped because the commit adding it asserted
            // site_url() "accepts an absolute URL unchanged" without checking.
            'redirect' => $this->landingFor($user['principal_type'] ?? null),
            'csrf'     => csrf_hash(),
        ]);
    }

    public function logout()
    {
        session()->destroy();

        return redirect()->to('login')->with('error', 'You have been signed out.');
    }
}
