<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Star ratings — static display and an interactive picker (no plugin).</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Display</h2>
        <?php foreach ([5=>'Excellent',4=>'Good',3=>'Average',2=>'Poor',1=>'Bad'] as $n=>$lbl): ?>
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="uk-rating"><?php for($i=1;$i<=5;$i++) echo '<i class="bi bi-star'.($i<=$n?'-fill':'').'"></i>'; ?></span>
                <span class="text-secondary small"><?= $lbl ?></span>
            </div>
        <?php endforeach; ?>
        <div class="mt-3"><span class="uk-rating"><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-half"></i><i class="bi bi-star"></i></span> <span class="small text-secondary">3.5 / 5 · 1,204 reviews</span></div>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Interactive</h2>
        <div class="uk-rating fs-3" id="ukStars" style="cursor:pointer">
            <?php for($i=1;$i<=5;$i++): ?><i class="bi bi-star" data-v="<?= $i ?>"></i><?php endfor; ?>
        </div>
        <div class="mt-2">You rated: <strong id="ukStarVal">0</strong> / 5</div>
        <hr>
        <h2 class="uk-section-title mb-2">Sizes</h2>
        <div class="uk-rating">★★★★☆</div>
        <div class="uk-rating fs-5">★★★★☆</div>
        <div class="uk-rating fs-3">★★★★☆</div>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var wrap = document.getElementById('ukStars'), val = document.getElementById('ukStarVal'), current = 0;
    var stars = wrap.querySelectorAll('i');
    function paint(n) { stars.forEach(function (s) { s.className = 'bi bi-star' + (+s.dataset.v <= n ? '-fill' : ''); }); }
    stars.forEach(function (s) {
        s.addEventListener('mouseenter', function () { paint(+s.dataset.v); });
        s.addEventListener('click', function () { current = +s.dataset.v; val.textContent = current; });
    });
    wrap.addEventListener('mouseleave', function () { paint(current); });
})();
</script>
<?= $this->endSection() ?>
