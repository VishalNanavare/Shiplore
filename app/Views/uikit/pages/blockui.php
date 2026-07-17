<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Block a region with a loading overlay during async work (no plugin — a tiny CSS overlay).</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100 position-relative" id="blkCard"><div class="card-body">
        <h2 class="uk-section-title mb-3">Block a card</h2>
        <p class="text-secondary small">Click to overlay this card with a spinner for 2 seconds.</p>
        <button class="btn btn-primary" id="blkBtn"><i class="bi bi-hourglass-split me-1"></i>Block card</button>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Block the page</h2>
        <p class="text-secondary small">Full-screen overlay — useful during a form submit.</p>
        <button class="btn btn-outline-primary" id="blkPage"><i class="bi bi-fullscreen me-1"></i>Block page</button>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    function overlay(parent, full) {
        var o = document.createElement('div');
        o.style.cssText = 'position:absolute;inset:0;display:grid;place-items:center;background:rgba(255,255,255,.7);z-index:10;border-radius:inherit'
            + (full ? ';position:fixed;background:rgba(30,34,51,.45)' : '');
        o.innerHTML = '<div class="spinner-border ' + (full ? 'text-light' : 'text-primary') + '"></div>';
        parent.appendChild(o);
        setTimeout(function () { o.remove(); }, 2000);
    }
    document.getElementById('blkBtn').addEventListener('click', function () { overlay(document.getElementById('blkCard'), false); });
    document.getElementById('blkPage').addEventListener('click', function () { overlay(document.body, true); });
})();
</script>
<?= $this->endSection() ?>
