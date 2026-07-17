<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Libraries\Geo\IndiaStates;
use App\Models\StoreCustomerRepository;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * RegisterController — vendor self-registration. Enforces the full rule set:
 * unique mobile + email + GSTIN, mobile ownership proven via Firebase phone auth,
 * email ownership proven with a code, and live GSTIN verification (state/status must
 * match) before the vendor + first shop are created. Any failure stops registration.
 * POSTs are CSRF-protected.
 *
 * Mobile is verified by Firebase (the browser completes phone auth and posts the ID
 * token to verifyMobile(), which binds the token's phone_number claim to the number
 * in session) — the same mechanism LoginController, Store\AccountController and
 * Rider\AuthController already use. The email code is an auth_otp row under the
 * dedicated purpose 'register_email'.
 *
 * Nothing is consumed until every fallible step has passed, so a wrong code, a GST
 * failure or a rolled-back insert never strands the user with a spent code.
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md (registration requirements)
 */
final class RegisterController extends BaseController
{
    /** auth_otp purpose for the registration email code — its own namespace. */
    private const OTP_PURPOSE = 'register_email';

    /** One code per identifier per minute... */
    private const RESEND_COOLDOWN = 60;

    /** ...and at most this many per hour, so send-codes can't be scripted into an email bomb. */
    private const RESEND_CAP       = 5;
    private const RESEND_CAP_HOURS = 3600;

    /**
     * Firebase sends the mobile SMS from the browser, so the server cannot refuse the
     * send outright. It can refuse to hand out a ticket, and the client asks for one
     * before every send — which caps our SMS spend and keeps the resend pacing on the
     * server where the user cannot edit it. Firebase's own reCAPTCHA + abuse
     * protection is the backstop if a client skips the ticket.
     */
    private const MOBILE_OTP_COOLDOWN = 60;
    private const MOBILE_OTP_CAP      = 5;

    private const FIELDS = [
        'mobile', 'email', 'password', 'gstin', 'business_type_id', 'legal_name', 'display_name',
        'shop_name', 'address', 'area', 'city', 'state_code', 'pincode', 'latitude', 'longitude', 'delivery_radius',
    ];

    /** Fields that may be left blank (delivery_radius only matters when nearby delivery is on). */
    private const OPTIONAL = ['area', 'delivery_radius'];

    public function show(): string
    {
        $reg = session()->get('reg');

        // The register page must always render — never let an integration lookup break it.
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

        return view('auth/register', [
            'title'           => 'Register',
            'stage'           => $reg ? 'verify' : 'details',
            'reg'             => $reg,
            'businessTypes'   => service('businessTypeRepository')->list('active'),
            'states'          => IndiaStates::list(),
            'mapsKey'         => $mapsKey,
            'stateNameToCode' => IndiaStates::nameToCodeMap(),
            'maxRadius'       => service('settingsRepository')->deliveryMaxRadiusKm(),
            'fb'              => [
                'apiKey'            => trim((string) ($fbCfg['web_api_key'] ?? '')),
                'authDomain'        => $project !== '' ? $project . '.firebaseapp.com' : '',
                'projectId'         => $project,
                'messagingSenderId' => trim((string) ($fbCfg['sender_id'] ?? '')),
                'appId'             => trim((string) ($fbCfg['app_id'] ?? '')),
            ],
            // The button countdowns are seeded from the server so a refresh or a second
            // tab shows the true remaining wait rather than restarting at zero.
            'resendWait' => [
                'mobile' => $reg ? $this->remainingWait('reg_sms_' . md5((string) $reg['mobile'])) : 0,
                'email'  => $reg ? $this->remainingWait('reg_email_' . md5(strtolower((string) $reg['email']))) : 0,
            ],
        ]);
    }

