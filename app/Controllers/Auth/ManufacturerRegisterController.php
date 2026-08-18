<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Libraries\Geo\IndiaStates;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * ManufacturerRegisterController — manufacturer self-registration.
 *
 * Mirrors Auth\RegisterController (Firebase phone OTP + emailed code + GST-API
 * verification, with nothing consumed until every fallible step has passed), with two
 * deliberate differences:
 *
 *   1. NO DELIVERY RANGE. The vendor flow collects `delivery_enabled` +
 *      `delivery_radius` and writes shops.delivery_radius_km. None of that exists here:
 *      the form uses partials/_factory_location.php (which omits both inputs) and the
 *      first location is an `mshops` row, a table with no delivery columns at all.
 *
 *   2. The account is created with principal_type='manufacturer' and
 *      vendors.party_type='manufacturer', so it lands in the manufacturer panel.
 *
 * The in-progress registration lives under its own session key. The session cookie is
 * domain-wide (.<domain>), so reusing the vendor flow's 'reg' key would let a half
 * finished vendor registration and a half-finished manufacturer registration overwrite
 * each other in the same browser.
 *
 * Auth\RegisterController is NOT modified — the vendor flow is untouched.
 *
 * @see App\Controllers\Auth\RegisterController — the vendor counterpart
 */
final class ManufacturerRegisterController extends BaseController
{
    /** Own auth_otp purpose, so a manufacturer code cannot invalidate a vendor's. */
    private const OTP_PURPOSE = 'register_email';

    /** Own session key — see the class docblock. */
    private const SESSION_KEY = 'mreg';

    private const RESEND_COOLDOWN  = 60;
    private const RESEND_CAP       = 5;
    private const RESEND_CAP_HOURS = 3600;

    private const MOBILE_OTP_COOLDOWN = 60;
    private const MOBILE_OTP_CAP      = 5;

    /**
     * Note what is absent: no 'delivery_radius'. `shop_name` is `unit_name` — a
     * manufacturer registers a production unit, not a retail shop.
     */
    private const FIELDS = [
        'mobile', 'email', 'password', 'gstin', 'business_type_id', 'legal_name', 'display_name',
        'unit_name', 'address', 'area', 'city', 'state_code', 'pincode', 'latitude', 'longitude',
    ];

    /** Only the address line 2 equivalent is optional here. */
    private const OPTIONAL = ['area'];

    public function show(): string
    {
        $reg = session()->get(self::SESSION_KEY);

        // The page must always render — never let an integration lookup break it.
        try {
            $mapsKey = trim((string) (service('integrationRepository')->config('google_maps')['browser_key'] ?? ''));
        } catch (\Throwable) {
            $mapsKey = '';
        }
        try {
            $fbCfg = service('integrationRepository')->config('firebase');
        } catch (\Throwable) {
            $fbCfg = [];
        }
        $project = trim((string) ($fbCfg['project_id'] ?? ''));

        return view('auth/manufacturer_register', [
            'title'           => 'Register as Manufacturer',
            'stage'           => $reg ? 'verify' : 'details',
            'reg'             => $reg,
            'businessTypes'   => service('businessTypeRepository')->list('active'),
            'states'          => IndiaStates::list(),
            'mapsKey'         => $mapsKey,
            'stateNameToCode' => IndiaStates::nameToCodeMap(),
            // No 'maxRadius' — there is no delivery radius to bound.
            'fb' => [
                'apiKey'            => trim((string) ($fbCfg['web_api_key'] ?? '')),
                'authDomain'        => $project !== '' ? $project . '.firebaseapp.com' : '',
                'projectId'         => $project,
                'messagingSenderId' => trim((string) ($fbCfg['sender_id'] ?? '')),
                'appId'             => trim((string) ($fbCfg['app_id'] ?? '')),
            ],
            'resendWait' => [
                'mobile' => $reg ? $this->remainingWait('mreg_sms_' . md5((string) $reg['mobile'])) : 0,
                'email'  => $reg ? $this->remainingWait('mreg_email_' . md5(strtolower((string) $reg['email']))) : 0,
            ],
        ]);
    }

