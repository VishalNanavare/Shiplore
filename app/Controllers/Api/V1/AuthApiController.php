<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\Firebase\FirebaseIdTokenVerifier;

/**
 * AuthApiController — shared mobile auth for all three apps (customer / vendor /
 * delivery). OTP login (SMS/Firebase) is the primary path; password login is
 * also supported. Returns a JWT the app sends as `Authorization: Bearer`.
 *
 * @see docs/architecture/33-MOBILE-API.md
 */
final class AuthApiController extends BaseApiController
{
    private const TTL = 2592000; // 30 days

    /** Brute-force lockout, deliberately identical to Auth\LoginController's so the
     *  two surfaces share one counter and neither can be used to bypass the other. */
    private const MAX_FAILS  = 5;
    private const WINDOW_MIN = 15;

    /**
     * CORS preflight catch-all so OPTIONS requests resolve to a route; the `cors`
     * filter answers the actual preflight (adds the allow-* headers). Returns 204.
     */
    public function preflight()
    {
        return $this->response->setStatusCode(204);
    }

    public function otpRequest()
    {
        $id = trim((string) ($this->input()['identifier'] ?? ''));
        if ($id === '') {
            return $this->failWith('VALIDATION_ERROR', 'identifier (email or phone) is required.');
        }

        // purpose 'verify' is this endpoint's own namespace (paired with otpVerify()).
        // Registration and store email login have their own purposes so a code minted
        // here can neither satisfy nor invalidate theirs.
        $code = service('otpService')->issue($id);
        $sms  = service('smsSender')->send($id, 'Your verification code is ' . $code . '. Valid for 10 minutes.');

        // The plaintext code is echoed back ONLY outside production, so a dev/staging
        // client can display it without a live gateway. It is NEVER echoed in
        // production: an echoed code proves nothing about who controls the number, and
        // OTP_TEST_NUMBERS is committed to the repo. Test numbers stay useful in
        // dev/staging because the whole environment echoes there anyway.
        $meta = [];
        if (ENVIRONMENT !== 'production') {
            $meta['dev_code'] = $code;
        }

        // Never claim `sent` when nothing left the building. Without a configured
        // gateway SmsSender returns ok=false/dev=true and logs nothing, which is how
        // this endpoint used to report success while delivering silence.
        if (! ($sms['ok'] ?? false)) {
            if (ENVIRONMENT !== 'production') {
                return $this->ok(['sent' => false], $meta);
            }

            log_message('error', 'otpRequest: SMS undeliverable (provider=' . ($sms['provider'] ?? '?') . ') ' . ($sms['message'] ?? ''));

            return $this->failWith('DEPENDENCY_UNAVAILABLE', 'Could not send the verification code. Please try again shortly.');
        }

        return $this->ok(['sent' => true], $meta);
    }

    public function otpVerify()
    {
        $in   = $this->input();
        $id   = trim((string) ($in['identifier'] ?? ''));
        $code = (string) ($in['code'] ?? '');

        if ($id === '' || ! service('otpService')->verify($id, $code)) {
            return $this->failWith('UNAUTHENTICATED', 'Invalid or expired code.');
        }

        $user = service('apiAuthRepository')->findByIdentifier($id);
        if ($user === null) {
            // Auto-register new customer by phone (mirrors firebaseVerify path).
            $identity = service('storeCustomerRepository')->findOrCreateByPhone($id, null);
            $user     = $identity !== null ? service('apiAuthRepository')->findById((int) $identity['user_id']) : null;
        }
        if ($user === null || $user['status'] !== 'active') {
            return $this->failWith('UNAUTHENTICATED', 'Account not available.');
        }

        // SECURITY: this is the PUBLIC storefront-app auth endpoint and it had no
        // principal_type gate at all, so an identifier belonging to a platform admin
        // would mint them a 30-day admin JWT. The web equivalent
        // (LoginController::otpLogin) has always restricted itself to platform/vendor;
        // this one restricted nothing. Staff must not be reachable through the
        // consumer app's auth, which is also the only path that auto-provisions.
        if (($user['principal_type'] ?? '') !== 'customer') {
            return $this->failWith('FORBIDDEN', 'This sign-in is for customer accounts only.');
        }

        return $this->ok($this->session($user));
    }

