<?php

declare(strict_types=1);

namespace App\Controllers\Rider;

use App\Models\StoreCustomerRepository;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Rider\AuthController — mobile-number OTP sign-in for the rider web panel.
 * Primary path is Firebase Phone Auth (real SMS, same project as staff/customer);
 * a dev OTP fallback (auth_otp) is used when Firebase isn't configured. Only a
 * user with principal_type='rider' linked to an active delivery_boy may sign in.
 */
final class AuthController extends BaseRiderController
{
    public function loginForm()
    {
        if ($this->riderUserId() !== null) {
            return redirect()->to('rider/dashboard');
        }
        try {
            $cfg = service('integrationRepository')->config('firebase');
        } catch (\Throwable) {
            $cfg = [];
        }
        $project = trim((string) ($cfg['project_id'] ?? ''));

        return view('rider/login', [
            'stage' => session()->get('rider_login_phone') ? 'verify' : 'phone',
            'fb'    => [
                'apiKey'            => trim((string) ($cfg['web_api_key'] ?? '')),
                'authDomain'        => $project !== '' ? $project . '.firebaseapp.com' : '',
                'projectId'         => $project,
                'messagingSenderId' => trim((string) ($cfg['sender_id'] ?? '')),
                'appId'             => trim((string) ($cfg['app_id'] ?? '')),
            ],
        ]);
    }

    /** Dev fallback: issue a code to the phone (shown on screen when no SMS gateway). */
    public function sendCode(): RedirectResponse
    {
        $phone = StoreCustomerRepository::normalizePhone((string) $this->request->getPost('phone'));
        if ($phone === null) {
            return redirect()->to('rider/login')->with('error', 'Enter a valid 10-digit mobile number.');
        }
        if (service('riderRepository')->findByPhone($phone) === null) {
            return redirect()->to('rider/login')->with('error', 'No rider account for that number. Ask your vendor to register you.');
        }
        $code = service('otpService')->issue($phone, 'login');
        $sms  = service('smsSender')->send($phone, 'Your rider sign-in code is ' . $code . '. Valid 10 minutes.');
        session()->set('rider_login_phone', $phone);

        // Gate the code on the ENVIRONMENT, not on the gateway's dev flag: SmsSender
        // reports dev=true whenever no gateway is configured, which is production's
        // normal state, so this printed a live rider login OTP on screen to anyone.
        if (! ($sms['ok'] ?? false)) {
            log_message('error', 'Rider login: SMS to ' . $phone . ' undeliverable (provider=' . ($sms['provider'] ?? '?') . ') ' . ($sms['message'] ?? ''));

            if (ENVIRONMENT === 'production') {
                return redirect()->to('rider/login')
                    ->with('error', 'We could not send your code right now. Please try again shortly.');
            }

            return redirect()->to('rider/login')
                ->with('status', 'Code generated (no SMS gateway configured).')
                ->with('dev_codes', 'DEV — OTP: ' . $code);
        }

        return redirect()->to('rider/login')->with('status', 'Code sent to ' . $phone . '.');
    }

    /** Dev fallback verify. */
    public function verify(): RedirectResponse
    {
        $phone = (string) session()->get('rider_login_phone');
        $code  = (string) $this->request->getPost('code');
        if ($phone === '' || ! service('otpService')->verify($phone, $code, 'login')) {
            return redirect()->to('rider/login')->with('error', 'Invalid or expired code.');
        }

        return $this->signIn($phone, 'rider/login');
    }

    /** Firebase path: verify the ID token, then sign in by verified phone (JSON). */
    public function otpLogin()
    {
        $claims = service('firebaseVerifier')->verify((string) $this->request->getPost('id_token'));
        if ($claims === null) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'message' => 'Could not verify the code.', 'csrf' => csrf_hash()]);
        }
        $raw   = trim((string) ($claims['phone_number'] ?? ''));
        $phone = StoreCustomerRepository::normalizePhone($raw) ?? $raw;
        $rider = $phone !== '' ? service('riderRepository')->findByPhone($phone) : null;
        if ($rider === null) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false, 'message' => 'No rider account for ' . $phone . '.', 'csrf' => csrf_hash()]);
        }
        if (($rider['status'] ?? '') !== 'active') {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'message' => 'This rider account is not active.', 'csrf' => csrf_hash()]);
        }
        session()->regenerate();
        session()->set(['rider_id' => (int) $rider['user_id'], 'rider_name' => $rider['name'] ?? 'Rider']);

        return $this->response->setJSON(['ok' => true, 'redirect' => site_url('rider/dashboard'), 'csrf' => csrf_hash()]);
    }

    private function signIn(string $phone, string $back): RedirectResponse
    {
        $rider = service('riderRepository')->findByPhone($phone);
        if ($rider === null) {
            return redirect()->to($back)->with('error', 'Rider account not found.');
        }
        if (($rider['status'] ?? '') !== 'active') {
            return redirect()->to($back)->with('error', 'This rider account is not active.');
        }
        session()->remove('rider_login_phone');
        session()->regenerate();
        session()->set(['rider_id' => (int) $rider['user_id'], 'rider_name' => $rider['name'] ?? 'Rider']);

        return redirect()->to('rider/dashboard')->with('success', 'Signed in.');
    }

    public function logout(): RedirectResponse
    {
        session()->remove(['rider_id', 'rider_name', 'rider_login_phone']);
        // Retire the session ID, not just the identity keys — a captured ID would
        // otherwise stay valid and be re-usable after the next sign-in.
        session()->regenerate(true);

        return redirect()->to('rider/login')->with('success', 'Signed out.');
    }
}