    public function sendCodes(): RedirectResponse
    {
        $data = [];
        foreach (self::FIELDS as $f) {
            $data[$f] = trim((string) $this->request->getPost($f));
        }
        foreach (self::FIELDS as $f) {
            if ($data[$f] === '' && ! in_array($f, self::OPTIONAL, true)) {
                return redirect()->back()->withInput()->with('error', 'All fields are required.');
            }
        }
        if (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return redirect()->back()->withInput()->with('error', 'Enter a valid email address.');
        }

        // Canonicalise the phone ONCE, the same way the Firebase claim will arrive, or
        // uq_users_phone is bypassed by a differently-formatted duplicate.
        $data['mobile'] = service('storeCustomerRepository')->normalizePhone($data['mobile']);

        if (! preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', strtoupper($data['gstin']))) {
            return redirect()->back()->withInput()->with('error', 'Enter a valid 15-character GSTIN.');
        }

        // Identifiers are unique across BOTH party types — a manufacturer must not be
        // able to claim a mobile/email/GSTIN already used by a vendor.
        $repo = service('manufacturerRegistrationRepository');
        if ($repo->mobileExists($data['mobile'])) {
            return redirect()->back()->withInput()->with('error', 'That mobile number is already registered.');
        }
        if ($repo->emailExists($data['email'])) {
            return redirect()->back()->withInput()->with('error', 'That email address is already registered.');
        }
        if ($repo->gstinExists(strtoupper($data['gstin']))) {
            return redirect()->back()->withInput()->with('error', 'That GSTIN is already registered.');
        }

        if (! $this->cooldownPasses($data['email'])) {
            return redirect()->back()->withInput()->with('error', 'Please wait a moment before requesting another code.');
        }

        $data['mobile_verified'] = false;
        session()->set(self::SESSION_KEY, $data);

        return $this->dispatchEmailCode($data['email'], 'We emailed you a verification code.');
    }

    /**
     * Server-side gate on Firebase SMS sends. Firebase sends from the browser, so the
     * server cannot refuse the send — but the client asks for a ticket first, which caps
     * our SMS spend and keeps pacing off the client. Keyed on the number, not the
     * session, so a fresh cookie buys no extra sends.
     */
    public function mobileOtpTicket(): ResponseInterface
    {
        $reg = session()->get(self::SESSION_KEY);
        if (! is_array($reg)) {
            return $this->response->setStatusCode(400)->setJSON(['ok' => false, 'message' => 'Start registration again.', 'csrf' => csrf_hash()]);
        }

        $throttler = service('throttler');
        $key       = md5((string) $reg['mobile']);
        if (! $throttler->check('mreg_sms_cap_' . $key, self::MOBILE_OTP_CAP, self::RESEND_CAP_HOURS)) {
            return $this->response->setStatusCode(429)->setJSON(['ok' => false, 'message' => 'Too many SMS requests. Try again later.', 'csrf' => csrf_hash()]);
        }
        if (! $throttler->check('mreg_sms_' . $key, 1, self::MOBILE_OTP_COOLDOWN)) {
            return $this->response->setStatusCode(429)->setJSON([
                'ok'      => false,
                'wait'    => $this->remainingWait('mreg_sms_' . $key, self::MOBILE_OTP_COOLDOWN),
                'message' => 'Please wait before requesting another SMS.',
                'csrf'    => csrf_hash(),
            ]);
        }

        return $this->response->setJSON(['ok' => true, 'csrf' => csrf_hash()]);
    }