    public function sendCodes(): RedirectResponse
    {
        $data = [];
        foreach (self::FIELDS as $f) {
            $data[$f] = trim((string) $this->request->getPost($f));
        }

        // Required fields (area is optional)
        foreach (self::FIELDS as $f) {
            if (! in_array($f, self::OPTIONAL, true) && $data[$f] === '') {
                return redirect()->back()->withInput()->with('error', 'All required fields must be filled.');
            }
        }
        if (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return redirect()->back()->withInput()->with('error', 'Enter a valid email.');
        }

        // Canonicalise the phone ONCE. Firebase returns E.164, and every existence
        // check / stored value must use that same form — otherwise "+91 98765..." and
        // "+9198765..." are different identifiers that both slip past uq_users_phone.
        $mobile = StoreCustomerRepository::normalizePhone($data['mobile']);
        if ($mobile === null) {
            return redirect()->back()->withInput()->with('error', 'Enter a valid 10-digit Indian mobile number.');
        }
        $data['mobile'] = $mobile;

        // Delivery radius only applies when the shop offers nearby delivery.
        if ((string) $this->request->getPost('delivery_enabled') !== '') {
            $maxRadius = service('settingsRepository')->deliveryMaxRadiusKm();
            if ((float) $data['delivery_radius'] <= 0 || (float) $data['delivery_radius'] > $maxRadius) {
                return redirect()->back()->withInput()->with('error', 'Delivery radius must be between 0.5 and ' . rtrim(rtrim(number_format($maxRadius, 2), '0'), '.') . ' km.');
            }
        } else {
            $data['delivery_radius'] = ''; // no nearby-delivery limit
        }
        if (! preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', strtoupper($data['gstin']))) {
            return redirect()->back()->withInput()->with('error', 'Enter a valid 15-character GSTIN.');
        }

        // Uniqueness
        $repo = service('registrationRepository');
        if ($repo->mobileExists($data['mobile'])) {
            return redirect()->back()->withInput()->with('error', 'Mobile number already registered.');
        }
        if ($repo->emailExists($data['email'])) {
            return redirect()->back()->withInput()->with('error', 'Email already registered.');
        }
        if ($repo->gstinExists(strtoupper($data['gstin']))) {
            return redirect()->back()->withInput()->with('error', 'GSTIN already registered.');
        }

        // Rate-limit BEFORE sending. This route carries only `csrf`, and CSRF does not
        // stop a script that scrapes a token and reuses it, so without this the endpoint
        // will email an arbitrary third party as fast as it is called.
        if (! $this->cooldownPasses($data['email'])) {
            return redirect()->back()->withInput()->with('error', $this->cooldownMessage());
        }

        $data['mobile_verified'] = false;
        session()->set('reg', $data);

        // The mobile is proved by Firebase in the browser; only the email gets a code.
        return $this->dispatchEmailCode($data['email'], 'Codes sent — check your email for the verification code, then verify your mobile below.');
    }

    /**
     * Gate every Firebase SMS send. The browser calls this before signInWithPhoneNumber,
     * both for the first send and for each resend, so pacing and the per-number cap live
     * on the server. Keyed on the number, not the session, so a fresh cookie or a second
     * tab cannot buy more SMS.
     */
    public function mobileOtpTicket(): ResponseInterface
    {
        $reg = session()->get('reg');
        if (! is_array($reg)) {
            return $this->response->setStatusCode(400)
                ->setJSON(['ok' => false, 'message' => 'Start registration again.', 'csrf' => csrf_hash()]);
        }
        if (($reg['mobile_verified'] ?? false) === true) {
            return $this->response->setJSON(['ok' => false, 'message' => 'This number is already verified.', 'csrf' => csrf_hash()]);
        }

        $throttler = service('throttler');
        $key       = md5((string) $reg['mobile']);

        if (! $throttler->check('reg_sms_cap_' . $key, self::MOBILE_OTP_CAP, self::RESEND_CAP_HOURS)) {
            return $this->response->setStatusCode(429)
                ->setJSON(['ok' => false, 'message' => 'Too many OTP requests for this number. Please try again later.', 'csrf' => csrf_hash()]);
        }
        if (! $throttler->check('reg_sms_' . $key, 1, self::MOBILE_OTP_COOLDOWN)) {
            return $this->response->setStatusCode(429)
                ->setJSON(['ok' => false, 'wait' => $throttler->getTokenTime(), 'message' => 'Please wait before requesting another OTP.', 'csrf' => csrf_hash()]);
        }

        return $this->response->setJSON(['ok' => true, 'cooldown' => self::MOBILE_OTP_COOLDOWN, 'csrf' => csrf_hash()]);
    }

