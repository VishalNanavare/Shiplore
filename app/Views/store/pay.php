<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<div class="row justify-content-center"><div class="col-md-6 col-lg-5">
    <div class="card st-auth-card"><div class="card-body p-4 text-center">
        <div class="st-auth-badge mx-auto mb-2"><i class="bi bi-credit-card-2-front"></i></div>
        <h1 class="h4 mb-1">Complete your payment</h1>
        <p class="text-secondary small">Order <?= esc($orderNo) ?></p>
        <div class="h2 fw-bold my-3">₹<?= esc(number_format((float) $amount, 2)) ?></div>

        <p class="text-secondary small mb-3">You will be redirected to PayU's secure payment page.</p>

        <div id="payLoader" class="text-center py-2">
            <div class="spinner-border text-primary mb-2" role="status"><span class="visually-hidden">Redirecting…</span></div>
            <p class="small text-secondary">Redirecting to payment gateway…</p>
        </div>

        <a href="<?= site_url('store/order/' . $orderNo) ?>" class="btn btn-link w-100 mt-2 text-secondary small">Pay later / view order</a>

        <form id="payuForm" method="POST" action="<?= esc($payuUrl, 'attr') ?>" class="d-none">
            <input type="hidden" name="key"         value="<?= esc($payuKey, 'attr') ?>">
            <input type="hidden" name="txnid"       value="<?= esc($txnid, 'attr') ?>">
            <input type="hidden" name="amount"      value="<?= esc($amount, 'attr') ?>">
            <input type="hidden" name="productinfo" value="<?= esc($productinfo, 'attr') ?>">
            <input type="hidden" name="firstname"   value="<?= esc($firstname, 'attr') ?>">
            <input type="hidden" name="email"       value="<?= esc($email, 'attr') ?>">
            <input type="hidden" name="phone"       value="<?= esc($phone, 'attr') ?>">
            <input type="hidden" name="surl"        value="<?= esc($surl, 'attr') ?>">
            <input type="hidden" name="furl"        value="<?= esc($furl, 'attr') ?>">
            <input type="hidden" name="hash"        value="<?= esc($hash, 'attr') ?>">
        </form>
    </div></div>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    // Auto-submit to PayU on load; small delay lets the page render the spinner
    window.addEventListener('DOMContentLoaded', function () {
        setTimeout(function () {
            document.getElementById('payuForm').submit();
        }, 500);
    });
})();
</script>
<?= $this->endSection() ?>
