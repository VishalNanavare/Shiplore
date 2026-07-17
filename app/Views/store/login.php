<?= $this->extend('layouts/store') ?>
<?php $fb = $fb ?? []; $fbReady = ($fb['apiKey'] ?? '') !== '' && ($fb['projectId'] ?? '') !== ''; ?>

<?= $this->section('content') ?>
<div class="row justify-content-center"><div class="col-md-5 col-lg-4">
    <div class="card st-auth-card"><div class="card-body p-4">
        <div class="text-center mb-3">
            <div class="st-auth-badge mx-auto mb-2"><i class="bi bi-phone"></i></div>
            <h1 class="h4 mb-1">Sign in or sign up</h1>
            <p class="text-secondary small mb-0">Fast, password-free access with a one-time code.</p>
        </div>

        <?php if ($m = session('status')): ?><div class="alert alert-success py-2 small"><?= esc($m) ?></div><?php endif; ?>
        <?php if ($m = session('error')): ?><div class="alert alert-danger py-2 small"><?= esc($m) ?></div><?php endif; ?>
        <?php if ($m = session('dev_codes')): ?><div class="alert alert-info py-2 small mb-2"><i class="bi bi-bug me-1"></i><?= esc($m) ?></div><?php endif; ?>

        <?php if ($stage === 'verify_phone' || $stage === 'verify_email'): ?>
            <p class="text-secondary small text-center">Enter the 6-digit code we sent to your <?= $stage === 'verify_phone' ? 'mobile' : 'email' ?>.</p>
            <form method="post" action="<?= site_url('store/login/verify') ?>">
                <?= csrf_field() ?>
                <input name="code" class="form-control form-control-lg text-center st-otp mb-3" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="••••••" required autofocus>
                <button class="btn btn-primary w-100 btn-lg">Verify &amp; continue</button>
            </form>
            <div class="text-center mt-3"><form method="post" action="<?= site_url('store/logout') ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="btn btn-link btn-sm p-0 small text-secondary text-decoration-none">Start over</button></form></div>

        <?php elseif ($stage === 'email'): ?>
            <form method="post" action="<?= site_url('store/login/email') ?>">
                <?= csrf_field() ?>
                <label class="form-label small">Email address</label>
                <input type="email" name="email" class="form-control form-control-lg mb-3" placeholder="you@example.com" required autofocus>
                <button class="btn btn-primary w-100 btn-lg">Email me a code</button>
            </form>
            <div class="text-center mt-3"><a href="<?= site_url('store/login') ?>" class="small"><i class="bi bi-phone me-1"></i>Use mobile number instead</a></div>

        <?php elseif ($fbReady): /* real mobile OTP via Firebase */ ?>
            <label class="form-label small">Mobile number</label>
            <div class="input-group input-group-lg mb-2">
                <span class="input-group-text">+91</span>
                <input id="otpPhone" class="form-control" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" placeholder="98765 43210" autofocus>
            </div>
            <label class="form-label small">Your name <span class="text-secondary">(new users)</span></label>
            <input id="otpName" class="form-control form-control-lg mb-2" placeholder="e.g. Rahul Sharma" maxlength="60">
            <div id="otpRecaptcha" class="mb-2"></div>
            <button type="button" id="otpSend" class="btn btn-primary w-100 btn-lg"><i class="bi bi-chat-dots me-1"></i>Send OTP</button>
            <div id="otpCodeWrap" class="d-none mt-2">
                <input id="otpCode" class="form-control form-control-lg text-center st-otp mb-2" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="••••••">
                <button type="button" id="otpVerify" class="btn btn-success w-100 btn-lg">Verify &amp; continue</button>
            </div>
            <div id="otpMsg" class="small mt-2"></div>
            <div class="text-center mt-3"><a href="<?= site_url('store/login?via=email') ?>" class="small text-secondary"><i class="bi bi-envelope me-1"></i>Sign in with email instead</a></div>

        <?php else: /* fallback: dev OTP flow when Firebase isn't configured */ ?>
            <form method="post" action="<?= site_url('store/login') ?>">
                <?= csrf_field() ?>
                <label class="form-label small">Mobile number</label>
                <div class="input-group input-group-lg mb-3">
                    <span class="input-group-text">+91</span>
                    <input name="phone" class="form-control" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" placeholder="98765 43210" required autofocus>
                </div>
                <label class="form-label small">Your name <span class="text-secondary">(new users)</span></label>
                <input name="name" class="form-control form-control-lg mb-3" placeholder="e.g. Rahul Sharma" maxlength="60">
                <button class="btn btn-primary w-100 btn-lg"><i class="bi bi-chat-dots me-1"></i>Send OTP</button>
            </form>
            <div class="text-center mt-3"><a href="<?= site_url('store/login?via=email') ?>" class="small text-secondary"><i class="bi bi-envelope me-1"></i>Sign in with email instead</a></div>
        <?php endif; ?>

        <p class="text-secondary text-center mt-3 mb-0" style="font-size:.72rem">By continuing you agree to our Terms &amp; Privacy Policy.</p>
    </div></div>