    /**
     * The browser completed Firebase phone auth; verify the ID token server-side and
     * bind it to the number being registered. Without that binding a valid token for
     * ANY number would satisfy this step. Returns JSON (the view posts via fetch).
     */
    public function verifyMobile(): ResponseInterface
    {
        $reg = session()->get('reg');
        if (! is_array($reg)) {
            return $this->response->setStatusCode(400)
                ->setJSON(['ok' => false, 'message' => 'Start registration again.', 'csrf' => csrf_hash()]);
        }

        $claims = service('firebaseVerifier')->verify((string) $this->request->getPost('id_token'));
        if ($claims === null) {
            return $this->response->setStatusCode(401)
                ->setJSON(['ok' => false, 'message' => 'Could not verify the code. Please try again.', 'csrf' => csrf_hash()]);
        }

        $claimed = StoreCustomerRepository::normalizePhone((string) ($claims['phone_number'] ?? ''));
        if ($claimed === null || $claimed !== $reg['mobile']) {
            return $this->response->setStatusCode(422)
                ->setJSON(['ok' => false, 'message' => 'That code was not sent to the number you are registering.', 'csrf' => csrf_hash()]);
        }

        $reg['mobile_verified'] = true;
        session()->set('reg', $reg);

        return $this->response->setJSON(['ok' => true, 'message' => 'Mobile verified.', 'csrf' => csrf_hash()]);
    }

    /**
     * Re-send the email code. The identifier comes from session, never from POST, so
     * this cannot be repurposed to mail an arbitrary address. Firebase owns mobile
     * resend client-side, so there is no mobile resend route.
     */
    public function resendEmail(): RedirectResponse
    {
        $reg = session()->get('reg');
        if (! is_array($reg)) {
            return redirect()->to('register')->with('error', 'Start registration again.');
        }

        // The address could have been claimed by another registration in the meantime.
        if (service('registrationRepository')->emailExists($reg['email'])) {
            session()->remove('reg');

            return redirect()->to('register')->with('error', 'That email has just been registered. Please start over.');
        }

        if (! $this->cooldownPasses($reg['email'])) {
            return redirect()->to('register')->with('error', $this->cooldownMessage());
        }

        return $this->dispatchEmailCode(
            $reg['email'],
            'A new code was sent. Use the latest code — the previous one no longer works.',
            true,
        );
    }

    public function complete(): RedirectResponse
    {
        $reg = session()->get('reg');
        if (! is_array($reg)) {
            return redirect()->to('register')->with('error', 'Start registration again.');
        }

        if (($reg['mobile_verified'] ?? false) !== true) {
            return redirect()->to('register')->with('error', 'Verify your mobile number first.');
        }

        $otp       = service('otpService');
        $emailCode = (string) $this->request->getPost('email_code');

        // check() validates WITHOUT consuming (wrong guesses still count towards the
        // per-code attempt cap). Everything that can still fail below runs first; the
        // code is only spent once the vendor row actually exists.
        if (! $otp->check($reg['email'], $emailCode, self::OTP_PURPOSE)) {
            return redirect()->to('register')->with('error', 'Invalid or expired email code.');
        }

        // GSTIN verification — registration STOPS on failure / mismatch.
        $gst = service('gstVerificationService')->verify(strtoupper($reg['gstin']), $reg['legal_name'], $reg['state_code']);
        if (! $gst['ok']) {
            return redirect()->to('register')->with('error', 'GST verification failed: ' . $gst['reason']);
        }

        $reg['gstin'] = strtoupper($reg['gstin']);
        $vendorId     = service('registrationRepository')->createVendorWithShop($reg);
        if ($vendorId === null) {
            // The pre-checks above ignore soft-deleted rows, but uq_users_email /
            // uq_users_phone / uq_vendors_gstin do not — so a soft-deleted vendor's
            // identifiers pass the gate and then collide here. Say so, don't shrug.
            return redirect()->to('register')
                ->with('error', 'Could not complete registration — the mobile, email or GSTIN may already be in use. Please contact support.');
        }

        // Everything succeeded: now spend the code.
        $otp->verify($reg['email'], $emailCode, self::OTP_PURPOSE);
        session()->remove('reg');

        return redirect()->to('login')->with('status', 'Registration complete — your vendor account is pending review. Please sign in.');
    }