    public function verifyMobile(): ResponseInterface
    {
        $reg = session()->get(self::SESSION_KEY);
        if (! is_array($reg)) {
            return $this->response->setStatusCode(400)->setJSON(['ok' => false, 'message' => 'Start registration again.', 'csrf' => csrf_hash()]);
        }

        try {
            $claims = service('firebaseVerifier')->verify((string) $this->request->getPost('id_token'));
        } catch (\Throwable) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'message' => 'Could not verify that code.', 'csrf' => csrf_hash()]);
        }

        // Bind the token to the number in session: without this ANY valid Firebase
        // token for ANY number would mark this registration's mobile as verified.
        $claimed = service('storeCustomerRepository')->normalizePhone((string) ($claims['phone_number'] ?? ''));
        if ($claimed === '' || $claimed !== $reg['mobile']) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'message' => 'That code was issued for a different number.', 'csrf' => csrf_hash()]);
        }

        $reg['mobile_verified'] = true;
        session()->set(self::SESSION_KEY, $reg);

        return $this->response->setJSON(['ok' => true, 'csrf' => csrf_hash()]);
    }

    public function resendEmail(): RedirectResponse
    {
        $reg = session()->get(self::SESSION_KEY);
        if (! is_array($reg)) {
            return redirect()->to('manufacturer-register')->with('error', 'Start registration again.');
        }

        // Someone may have claimed the address since the flow started.
        if (service('manufacturerRegistrationRepository')->emailExists($reg['email'])) {
            session()->remove(self::SESSION_KEY);

            return redirect()->to('manufacturer-register')->with('error', 'That email address is already registered.');
        }
        if (! $this->cooldownPasses($reg['email'])) {
            return redirect()->to('manufacturer-register')->with('error', 'Please wait a moment before requesting another code.');
        }

        return $this->dispatchEmailCode($reg['email'], 'We sent a new verification code.', true);
    }

    public function complete(): RedirectResponse
    {
        $reg = session()->get(self::SESSION_KEY);
        if (! is_array($reg)) {
            return redirect()->to('manufacturer-register')->with('error', 'Start registration again.');
        }
        if (($reg['mobile_verified'] ?? false) !== true) {
            return redirect()->to('manufacturer-register')->with('error', 'Verify your mobile number first.');
        }

        $otp       = service('otpService');
        $emailCode = (string) $this->request->getPost('email_code');

        // check() validates WITHOUT consuming. Everything that can still fail runs
        // first; the code is only spent once the manufacturer row actually exists.
        if (! $otp->check($reg['email'], $emailCode, self::OTP_PURPOSE)) {
            return redirect()->to('manufacturer-register')->with('error', 'Invalid or expired email code.');
        }

        $gst = service('gstVerificationService')->verify(strtoupper($reg['gstin']), $reg['legal_name'], $reg['state_code']);
        if (! $gst['ok']) {
            return redirect()->to('manufacturer-register')->with('error', 'GST verification failed: ' . $gst['reason']);
        }

        $reg['gstin']   = strtoupper($reg['gstin']);
        $manufacturerId = service('manufacturerRegistrationRepository')->createManufacturerWithUnit($reg);
        if ($manufacturerId === null) {
            // The pre-checks ignore soft-deleted rows but the unique keys do not, so a
            // soft-deleted account's identifiers pass the gate and collide here.
            return redirect()->to('manufacturer-register')
                ->with('error', 'Could not complete registration — the mobile, email or GSTIN may already be in use. Please contact support.');
        }

        $otp->verify($reg['email'], $emailCode, self::OTP_PURPOSE);
        session()->remove(self::SESSION_KEY);

        return redirect()->to('login')->with('status', 'Registration complete — your manufacturer account is pending review. Please sign in.');
    }

    public function cancel(): RedirectResponse
    {
        session()->remove(self::SESSION_KEY);

        return redirect()->to('manufacturer-register');
    }

    private function dispatchEmailCode(string $email, string $successMessage, bool $reissue = false): RedirectResponse
    {
        $otp  = service('otpService');
        $code = $reissue ? $otp->reissue($email, self::OTP_PURPOSE) : $otp->issue($email, self::OTP_PURPOSE);

        // The code goes in the body only — subjects surface in lock-screen previews.
        $sent = service('mailer')->send(
            $email,
            'Your manufacturer registration code',
            '<p>Your email verification code is <b>' . esc($code) . '</b>. It expires in 10 minutes.</p>',
        );

        if (! $sent) {
            log_message('error', 'ManufacturerRegister: verification email to ' . $email . ' could not be sent.');

            // A reissue voided the previous code BEFORE this send was attempted, so on
            // failure the user holds a code that no longer works. Say so.
            return redirect()->to('manufacturer-register')->with(
                'error',
                $reissue
                    ? 'We could not send the new verification email, and your previous code is no longer valid. Please request another code, or contact support.'
                    : 'We could not send the verification email. Please try again, or contact support.',
            );
        }

        $redirect = redirect()->to('manufacturer-register')->with('status', $successMessage);

        return ENVIRONMENT !== 'production'
            ? $redirect->with('dev_codes', 'DEV — Email code: ' . $code)
            : $redirect;
    }

    private function cooldownPasses(string $email): bool
    {
        $throttler = service('throttler');
        $key       = md5(strtolower($email));

        return $throttler->check('mreg_email_cap_' . $key, self::RESEND_CAP, self::RESEND_CAP_HOURS)
            && $throttler->check('mreg_email_' . $key, 1, self::RESEND_COOLDOWN);
    }

    private function remainingWait(string $key, int $cooldown = self::RESEND_COOLDOWN): int
    {
        $cache  = cache();
        $tokens = $cache->get('throttler_' . $key);
        if ($tokens === null) {
            return 0;
        }

        $last = (int) $cache->get('throttler_' . $key . 'Time');
        $rate = 1 / $cooldown;
        $tokens += $rate * (time() - $last);

        return $tokens >= 1 ? 0 : max(1, (int) round((1 - $tokens) * $cooldown));
    }
}