    /**
     * Customer Firebase Phone-Auth login: the app verifies the phone with
     * Firebase and posts the resulting ID token here. We verify it (RS256 +
     * standard claims), then sign-in/sign-up the customer by the verified phone
     * and issue the same JWT session as otpVerify(). Mirrors the vendor path in
     * VendorPosController::firebaseVerify, but auto-provisions a customer on
     * first login (consumer storefront behaviour).
     */
    public function firebaseVerify()
    {
        $idToken = trim((string) ($this->input()['id_token'] ?? ''));
        if ($idToken === '') {
            return $this->failWith('VALIDATION_ERROR', 'id_token is required.');
        }

        $projectId = trim((string) (service('integrationRepository')->config('firebase')['project_id'] ?? ''));
        if ($projectId === '') {
            return $this->failWith('DEPENDENCY_UNAVAILABLE', 'Firebase is not configured for this installation.');
        }

        $claims = (new FirebaseIdTokenVerifier($projectId))->verify($idToken);
        if ($claims === null) {
            return $this->failWith('UNAUTHENTICATED', 'Invalid or expired Firebase token. Please try again.');
        }

        // Firebase Phone Auth puts the verified number (E.164) in phone_number.
        $phone = trim((string) ($claims['phone_number'] ?? ''));
        if ($phone === '') {
            return $this->failWith('UNAUTHENTICATED', 'Token has no phone_number. Enable Phone sign-in in Firebase.');
        }

        // Sign-in or sign-up the customer by verified phone, then load the full
        // row in the shape session() expects (id, principal_type, name, email, phone).
        $identity = service('storeCustomerRepository')->findOrCreateByPhone($phone, null);
        $user     = $identity !== null ? service('apiAuthRepository')->findById((int) $identity['user_id']) : null;
        if ($user === null || $user['status'] !== 'active') {
            return $this->failWith('UNAUTHENTICATED', 'Account not available.');
        }

        // Same gate as otpVerify(): findOrCreateByPhone() resolves an existing users row
        // by number, so a staff or vendor member whose phone also has a customers row
        // would be issued a JWT carrying THEIR principal_type — a consumer-app login
        // silently escalating into staff access. The storefront app only ever needs a
        // customer session.
        if (($user['principal_type'] ?? '') !== 'customer') {
            return $this->failWith('FORBIDDEN', 'This sign-in is for customer accounts only.');
        }

        return $this->ok($this->session($user));
    }

    public function login()
    {
        $in   = $this->input();
        $id   = trim((string) ($in['identifier'] ?? ''));
        $pass = (string) ($in['password'] ?? '');

        // The same rolling-window lockout and audit trail the web login enforces
        // (Auth\LoginController). Without them this endpoint was an un-audited bypass
        // of that protection against the same `users` rows: findByIdentifier() matches
        // email OR phone with no principal predicate, so admin and vendor accounts are
        // reachable here, and a success mints a 30-day JWT. The throttle filter is not
        // a substitute — it keys on IP+path, so it multiplies across proxies while the
        // target account never locks and nothing is recorded anywhere.
        $attempts = service('loginAttemptRepository');
        $ip       = $this->request->getIPAddress();
        $ua       = (string) $this->request->getUserAgent();

        if ($id !== '' && $attempts->recentFailureCount($id, self::WINDOW_MIN) >= self::MAX_FAILS) {
            return $this->failWith('RATE_LIMITED', 'Too many failed attempts. Please try again in a few minutes.');
        }

        $user = $id !== '' ? service('apiAuthRepository')->findByIdentifier($id) : null;

        if ($user === null || empty($user['password_hash']) || ! password_verify($pass, (string) $user['password_hash']) || $user['status'] !== 'active') {
            $attempts->record($id, false, 'invalid_credentials', $user !== null ? (int) $user['id'] : null, $ip, $ua);

            return $this->failWith('UNAUTHENTICATED', 'Invalid credentials.');
        }

        $attempts->record($id, true, null, (int) $user['id'], $ip, $ua);

        return $this->ok($this->session($user));
    }

    /**
     * Re-issue a fresh JWT for the currently authenticated user (sliding session),
     * so a long-lived app never forces a re-login while the account stays active.
     * Requires a still-valid token (jwtAuth filter).
     */
    public function refresh()
    {
        $uid = (int) service('scopeContext')->actorId();
        if ($uid <= 0) {
            return $this->failWith('UNAUTHENTICATED', 'Invalid session.');
        }
        $user = service('apiAuthRepository')->findById($uid);
        if ($user === null || $user['status'] !== 'active') {
            return $this->failWith('UNAUTHENTICATED', 'Account not available.');
        }

        return $this->ok($this->session($user));
    }

    /** @param array<string,mixed> $user */
    private function session(array $user): array
    {
        $secret = \App\Libraries\TokenService::secret();
        $claims = ['sub' => (int) $user['id'], 'typ' => $user['principal_type'], 'name' => $user['name']];
        $pwd    = \App\Libraries\TokenService::pwdClaim($user['password_hash'] ?? null);
        if ($pwd !== null) {
            $claims['pwd'] = $pwd;
        }
        $token = service('tokenService')->issue($claims, self::TTL, $secret, time());

        return [
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::TTL,
            'user'       => [
                'id'             => (int) $user['id'],
                'name'           => $user['name'],
                'principal_type' => $user['principal_type'],
                'email'          => $user['email'],
                'phone'          => $user['phone'],
            ],
        ];
    }
}