    public function cancel(): RedirectResponse
    {
        session()->remove('reg');

        return redirect()->to('register');
    }

    /**
     * Issue + email a code. `$reissue` invalidates any live code first, so exactly one
     * code is ever valid — verify() already honours only the newest row, and leaving
     * older rows live would multiply the brute-force budget.
     */
    private function dispatchEmailCode(string $email, string $successMessage, bool $reissue = false): RedirectResponse
    {
        $otp  = service('otpService');
        $code = $reissue
            ? $otp->reissue($email, self::OTP_PURPOSE)
            : $otp->issue($email, self::OTP_PURPOSE);

        // The code goes in the body only — subjects surface in lock-screen previews,
        // mail-list rows and relay logs.
        $sent = service('mailer')->send(
            $email,
            'Your vendor registration code',
            '<p>Your email verification code is <b>' . esc($code) . '</b>. It expires in 10 minutes.</p>',
        );

        if (! $sent) {
            // Mailer returns false both when SMTP is unconfigured (silently) and when the
            // send fails (logged at error). Either way the user must not be left staring
            // at a success message waiting for mail that will never arrive.
            log_message('error', 'Register: verification email to ' . $email . ' could not be sent.');

            return redirect()->to('register')
                ->with('error', 'We could not send the verification email. Please try again, or contact support.');
        }

        $redirect = redirect()->to('register')->with('status', $successMessage);

        // Outside production surface the code so the flow stays testable without a
        // mailbox. Never in production, and never when the mail actually went out.
        return ENVIRONMENT !== 'production'
            ? $redirect->with('dev_codes', 'DEV — Email code: ' . $code)
            : $redirect;
    }

    /**
     * Server-side pacing, keyed on the identifier rather than IP or session so a second
     * tab, a fresh cookie or a rotated IP cannot bypass it.
     */
    private function cooldownPasses(string $email): bool
    {
        $throttler = service('throttler');
        $key       = md5(strtolower($email));

        return $throttler->check('reg_email_cap_' . $key, self::RESEND_CAP, self::RESEND_CAP_HOURS)
            && $throttler->check('reg_email_' . $key, 1, self::RESEND_COOLDOWN);
    }

    private function cooldownMessage(): string
    {
        $wait = service('throttler')->getTokenTime();

        return $wait > 0
            ? 'Please wait ' . $wait . 's before requesting another code.'
            : 'Too many code requests. Please try again later.';
    }

    /**
     * Seconds left on a 1-token/COOLDOWN bucket, WITHOUT spending a token —
     * Throttler::check() consumes one, so it cannot be used to peek. Mirrors the
     * refill maths in CodeIgniter\Throttle\Throttler::check().
     */
    private function remainingWait(string $key, int $cooldown = self::RESEND_COOLDOWN): int
    {
        $cache  = cache();
        $tokens = $cache->get('throttler_' . $key);
        if ($tokens === null) {
            return 0; // bucket never created: nothing spent, nothing to wait for
        }

        $last    = (int) $cache->get('throttler_' . $key . 'Time');
        $rate    = 1 / $cooldown;                      // capacity 1 over the window
        $tokens += $rate * (time() - $last);

        return $tokens >= 1 ? 0 : max(1, (int) round((1 - $tokens) * $cooldown));
    }
}
