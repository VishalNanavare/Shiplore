<?= $this->extend('layouts/rider') ?>
<?php $fb = $fb ?? []; $fbReady = ($fb['apiKey'] ?? '') !== '' && ($fb['projectId'] ?? '') !== ''; ?>

<?= $this->section('content') ?>
<div class="rd-auth">
    <div class="rd-card p-4 w-100">
        <div class="text-center mb-3">
            <div class="rd-auth-badge mx-auto mb-2"><i class="bi bi-scooter"></i></div>
            <h1 class="h4 mb-1">Rider sign in</h1>
            <p class="text-secondary small mb-0">Sign in with your registered mobile number.</p>
        </div>

        <?php if ($m = session('status')): ?><div class="alert alert-success py-2 small"><?= esc($m) ?></div><?php endif; ?>
        <?php if ($m = session('error')): ?><div class="alert alert-danger py-2 small"><?= esc($m) ?></div><?php endif; ?>
        <?php if ($m = session('dev_codes')): ?><div class="alert alert-info py-2 small"><i class="bi bi-bug me-1"></i><?= esc($m) ?></div><?php endif; ?>

        <?php if (($stage ?? '') === 'verify'): ?>
            <form method="post" action="<?= site_url('rider/login/verify') ?>">
                <?= csrf_field() ?>
                <label class="form-label small">Enter the 6-digit code</label>
                <input name="code" class="form-control form-control-lg rd-otp mb-3" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="••••••" required autofocus>
                <button class="btn btn-rd btn-lg w-100">Verify &amp; sign in</button>
            </form>
            <div class="text-center mt-3"><form method="post" action="<?= site_url('rider/logout') ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="btn btn-link btn-sm p-0 small text-secondary text-decoration-none">Use a different number</button></form></div>

        <?php elseif ($fbReady): ?>
            <label class="form-label small">Mobile number</label>
            <div class="input-group input-group-lg mb-2">
                <span class="input-group-text">+91</span>
                <input id="rdPhone" class="form-control" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" placeholder="98765 43210" autofocus>
            </div>
            <div id="rdRecaptcha" class="mb-2"></div>
            <button type="button" id="rdSend" class="btn btn-rd btn-lg w-100"><i class="bi bi-chat-dots me-1"></i>Send OTP</button>
            <div id="rdCodeWrap" class="d-none mt-2">
                <input id="rdCode" class="form-control form-control-lg rd-otp mb-2" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="••••••">
                <button type="button" id="rdVerify" class="btn btn-success btn-lg w-100">Verify &amp; sign in</button>
            </div>
            <div id="rdMsg" class="small mt-2"></div>

        <?php else: ?>
            <form method="post" action="<?= site_url('rider/login') ?>">
                <?= csrf_field() ?>
                <label class="form-label small">Mobile number</label>
                <div class="input-group input-group-lg mb-3">
                    <span class="input-group-text">+91</span>
                    <input name="phone" class="form-control" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" placeholder="98765 43210" required autofocus>
                </div>
                <button class="btn btn-rd btn-lg w-100"><i class="bi bi-chat-dots me-1"></i>Send OTP</button>
            </form>
        <?php endif; ?>
        <p class="text-secondary text-center mt-3 mb-0" style="font-size:.72rem">Not a rider? Ask your vendor to register you.</p>
    </div>
</div>
<?= $this->endSection() ?>

<?php if ($fbReady && ($stage ?? '') !== 'verify'): ?>
<?= $this->section('scripts') ?>
<script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-auth-compat.js"></script>
<script>
(function () {
    var sendBtn = document.getElementById('rdSend'), msgEl = document.getElementById('rdMsg');
    function msg(t, ok) { msgEl.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'danger') + ' py-2 mb-0 small">' + t + '</div>'; }
    function meta(n, d) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.getAttribute('content') : d; }
    if (!sendBtn || typeof firebase === 'undefined') { if (msgEl) { msg('OTP service unavailable. Please try again later.', false); } return; }
    firebase.initializeApp({
        apiKey: <?= json_encode($fb['apiKey']) ?>, authDomain: <?= json_encode($fb['authDomain']) ?>,
        projectId: <?= json_encode($fb['projectId']) ?>, messagingSenderId: <?= json_encode($fb['messagingSenderId']) ?>, appId: <?= json_encode($fb['appId']) ?>
    });
    var auth = firebase.auth(), verifier = new firebase.auth.RecaptchaVerifier('rdRecaptcha', { size: 'normal' }), confirmation = null;
    verifier.render();
    sendBtn.addEventListener('click', function () {
        var digits = (document.getElementById('rdPhone').value || '').replace(/\D/g, '');
        if (digits.length !== 10) { msg('Enter a valid 10-digit mobile number.', false); return; }
        sendBtn.disabled = true; msg('Sending OTP…', true);
        auth.signInWithPhoneNumber('+91' + digits, verifier).then(function (c) {
            confirmation = c; document.getElementById('rdCodeWrap').classList.remove('d-none'); document.getElementById('rdCode').focus();
            msg('OTP sent to +91 ' + digits + '.', true);
        }).catch(function (e) { sendBtn.disabled = false; msg('Could not send OTP: ' + (e && e.message ? e.message : e), false); });
    });
    document.getElementById('rdVerify').addEventListener('click', function () {
        if (!confirmation) { return; }
        msg('Verifying…', true);
        confirmation.confirm((document.getElementById('rdCode').value || '').trim()).then(function (res) { return res.user.getIdToken(); })
        .then(function (idToken) {
            return fetch(<?= json_encode(site_url('rider/login/otp')) ?>, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: encodeURIComponent(meta('csrf-name', <?= json_encode(csrf_token()) ?>)) + '=' + encodeURIComponent(meta('csrf-hash', <?= json_encode(csrf_hash()) ?>)) + '&id_token=' + encodeURIComponent(idToken)
            });
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res.ok && res.redirect) { window.location.assign(res.redirect); } else { msg(res.message || 'Sign-in failed.', false); }
        }).catch(function () { msg('Invalid or expired code.', false); });
    });
})();
</script>
<?= $this->endSection() ?>
<?php endif; ?>
