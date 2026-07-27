<?php
/**
 * Firebase phone-OTP block for a registration flow, parameterised by route prefix.
 *
 * Expects:
 *   $fb          array  Firebase web config (apiKey, authDomain, projectId, …)
 *   $reg         array  the in-progress registration (needs 'mobile')
 *   $resendWait  array  ['mobile' => int] server-seeded countdown
 *   $routes      array  ['ticket' => uri, 'verify' => uri]  — the two POST endpoints
 *
 * Extracted so the manufacturer registration view does not duplicate ~90 lines of
 * Firebase wiring. app/Views/auth/register.php still carries its own inline copy: it is
 * the live vendor flow and was deliberately left untouched by the manufacturer work.
 * If that copy is ever DRY'd up, this partial is where it should land.
 */
$fbReady = ($fb['apiKey'] ?? '') !== '' && ($fb['projectId'] ?? '') !== '';
?>
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
        if (code === 'auth/invalid-phone-number')  { return 'That mobile number is not valid.'; }
        if (code === 'auth/too-many-requests')     { return 'Firebase is rate-limiting this number. Wait a few minutes.'; }
        if (code === 'auth/quota-exceeded')        { return 'The SMS quota for this project is exhausted. Contact support.'; }
        if (code === 'auth/captcha-check-failed')  { return 'reCAPTCHA failed — this domain may not be authorised in Firebase.'; }
        if (code === 'auth/unauthorized-domain')   { return 'This domain is not authorised in the Firebase console.'; }
        if (code === 'auth/operation-not-allowed') { return 'Phone sign-in is disabled for this Firebase project.'; }
        return 'Send failed' + (code ? ' (' + code + ')' : '') + ': ' + ((e && e.message) ? e.message : e);
    }

    var verifier = new firebase.auth.RecaptchaVerifier('otpRecaptcha', { size: 'normal' });
    verifier.render();
    var confirmation = null;

    // Every SMS — first send and each resend — asks the server for a ticket first, so
    // pacing and the per-number cap are enforced where the user cannot edit them.
    function sendOtp(btn) {
        msg('Checking…', true);
        return post(<?= json_encode(site_url($routes['ticket'])) ?>).then(function (t) {
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
            return post(<?= json_encode(site_url($routes['verify'])) ?>, '&id_token=' + encodeURIComponent(idToken));
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
