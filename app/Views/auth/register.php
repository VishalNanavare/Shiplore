<?= $this->extend('layouts/auth') ?>

<?php $fbReady = ($fb['apiKey'] ?? '') !== '' && ($fb['projectId'] ?? '') !== ''; ?>
<?php $mobileVerified = ($reg['mobile_verified'] ?? false) === true; ?>

<?= $this->section('content') ?>
<div class="card auth-card shadow-lg" style="max-width:<?= $stage === 'verify' ? '460px' : '760px' ?>">
    <div class="card-body p-4 p-sm-5">
        <div class="text-center mb-4">
            <img src="<?= asset('images/logo.svg') ?>" alt="" width="46" height="46" class="mb-2">
            <h1 class="h4 auth-brand mb-1">Become a Vendor</h1>
            <p class="text-secondary small mb-0"><?= $stage === 'verify' ? 'Verify your mobile, email & GSTIN' : 'Register your business to start selling' ?></p>
        </div>

        <?php if ($m = session('status')): ?><div class="alert alert-success py-2"><?= esc($m) ?></div><?php endif; ?>
        <?php if ($m = session('error')): ?><div class="alert alert-danger py-2"><?= esc($m) ?></div><?php endif; ?>
        <?php if ($m = session('dev_codes')): ?><div class="alert alert-info py-2 small"><?= esc($m) ?></div><?php endif; ?>

        <?php if ($stage === 'verify'): ?>
            <div class="alert alert-light border small mb-3">
                <div><strong><?= esc($reg['display_name'] ?? '') ?></strong> · <?= esc($reg['shop_name'] ?? '') ?></div>
                <div class="text-secondary"><?= esc($reg['mobile'] ?? '') ?> · <?= esc($reg['email'] ?? '') ?> · GSTIN <?= esc($reg['gstin'] ?? '') ?></div>
            </div>

            <!-- Step 1: prove the mobile number with Firebase phone auth. -->
            <div class="mb-4">
                <label class="form-label d-flex justify-content-between align-items-center">
                    <span>Mobile verification</span>
                    <?php if ($mobileVerified): ?>
                        <span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Verified</span>
                    <?php endif; ?>
                </label>

                <?php if ($mobileVerified): ?>
                    <div class="form-text"><?= esc($reg['mobile']) ?> is verified.</div>
                <?php elseif (! $fbReady): ?>
                    <div class="alert alert-warning py-2 small mb-0">
                        Mobile verification is temporarily unavailable. Please contact support.
                    </div>
                <?php else: ?>
                    <div id="otpMsg" class="mb-2"></div>
                    <div id="otpRecaptcha" class="mb-2"></div>
                    <button type="button" id="otpSend" class="btn btn-outline-primary btn-sm w-100"
                            data-cooldown="<?= (int) ($resendWait['mobile'] ?? 0) ?>"
                            data-label="Send OTP to <?= esc($reg['mobile'] ?? '', 'attr') ?>">
                        <i class="bi bi-phone me-1"></i>Send OTP to <?= esc($reg['mobile'] ?? '') ?>
                    </button>
                    <div id="otpCodeWrap" class="d-none mt-2">
                        <div class="input-group">
                            <input id="otpCode" class="form-control" inputmode="numeric" maxlength="6" placeholder="6-digit OTP">
                            <button type="button" id="otpVerify" class="btn btn-primary">Verify</button>
                        </div>
                        <div class="mt-1">
                            <button type="button" id="otpResend" class="btn btn-link btn-sm p-0" data-label="Resend OTP">Resend OTP</button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Step 2: prove the email address with the emailed code. -->
            <form method="post" action="<?= site_url('register/complete') ?>" autocomplete="off">
                <?= csrf_field() ?>
                <div class="mb-1">
                    <label class="form-label">Email code</label>
                    <input name="email_code" class="form-control" inputmode="numeric" maxlength="6" required>
                </div>
                <div class="mb-3 text-end">
                    <span class="small text-secondary">Sent to <?= esc($reg['email'] ?? '') ?></span>
                </div>
                <button class="btn btn-primary w-100" type="submit" <?= $mobileVerified ? '' : 'disabled' ?>>
                    <i class="bi bi-shield-check me-1"></i>Verify &amp; create account
                </button>
                <?php if (! $mobileVerified): ?>
                    <div class="form-text text-center mt-1">Verify your mobile number first.</div>
                <?php endif; ?>
            </form>

            <div class="d-flex justify-content-between align-items-center mt-3">
                <form method="post" action="<?= site_url('register/resend/email') ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-link btn-sm p-0 js-countdown"
                            data-cooldown="<?= (int) ($resendWait['email'] ?? 0) ?>"
                            data-label="Resend email code">Resend email code</button>
                </form>
                <form method="post" action="<?= site_url('register/cancel') ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-link btn-sm p-0 text-secondary">Start over</button>
                </form>
            </div>
        <?php else: ?>
            <form method="post" action="<?= site_url('register/send-codes') ?>" autocomplete="off">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Business Type</label>
                        <select name="business_type_id" class="form-select" required>
                            <option value="">Choose…</option>
                            <?php foreach ($businessTypes as $bt): ?><option value="<?= esc($bt['id'], 'attr') ?>" <?= old('business_type_id') == $bt['id'] ? 'selected' : '' ?>><?= esc($bt['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label">GSTIN</label><input name="gstin" class="form-control text-uppercase" maxlength="15" value="<?= esc(old('gstin'), 'attr') ?>" placeholder="27ABCDE1234F1Z5" required></div>
                    <div class="col-md-6"><label class="form-label">Legal Name</label><input name="legal_name" class="form-control" value="<?= esc(old('legal_name'), 'attr') ?>" required></div>
                    <div class="col-md-6"><label class="form-label">Display Name</label><input name="display_name" class="form-control" value="<?= esc(old('display_name'), 'attr') ?>" required></div>
                    <div class="col-md-6"><label class="form-label">Mobile</label><input name="mobile" class="form-control" value="<?= esc(old('mobile'), 'attr') ?>" placeholder="+91…" required></div>
                    <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= esc(old('email'), 'attr') ?>" required></div>
                    <div class="col-md-6"><label class="form-label">Password</label><input type="password" name="password" class="form-control" minlength="8" required></div>
                    <div class="col-md-6"><label class="form-label">Shop Name</label><input name="shop_name" class="form-control" value="<?= esc(old('shop_name'), 'attr') ?>" required></div>
                    <?= $this->include('partials/_shop_location', ['mapsKey' => $mapsKey, 'states' => $states, 'maxRadius' => $maxRadius]) ?>
                </div>
                <button class="btn btn-primary w-100 mt-4" type="submit"><i class="bi bi-send me-1"></i>Send OTP &amp; email code</button>
            </form>
            <p class="text-center text-secondary small mt-3 mb-0">Already registered? <a href="<?= site_url('/') ?>">Sign in</a></p>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>

<?php if ($stage !== 'verify'): ?>
<?= $this->section('scripts') ?>
<?= $this->include('partials/_shop_location_scripts', ['mapsKey' => $mapsKey, 'stateNameToCode' => $stateNameToCode]) ?>
<?= $this->endSection() ?>
<?php else: ?>
<?= $this->section('scripts') ?>
<script>
// Countdown for any resend button. Cosmetic only — the server rejects early clicks,
// and seeds data-cooldown so a refresh or a second tab shows the true remaining wait.
function regCountdown(btn, seconds) {
    var left = seconds;
    (function tick() {
        if (left <= 0) { btn.disabled = false; btn.textContent = btn.dataset.label; return; }
        btn.disabled = true;
        btn.textContent = btn.dataset.label + ' (' + left + 's)';
        left--; setTimeout(tick, 1000);
    })();
}
document.querySelectorAll('.js-countdown').forEach(function (b) {
    regCountdown(b, parseInt(b.dataset.cooldown || '0', 10));
});
</script>

<?php if ($fbReady && ! $mobileVerified): ?>
<script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-auth-compat.js"></script>
<script>
(function () {
    var sendBtn   = document.getElementById('otpSend');
    var resendBtn = document.getElementById('otpResend');
    var msgEl     = document.getElementById('otpMsg');
    function msg(t, ok) { msgEl.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'danger') + ' py-2 mb-0 small">' + t + '</div>'; }

    if (typeof firebase === 'undefined') {
        msg('Could not load Firebase (the script may be blocked by your network or an extension). Mobile verification is unavailable.', false);
        sendBtn.disabled = true;
        return;
    }

    firebase.initializeApp({
        apiKey: <?= json_encode($fb['apiKey']) ?>,
        authDomain: <?= json_encode($fb['authDomain']) ?>,
        projectId: <?= json_encode($fb['projectId']) ?>,
        messagingSenderId: <?= json_encode($fb['messagingSenderId']) ?>,
        appId: <?= json_encode($fb['appId']) ?>
    });
    var auth  = firebase.auth();
    var phone = <?= json_encode($reg['mobile'] ?? '') ?>;
    function meta(n, d) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.getAttribute('content') : d; }
    function csrfBody(extra) {
        return encodeURIComponent(meta('csrf-name', <?= json_encode(csrf_token()) ?>)) + '='
             + encodeURIComponent(meta('csrf-hash', <?= json_encode(csrf_hash()) ?>)) + (extra || '');
    }
    function post(url, extra) {
        return fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: csrfBody(extra)
        }).then(function (r) { return r.json(); });
    }

    // Firebase surfaces precise auth/* codes. Show them: "Send failed" alone makes an
    // SMS-region block, an unauthorised domain and an exhausted quota all look the same.
    function explain(e) {
        var code = (e && e.code) || '';
        if (code === 'auth/invalid-phone-number')      { return 'That mobile number is not valid.'; }
        if (code === 'auth/too-many-requests')         { return 'Firebase is rate-limiting this number. Wait a few minutes.'; }
        if (code === 'auth/quota-exceeded')            { return 'The SMS quota for this project is exhausted. Contact support.'; }
        if (code === 'auth/captcha-check-failed')      { return 'reCAPTCHA failed — this domain may not be authorised in Firebase.'; }
        if (code === 'auth/unauthorized-domain')       { return 'This domain is not authorised in the Firebase console.'; }
        if (code === 'auth/operation-not-allowed')     { return 'Phone sign-in is disabled for this Firebase project.'; }
        return 'Send failed' + (code ? ' (' + code + ')' : '') + ': ' + ((e && e.message) ? e.message : e);
    }

    var verifier = new firebase.auth.RecaptchaVerifier('otpRecaptcha', { size: 'normal' });
    verifier.render();
    var confirmation = null;

    // Every SMS — first send and each resend — asks the server for a ticket first, so
    // pacing and the per-number cap are enforced where the user cannot edit them.
    function sendOtp(btn) {
        msg('Checking…', true);
        return post(<?= json_encode(site_url('register/otp-ticket')) ?>).then(function (t) {
            if (!t.ok) {
                msg(t.message || 'Please wait before requesting another OTP.', false);
                if (t.wait) { regCountdown(btn, t.wait); }
                return;
            }
            msg('Sending OTP…', true);
            return auth.signInWithPhoneNumber(phone, verifier).then(function (c) {
                confirmation = c;
                document.getElementById('otpCodeWrap').classList.remove('d-none');
                msg('OTP sent to ' + phone + '.', true);
                regCountdown(resendBtn, t.cooldown);
            }).catch(function (e) { msg(explain(e), false); });
        }).catch(function () { msg('Network error. Please try again.', false); });
    }

    sendBtn.addEventListener('click', function () { sendOtp(sendBtn); });
    resendBtn.addEventListener('click', function () { sendOtp(resendBtn); });

    document.getElementById('otpVerify').addEventListener('click', function () {
        if (!confirmation) { msg('Send the OTP first.', false); return; }
        msg('Verifying…', true);
        confirmation.confirm(document.getElementById('otpCode').value.trim()).then(function (res) {
            return res.user.getIdToken();
        }).then(function (idToken) {
            return post(<?= json_encode(site_url('register/verify-mobile')) ?>, '&id_token=' + encodeURIComponent(idToken));
        }).then(function (res) {
            // Reload so the server re-renders the verified state; it is the only authority.
            if (res && res.ok) { window.location.reload(); }
            else { msg((res && res.message) || 'Verification failed.', false); }
        }).catch(function () { msg('Invalid or expired OTP.', false); });
    });

    <?php if (($resendWait['mobile'] ?? 0) > 0): ?>
    regCountdown(sendBtn, <?= (int) $resendWait['mobile'] ?>);
    <?php endif; ?>
})();
</script>
<?php endif; ?>
<?= $this->endSection() ?>
<?php endif; ?>
