<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * ForgotPasswordController — request + perform a password reset (Phase 5, web).
 * Token hash stored via PasswordResetRepository; raw token e-mailed (dev: the
 * link is flashed so the flow works without SMTP). Reset is single-use, expires
 * in 30 min, and destroys the session on success. POSTs are CSRF-protected.
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §3.3
 */
final class ForgotPasswordController extends BaseController
{
    private const TTL = 1800; // 30 minutes

    public function showForgot(): string
    {
        return view('auth/forgot', ['title' => 'Forgot Password']);
    }

    public function sendReset(): RedirectResponse
    {
        $email = trim((string) $this->request->getPost('email'));
        $user  = $email !== '' ? service('userRepository')->findByEmail($email) : null;

        if ($user !== null) {
            $pair = service('passwordResetService')->generate();
            service('passwordResetRepository')->store($email, $pair['hash'], time() + self::TTL);

            $link = site_url('reset-password') . '?email=' . urlencode($email) . '&token=' . $pair['token'];
            $sent = service('mailer')->send($email, 'Reset your password', $this->resetEmail($link));

            // If SMTP isn't configured (or the send failed) keep the flow usable:
            // surface the link on-screen ONLY outside production (showing it in
            // production would bypass email verification entirely).
            //
            // The failure MUST be logged at error: logger.threshold is 4, so the
            // 'info' this used to use was discarded outright. Combined with the
            // production gate below, a failed reset left no trace on screen, in the
            // log, or in the response — the user was told "a reset link has been
            // sent" while nothing had been sent and nothing was recorded.
            if (! $sent) {
                log_message('error', 'Password reset email to ' . $email . ' could not be sent.');
                // NEVER log $link: it carries the raw, single-use token, and `error` is
                // inside the production threshold, so this line is retained. The branch
                // fires precisely when SMTP is misconfigured — i.e. potentially for
                // every reset. A hash prefix is enough to correlate with the auth_otp row.
                log_message(
                    'error',
                    'Password reset token issued for {email} (hash {ref}) but not delivered.',
                    ['email' => $email, 'ref' => substr($pair['hash'], 0, 12)],
                );
                if (ENVIRONMENT !== 'production') {
                    session()->setFlashdata('reset_link', $link);
                }

                // Tell the user the truth. The uniform message below exists to prevent
                // account enumeration, but it must not also hide a transport outage:
                // this branch is only reachable AFTER the address matched a real user,
                // so saying "we could not send" leaks nothing a successful send wouldn't.
                return redirect()->to('forgot-password')
                    ->with('error', 'We could not send the reset email right now. Please try again shortly, or contact support.');
            }
        }

        // Uniform response (no user enumeration).
        return redirect()->to('forgot-password')
            ->with('status', 'If that email exists, a reset link has been sent.');
    }

    private function resetEmail(string $link): string
    {
        $url = esc($link, 'attr');

        return '<p>We received a request to reset your password.</p>'
            . '<p><a href="' . $url . '">Reset your password</a> — this link expires in 30 minutes.</p>'
            . '<p>If you didn\'t request this, you can safely ignore this email.</p>';
    }

    public function showReset(): string
    {
        return view('auth/reset', [
            'title' => 'Reset Password',
            'email' => (string) $this->request->getGet('email'),
            'token' => (string) $this->request->getGet('token'),
        ]);
    }

    public function doReset(): RedirectResponse
    {
        $email    = trim((string) $this->request->getPost('email'));
        $token    = (string) $this->request->getPost('token');
        $password = (string) $this->request->getPost('password');
        $confirm  = (string) $this->request->getPost('password_confirm');

        if (strlen($password) < 8 || $password !== $confirm) {
            return redirect()->back()->withInput()
                ->with('error', 'Passwords must match and be at least 8 characters.');
        }

        $reset = service('passwordResetRepository');
        $row   = $reset->findLatestReset($email);

        if ($row === null
            || ! service('passwordResetService')->verify($token, $row['code_hash'], strtotime($row['expires_at']), time())) {
            return redirect()->back()->withInput()->with('error', 'Invalid or expired reset link.');
        }

        $user = service('userRepository')->findByEmail($email);
        if ($user === null) {
            return redirect()->to('login')->with('error', 'Invalid reset request.');
        }

        service('userRepository')->updatePassword((int) $user['id'], password_hash($password, PASSWORD_BCRYPT));
        $reset->consume((int) $row['id']);
        session()->destroy();

        return redirect()->to('login')->with('status', 'Password updated. Please sign in.');
    }
}
