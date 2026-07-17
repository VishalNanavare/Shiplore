<?= $this->extend('layouts/auth') ?>
<?php $fb = $fb ?? []; $fbReady = ($fb['apiKey'] ?? '') !== '' && ($fb['projectId'] ?? '') !== ''; ?>

<?= $this->section('content') ?>
<div class="card auth-card shadow-lg">
    <div class="card-body p-4 p-sm-5">
        <div class="text-center mb-4">
            <img src="<?= asset('images/logo.svg') ?>" alt="" width="48" height="48" class="mb-2">
            <h1 class="h4 auth-brand mb-1"><?= esc(service('settingsRepository')->brandName()) ?></h1>
            <p class="text-secondary small mb-0">Sign in to your account</p>
        </div>

        <?php if ($m = session('status')): ?>
            <div class="alert alert-success py-2"><?= esc($m) ?></div>
        <?php endif; ?>
        <?php if ($flashError = session('error')): ?>
            <div class="alert alert-danger py-2"><?= esc($flashError) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= site_url('login') ?>" autocomplete="off">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="email">Email or Mobile</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" id="email" name="login" placeholder="you@example.com" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember">
                    <label class="form-check-label small" for="remember">Remember me</label>
                </div>
                <a href="<?= site_url('forgot-password') ?>" class="small">Forgot password?</a>
            </div>
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
            </button>
        </form>

        <?php if ($fbReady): ?>
            <div class="d-flex align-items-center my-3 text-secondary small">
                <hr class="flex-grow-1"><span class="px-2">or</span><hr class="flex-grow-1">
            </div>
            <button type="button" id="otpToggle" class="btn btn-outline-secondary w-100">
                <i class="bi bi-phone me-1"></i> Sign in with mobile OTP
            </button>
            <div id="otpBox" class="d-none mt-3">
                <div class="input-group mb-2">
                    <span class="input-group-text"><i class="bi bi-phone"></i></span>
                    <input id="otpPhone" class="form-control" placeholder="+9198XXXXXXXX" autocomplete="off">
                </div>
                <div id="otpRecaptcha" class="mb-2"></div>
                <button type="button" id="otpSend" class="btn btn-primary w-100 mb-2">Send OTP</button>
                <div id="otpCodeWrap" class="d-none">
                    <div class="input-group mb-2">
                        <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
                        <input id="otpCode" class="form-control" inputmode="numeric" placeholder="6-digit code" autocomplete="one-time-code">
                    </div>
                    <button type="button" id="otpVerify" class="btn btn-success w-100">Verify &amp; sign in</button>
                </div>
                <div id="otpMsg" class="small mt-2"></div>
            </div>
        <?php endif; ?>

        <p class="text-center text-secondary small mt-4 mb-0">
            Vendor? <a href="<?= site_url('register') ?>">Register your business</a>
        </p>
    </div>
</div>
<?= $this->endSection() ?>

<?php if ($fbReady): ?>
<?= $this->section('scripts') ?>
<script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-auth-compat.js"></script>
<script>
(function () {
    var toggle = document.getElementById('otpToggle');
    if (!toggle || typeof firebase === 'undefined') { return; } // CDN blocked -> password login still works
    firebase.initializeApp({
        apiKey: <?= json_encode($fb['apiKey']) ?>,
        authDomain: <?= json_encode($fb['authDomain']) ?>,
        projectId: <?= json_encode($fb['projectId']) ?>,
        messagingSenderId: <?= json_encode($fb['messagingSenderId']) ?>,
        appId: <?= json_encode($fb['appId']) ?>
    });
    var auth = firebase.auth();
    var msgEl = document.getElementById('otpMsg');
    function msg(t, ok) { msgEl.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'danger') + ' py-2 mb-0">' + t + '</div>'; }
    function meta(n, d) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.getAttribute('content') : d; }

    var verifier = null, confirmation = null;
    toggle.addEventListener('click', function () {
        var box = document.getElementById('otpBox');
        box.classList.toggle('d-none');
        if (!verifier && !box.classList.contains('d-none')) {
            verifier = new firebase.auth.RecaptchaVerifier('otpRecaptcha', { size: 'normal' });
            verifier.render();
        }
    });

    document.getElementById('otpSend').addEventListener('click', function () {
        var phone = document.getElementById('otpPhone').value.trim();
        if (!phone) { msg('Enter your mobile number with country code.', false); return; }
        msg('Sending OTP…', true);
        auth.signInWithPhoneNumber(phone, verifier).then(function (c) {
            confirmation = c;
            document.getElementById('otpCodeWrap').classList.remove('d-none');
            msg('OTP sent to ' + phone + '.', true);
        }).catch(function (e) { msg('Send failed: ' + (e && e.message ? e.message : e), false); });
    });

    document.getElementById('otpVerify').addEventListener('click', function () {
        if (!confirmation) { return; }
        var code = document.getElementById('otpCode').value.trim();
        msg('Verifying…', true);
        confirmation.confirm(code).then(function (res) {
            return res.user.getIdToken();
        }).then(function (idToken) {
            return fetch(<?= json_encode(site_url('login/otp')) ?>, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: encodeURIComponent(meta('csrf-name', <?= json_encode(csrf_token()) ?>)) + '='
                    + encodeURIComponent(meta('csrf-hash', <?= json_encode(csrf_hash()) ?>))
                    + '&id_token=' + encodeURIComponent(idToken)
            });
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res.ok && res.redirect) { window.location.assign(res.redirect); }
            else { msg(res.message || 'Sign-in failed.', false); }
        }).catch(function () { msg('Invalid or expired code.', false); });
    });
})();
</script>
<?= $this->endSection() ?>
<?php endif; ?>
