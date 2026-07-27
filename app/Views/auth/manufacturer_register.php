<?= $this->extend('layouts/auth') ?>

<?php $fbReady = ($fb['apiKey'] ?? '') !== '' && ($fb['projectId'] ?? '') !== ''; ?>
<?php $mobileVerified = ($reg['mobile_verified'] ?? false) === true; ?>

<?= $this->section('content') ?>
<div class="card auth-card shadow-lg" style="max-width:<?= $stage === 'verify' ? '460px' : '760px' ?>">
    <div class="card-body p-4 p-sm-5">
        <div class="text-center mb-4">
            <img src="<?= asset('images/logo.svg') ?>" alt="" width="46" height="46" class="mb-2">
            <h1 class="h4 auth-brand mb-1">Become a Manufacturer</h1>
            <p class="text-secondary small mb-0"><?= $stage === 'verify' ? 'Verify your mobile, email &amp; GSTIN' : 'Register your unit to supply vendors and shops' ?></p>
        </div>

        <?php if ($m = session('status')): ?><div class="alert alert-success py-2"><?= esc($m) ?></div><?php endif; ?>
        <?php if ($m = session('error')): ?><div class="alert alert-danger py-2"><?= esc($m) ?></div><?php endif; ?>
        <?php if ($m = session('dev_codes')): ?><div class="alert alert-info py-2 small"><?= esc($m) ?></div><?php endif; ?>

        <?php if ($stage === 'verify'): ?>
            <div class="alert alert-light border small mb-3">
                <div><strong><?= esc($reg['display_name'] ?? '') ?></strong> · <?= esc($reg['unit_name'] ?? '') ?></div>
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
            <form method="post" action="<?= site_url('manufacturer-register/complete') ?>" autocomplete="off">
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
                <form method="post" action="<?= site_url('manufacturer-register/resend/email') ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-link btn-sm p-0 js-countdown"
                            data-cooldown="<?= (int) ($resendWait['email'] ?? 0) ?>"
                            data-label="Resend email code">Resend email code</button>
                </form>
                <form method="post" action="<?= site_url('manufacturer-register/cancel') ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-link btn-sm p-0 text-secondary">Start over</button>
                </form>
            </div>
        <?php else: ?>
            <form method="post" action="<?= site_url('manufacturer-register/send-codes') ?>" autocomplete="off">
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
                    <div class="col-md-6"><label class="form-label">Unit Name</label><input name="unit_name" class="form-control" value="<?= esc(old('unit_name'), 'attr') ?>" placeholder="e.g. Bhiwandi Plant 1" required></div>
                    <?php
                    /*
                     * _factory_location, NOT _shop_location: no "Deliver to nearby area"
                     * checkbox and no delivery-radius input. Manufacturers do not deliver,
                     * and `mshops` has no column to store a radius in.
                     */
                    echo $this->include('partials/_factory_location', ['mapsKey' => $mapsKey, 'states' => $states]);
                    ?>
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
<?= $this->include('partials/_register_firebase_otp', [
    'fb'         => $fb,
    'reg'        => $reg,
    'resendWait' => $resendWait,
    'routes'     => ['ticket' => 'manufacturer-register/otp-ticket', 'verify' => 'manufacturer-register/verify-mobile'],
]) ?>
<?php endif; ?>
<?= $this->endSection() ?>
<?php endif; ?>