</div></div>
<?= $this->endSection() ?>

<?php if ($fbReady && $stage !== 'verify_phone' && $stage !== 'verify_email' && $stage !== 'email'): ?>
<?= $this->section('scripts') ?>
<script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-auth-compat.js"></script>
<script>
(function () {
    var sendBtn = document.getElementById('otpSend');
    var msgEl = document.getElementById('otpMsg');
    function msg(t, ok) { msgEl.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'danger') + ' py-2 mb-0 small">' + t + '</div>'; }
    function meta(n, d) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.getAttribute('content') : d; }
    if (!sendBtn || typeof firebase === 'undefined') {
        if (msgEl) { msg('Could not load the OTP service. Please <a href="<?= site_url('store/login?via=email') ?>">sign in with email</a> instead.', false); }
        return;
    }
    firebase.initializeApp({
        apiKey: <?= json_encode($fb['apiKey']) ?>,
        authDomain: <?= json_encode($fb['authDomain']) ?>,
        projectId: <?= json_encode($fb['projectId']) ?>,
        messagingSenderId: <?= json_encode($fb['messagingSenderId']) ?>,
        appId: <?= json_encode($fb['appId']) ?>
    });
    var auth = firebase.auth();
    var verifier = new firebase.auth.RecaptchaVerifier('otpRecaptcha', { size: 'normal' });
    verifier.render();
    var confirmation = null;

    sendBtn.addEventListener('click', function () {
        var digits = (document.getElementById('otpPhone').value || '').replace(/\D/g, '');
        if (digits.length !== 10) { msg('Enter a valid 10-digit mobile number.', false); return; }
        sendBtn.disabled = true; msg('Sending OTP…', true);
        auth.signInWithPhoneNumber('+91' + digits, verifier).then(function (c) {
            confirmation = c;
            document.getElementById('otpCodeWrap').classList.remove('d-none');
            document.getElementById('otpCode').focus();
            msg('OTP sent to +91 ' + digits + '.', true);
        }).catch(function (e) {
            sendBtn.disabled = false;
            msg('Could not send OTP: ' + (e && e.message ? e.message : e), false);
        });
    });

    document.getElementById('otpVerify').addEventListener('click', function () {
        if (!confirmation) { return; }
        var code = (document.getElementById('otpCode').value || '').trim();
        msg('Verifying…', true);
        confirmation.confirm(code).then(function (res) {
            return res.user.getIdToken();
        }).then(function (idToken) {
            return fetch(<?= json_encode(site_url('store/login/otp')) ?>, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: encodeURIComponent(meta('csrf-name', <?= json_encode(csrf_token()) ?>)) + '='
                    + encodeURIComponent(meta('csrf-hash', <?= json_encode(csrf_hash()) ?>))
                    + '&id_token=' + encodeURIComponent(idToken)
                    + '&name=' + encodeURIComponent(document.getElementById('otpName').value || '')
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
