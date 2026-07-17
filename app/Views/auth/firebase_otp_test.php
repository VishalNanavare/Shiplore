<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<?php $ready = ($fb['apiKey'] ?? '') !== '' && ($fb['projectId'] ?? '') !== ''; ?>
<div class="card mx-auto" style="max-width:480px">
    <div class="card-body">
        <h1 class="h5 mb-1"><i class="bi bi-phone me-1"></i>Firebase Phone-OTP — Test</h1>
        <p class="text-secondary small mb-3">Sends a real OTP via your Firebase project, then verifies it on the server.</p>

        <?php if (! $ready): ?>
            <div class="alert alert-warning small mb-0">
                Firebase isn't fully configured. Fill <b>Web API Key</b> and <b>Project ID</b> (and App ID / Sender ID)
                in <a href="<?= site_url('admin/integrations/firebase') ?>">Admin → Integrations → Firebase</a>, then reload.
            </div>
        <?php else: ?>
            <div class="small border rounded p-2 mb-3 bg-light">
                <div class="fw-semibold mb-1">Config in use — compare with your Firebase web app:</div>
                <div>API Key: <code><?= $fb['apiKey'] !== '' ? esc(substr($fb['apiKey'], 0, 10) . '…' . substr($fb['apiKey'], -4)) : '(empty)' ?></code>
                    <?php if (! str_starts_with((string) $fb['apiKey'], 'AIza')): ?><span class="text-danger">← a real Web API Key starts with "AIza"</span><?php endif; ?>
                </div>
                <div>Project ID: <code><?= esc($fb['projectId'] ?: '(empty)') ?></code></div>
                <div>App ID: <code><?= esc($fb['appId'] ?: '(empty)') ?></code></div>
                <div>Sender ID: <code><?= esc($fb['messagingSenderId'] ?: '(empty)') ?></code></div>
            </div>
            <div class="mb-2">
                <label class="form-label small">Phone (with country code)</label>
                <input id="fbPhone" class="form-control" value="+919768181958" placeholder="+9198XXXXXXXX" autocomplete="off">
            </div>
            <div id="recaptcha" class="mb-2"></div>
            <button id="fbSend" class="btn btn-primary w-100 mb-2">Send OTP</button>

            <div id="fbCodeWrap" class="d-none">
                <label class="form-label small">Enter the 6-digit code</label>
                <input id="fbCode" class="form-control mb-2" inputmode="numeric" autocomplete="one-time-code">
                <button id="fbVerify" class="btn btn-success w-100">Verify</button>
            </div>

            <div id="fbResult" class="mt-3 small"></div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if ($ready): ?>
<script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-auth-compat.js"></script>
<script>
(function () {
    firebase.initializeApp({
        apiKey: <?= json_encode($fb['apiKey']) ?>,
        authDomain: <?= json_encode($fb['authDomain']) ?>,
        projectId: <?= json_encode($fb['projectId']) ?>,
        messagingSenderId: <?= json_encode($fb['messagingSenderId']) ?>,
        appId: <?= json_encode($fb['appId']) ?>
    });
    var auth = firebase.auth();
    var box = document.getElementById('fbResult');
    function msg(html, ok) { box.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'danger') + ' py-2 mb-0">' + html + '</div>'; }

    var verifier = new firebase.auth.RecaptchaVerifier('recaptcha', { size: 'normal' });
    verifier.render();
    var confirmation = null;

    document.getElementById('fbSend').addEventListener('click', function () {
        var phone = document.getElementById('fbPhone').value.trim();
        msg('Sending OTP to ' + phone + '…', true);
        auth.signInWithPhoneNumber(phone, verifier).then(function (c) {
            confirmation = c;
            document.getElementById('fbCodeWrap').classList.remove('d-none');
            msg('OTP sent to ' + phone + '. Enter the code below.', true);
        }).catch(function (e) {
            msg('Send failed: ' + (e && e.message ? e.message : e), false);
        });
    });

    document.getElementById('fbVerify').addEventListener('click', function () {
        if (!confirmation) { return; }
        var code = document.getElementById('fbCode').value.trim();
        confirmation.confirm(code).then(function (res) {
            return res.user.getIdToken();
        }).then(function (idToken) {
            return fetch(<?= json_encode(site_url('admin/firebase-otp-verify')) ?>, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: <?= json_encode(csrf_token()) ?> + '=' + encodeURIComponent(<?= json_encode(csrf_hash()) ?>)
                    + '&id_token=' + encodeURIComponent(idToken)
            });
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res.ok) {
                msg('✅ Verified by the server. Phone <b>' + res.phone + '</b> (uid ' + res.uid + '). Firebase OTP works end-to-end.', true);
            } else {
                msg('Code accepted by Firebase, but ' + (res.message || 'server verification failed') + '.', false);
            }
        }).catch(function (e) {
            msg('Invalid or expired code: ' + (e && e.message ? e.message : e), false);
        });
    });
})();
</script>
<?php endif; ?>
<?= $this->endSection() ?>
