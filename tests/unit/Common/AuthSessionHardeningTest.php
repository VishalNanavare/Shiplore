<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Session and credential-lifecycle guards (audit H1, H2, M5, M7, L7).
 *
 * The common shape of these findings: nothing revokes. A suspended account kept its
 * browser session, the mobile login had neither lockout nor audit trail, and a reset
 * token was written to a log retained in production.
 */
final class AuthSessionHardeningTest extends CIUnitTestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

    /** Crude brace-matching body extractor — same convention as the other suites here. */
    private function methodBody(string $src, string $method): string
    {
        if (! preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $brace = strpos($src, '{', (int) $m[0][1]);
        if ($brace === false) {
            return '';
        }
        $depth = 0;
        for ($i = $brace, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }

        return '';
    }

    // ------------------------------------------------------------------ H1

    /**
     * Suspending a user must end their browser session. WebAuthFilter proved only that
     * `isLoggedIn` was set; JwtAuthFilter re-checks the account on every API request,
     * so the mobile app died on suspension while the browser tab kept full panel
     * access indefinitely (FileHandler refreshes the session on every request).
     */
    public function testWebAuthFilterRechecksAccountStatusOnEveryRequest(): void
    {
        $body = $this->methodBody($this->read('Filters/WebAuthFilter.php'), 'before');

        $this->assertStringContainsString(
            "service('apiAuthRepository')->isActive(",
            $body,
            'WebAuthFilter must re-check the account is still active, as JwtAuthFilter already does',
        );
        $this->assertStringContainsString('$session->destroy()', $body);

        // It must fail OPEN on a DB error. This is on every authenticated page load, so
        // an uncaught exception here 500s (or mass-logs-out) every signed-in user at
        // once — a worse outage than the risk being closed.
        $this->assertMatchesRegularExpression(
            '/catch \(\\\\?Throwable[^)]*\)[^}]*\$stillActive = true;/s',
            $body,
            'the status re-check must fail open on a DB fault, not lock everyone out',
        );

        // The check must run BEFORE capabilities are resolved and handed to ScopeContext.
        $check = strpos($body, 'isActive(');
        $scope = strpos($body, "service('scopeContext')->set(");
        $this->assertNotFalse($check);
        $this->assertNotFalse($scope);
        $this->assertLessThan($scope, $check, 'the status re-check must precede populating ScopeContext');
    }

    /**
     * The revocation code deleted from `sessions` — an application device-session
     * table that nothing in app/ ever inserts into — while the real store is
     * FileHandler files. Leaving it in place reads as working protection.
     */
    public function testDeadSessionRevocationIsNotPresentedAsProtection(): void
    {
        $src = $this->read('Controllers/Vendor/StaffController.php');

        $this->assertStringNotContainsString(
            "table('sessions')",
            $src,
            "deleting from `sessions` is a no-op — CI4 sessions are FileHandler files. "
            . 'Leaving it implies staff sessions are revoked when they are not.',
        );
        // The half that DOES work must stay.
        $this->assertStringContainsString("table('auth_tokens')", $src);
    }

    // ------------------------------------------------------------------ H2

    /** The mobile password login must enforce the same lockout as the web login. */
    public function testApiLoginEnforcesLockoutAndAudits(): void
    {
        $body = $this->methodBody($this->read('Controllers/Api/V1/AuthApiController.php'), 'login');

        $this->assertStringContainsString('recentFailureCount(', $body, 'the API login has no brute-force lockout');
        $this->assertStringContainsString("'RATE_LIMITED'", $body);
        $this->assertStringContainsString('->record(', $body, 'every API login attempt must be audited to login_attempts');

        // The lockout must be consulted before the credentials are checked.
        $gate = strpos($body, 'recentFailureCount(');
        $veri = strpos($body, 'password_verify(');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($veri);
        $this->assertLessThan($veri, $gate, 'the lockout must gate the password check, not follow it');
    }

    /** Both outcomes must be recorded, or the audit trail only shows successes. */
    public function testApiLoginRecordsFailuresAndSuccesses(): void
    {
        $body = $this->methodBody($this->read('Controllers/Api/V1/AuthApiController.php'), 'login');

        $this->assertStringContainsString('false,', $body, 'failed attempts must be recorded');
        $this->assertStringContainsString('true,', $body, 'successful attempts must be recorded');
    }

    // ------------------------------------------------------------------ M5

    /** The raw reset token must never reach the log — production retains `error`. */
    public function testPasswordResetLinkIsNotLogged(): void
    {
        $body = $this->methodBody($this->read('Controllers/Auth/ForgotPasswordController.php'), 'send');

        if ($body === '') {
            $body = $this->read('Controllers/Auth/ForgotPasswordController.php');
        }

        $this->assertDoesNotMatchRegularExpression(
            "/log_message\([^)]*'link'\s*=>\s*\\\$link/s",
            $body,
            'the reset link carries the raw single-use token and this log level is retained in production',
        );
    }

    // ------------------------------------------------------------------ M7

    /** Sign-in must rotate the session ID before elevating it — fixation defence. */
    public function testCustomerOtpVerifyRegeneratesTheSession(): void
    {
        $body = $this->methodBody($this->read('Controllers/Store/AccountController.php'), 'verify');

        $this->assertStringContainsString('regenerate(', $body, 'verify() writes customer_id into the presented session ID');

        $regen = strpos($body, 'regenerate(');
        $set   = strpos($body, "session()->set(");
        $this->assertNotFalse($regen);
        if ($set !== false) {
            $this->assertLessThan($set, $regen, 'the session must rotate BEFORE the identity is written into it');
        }
    }

    /** Logout must retire the session ID, not merely unset the identity keys. */
    public function testLogoutDestroysTheOldSessionId(): void
    {
        foreach (['Controllers/Store/AccountController.php', 'Controllers/Rider/AuthController.php'] as $rel) {
            $body = $this->methodBody($this->read($rel), 'logout');

            $this->assertStringContainsString(
                'regenerate(true)',
                $body,
                $rel . '::logout() leaves the old session ID valid and replayable',
            );
        }
    }

    // ------------------------------------------------------------------ M6

    /** Every mobile-JWT mint site must stamp the password-binding claim so a password change can invalidate the token. */
    public function testAllFourMintSitesStampThePwdClaim(): void
    {
        $sites = [
            ['Controllers/Api/V1/AuthApiController.php', 'session'],
            ['Controllers/Api/V1/VendorPosController.php', 'firebaseVerify'],
            ['Controllers/Api/V1/VendorPosController.php', 'otpVerify'],
        ];

        foreach ($sites as [$rel, $method]) {
            $body = $this->methodBody($this->read($rel), $method);

            $this->assertStringContainsString(
                'TokenService::pwdClaim(',
                $body,
                "{$rel}::{$method}() must stamp the pwd claim, or a password change never revokes its tokens",
            );
        }
    }

    /** Nullable-tolerant on purpose: tokens already in the wild must keep working, so the claim can only be conditionally added. */
    public function testPwdClaimIsOnlyAddedWhenNotNull(): void
    {
        foreach (['session', 'firebaseVerify', 'otpVerify'] as $method) {
            $rel  = str_contains($method, 'Verify') ? 'Controllers/Api/V1/VendorPosController.php' : 'Controllers/Api/V1/AuthApiController.php';
            $body = $this->methodBody($this->read($rel), $method);

            $this->assertMatchesRegularExpression(
                '/if\s*\(\s*\$pwd\s*!==\s*null\s*\)/',
                $body,
                "{$rel}::{$method}() must only add the pwd claim when TokenService::pwdClaim() returned non-null",
            );
        }
    }

    /** JwtAuthFilter must verify the claim after confirming the account is active, and reject a stale one. */
    public function testJwtAuthFilterVerifiesThePwdClaim(): void
    {
        $body = $this->methodBody($this->read('Filters/JwtAuthFilter.php'), 'before');

        $this->assertStringContainsString('TokenService::pwdClaim(', $body);
        $this->assertStringContainsString('hash_equals(', $body);

        $isActive = strpos($body, 'isActive(');
        $pwdCheck = strpos($body, "\$ctx['token_claims']['pwd']");
        $this->assertNotFalse($isActive);
        $this->assertNotFalse($pwdCheck);
        $this->assertLessThan($pwdCheck, $isActive, 'the account-active re-check must run before the password-claim check');
    }

    /** A self-service password change must rotate the web session so a pre-change session ID cannot ride the account past the change. */
    public function testSelfServicePasswordChangesRegenerateTheSession(): void
    {
        foreach ([
            ['Controllers/Admin/ProfileController.php', 'savePassword'],
            ['Controllers/Vendor/MeController.php', 'savePassword'],
        ] as [$rel, $method]) {
            $body = $this->methodBody($this->read($rel), $method);

            $this->assertStringContainsString('regenerate(true)', $body, "{$rel}::{$method}() must rotate the session ID after a password change");

            $update = strpos($body, 'updatePassword(');
            $regen  = strpos($body, 'regenerate(true)');
            $this->assertNotFalse($update);
            $this->assertNotFalse($regen);
            $this->assertLessThan($regen, $update, 'the session must rotate AFTER the password is actually changed, not before');
        }
    }

    // ------------------------------------------------------------------ M8 step 1

    /**
     * Every staff/customer/rider sign-in point must fully destroy the pre-rotation
     * session file (`regenerate(true)`), not leave it on disk still accepted until
     * GC. Low risk specifically here: the pre-login session holds only flashdata and
     * CSRF state, both carried across a regenerate() call regardless of the destroy
     * flag — unlike the mid-session auto-rotation this finding also covers (step 2,
     * not done in this pass).
     */
    public function testEverySignInPointDestroysThePriorSessionFile(): void
    {
        $sites = [
            ['Controllers/Auth/LoginController.php', 'attempt', 1],
            ['Controllers/Auth/LoginController.php', 'otpLogin', 1],
            ['Controllers/Store/AccountController.php', 'otpLogin', 1],
            ['Controllers/Store/AccountController.php', 'verify', 2], // phone branch + email branch
            ['Controllers/Rider/AuthController.php', 'otpLogin', 1],
            ['Controllers/Rider/AuthController.php', 'signIn', 1],
        ];

        foreach ($sites as [$rel, $method, $expected]) {
            $body = $this->methodBody($this->read($rel), $method);
            $this->assertNotSame('', $body, "{$rel}::{$method}() not found — has it been renamed?");

            $this->assertSame(
                $expected,
                substr_count($body, 'regenerate(true)'),
                "{$rel}::{$method}() must call regenerate(true) at every sign-in branch, or a cookie captured before sign-in survives sign-out",
            );
        }
    }

    // ------------------------------------------------------------------ L7

    /** Failing open is correct; failing open SILENTLY is not. */
    public function testLockoutFailureIsAudible(): void
    {
        $src = $this->read('Models/LoginAttemptRepository.php');

        $this->assertMatchesRegularExpression(
            "/catch \(Throwable[^)]*\) \{\s*\n[^}]*log_message\(\s*'critical'/s",
            $src,
            'a DB error here silently disables brute-force protection with no other signal',
        );
        // The fail-open contract itself must not change.
        $this->assertStringContainsString('return 0;', $src);
    }
}
