<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Copy to clipboard using the native <code class="uk-code">navigator.clipboard</code> API (no plugin).</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Copy a field</h2>
        <label class="form-label">API key</label>
        <div class="input-group">
            <input class="form-control" id="cbKey" value="sk_live_8f2a91c4e7b6d530" readonly>
            <button class="btn btn-outline-primary uk-copy" data-target="#cbKey"><i class="bi bi-clipboard"></i></button>
        </div>
        <label class="form-label mt-3">Referral link</label>
        <div class="input-group">
            <input class="form-control" id="cbLink" value="https://commercehub.io/r/VN-2026" readonly>
            <button class="btn btn-outline-primary uk-copy" data-target="#cbLink"><i class="bi bi-clipboard"></i></button>
        </div>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Copy a code block</h2>
        <pre class="bg-light border rounded p-3 small mb-2" id="cbCode">composer require commercehub/sdk
php spark commercehub:init</pre>
        <button class="btn btn-primary uk-copy" data-target="#cbCode"><i class="bi bi-clipboard me-1"></i>Copy snippet</button>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.querySelectorAll('.uk-copy').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var el = document.querySelector(btn.dataset.target);
        var text = el.value !== undefined ? el.value : el.innerText;
        navigator.clipboard.writeText(text).then(function () {
            if (window.toastr) toastr.success('Copied to clipboard');
            var old = btn.innerHTML; btn.innerHTML = '<i class="bi bi-check-lg"></i>';
            setTimeout(function () { btn.innerHTML = old; }, 1200);
        });
    });
});
</script>
<?= $this->endSection() ?>
