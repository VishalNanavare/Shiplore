<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="card uk-card mb-3"><div class="card-body p-4">
    <div class="row g-4">
        <!-- Gallery -->
        <div class="col-md-5">
            <div class="uk-gallery-main mb-2" id="galMain"><i class="bi bi-box-seam"></i></div>
            <div class="d-flex gap-2">
                <?php foreach (['box-seam','image','camera','badge-3d'] as $i=>$ic): ?>
                    <div class="uk-thumb <?= $i===0?'active':'' ?>"><i class="bi bi-<?= $ic ?>"></i></div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- Info -->
        <div class="col-md-7">
            <span class="badge bg-success-subtle text-success mb-2">In stock</span>
            <h1 class="h4">Wireless Noise-Cancelling Earbuds</h1>
            <div class="uk-rating mb-2">★★★★★ <span class="text-secondary small">4.6 · 1,204 reviews</span></div>
            <div class="d-flex align-items-baseline gap-2 mb-3">
                <span class="h3 mb-0 text-primary">₹1,999</span>
                <span class="text-secondary text-decoration-line-through">₹3,499</span>
                <span class="badge bg-danger">43% OFF</span>
            </div>
            <p class="text-secondary">Premium sound with active noise cancellation, 32-hour battery, and IPX5 water resistance. Inclusive of all taxes.</p>

            <div class="mb-3"><label class="form-label small fw-medium">Color</label><br>
                <div class="btn-group" role="group">
                    <input type="radio" class="btn-check" name="clr" id="c-blk" checked><label class="btn btn-sm btn-outline-secondary" for="c-blk">Black</label>
                    <input type="radio" class="btn-check" name="clr" id="c-wht"><label class="btn btn-sm btn-outline-secondary" for="c-wht">White</label>
                    <input type="radio" class="btn-check" name="clr" id="c-blu"><label class="btn btn-sm btn-outline-secondary" for="c-blu">Blue</label>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-end mb-3">
                <div style="width:110px"><label class="form-label small fw-medium">Qty</label><input type="number" class="form-control" value="1" min="1"></div>
                <button class="btn btn-primary"><i class="bi bi-cart-plus me-1"></i>Add to Cart</button>
                <button class="btn btn-outline-danger"><i class="bi bi-heart"></i></button>
            </div>
            <ul class="list-inline small text-secondary mb-0">
                <li class="list-inline-item me-3"><i class="bi bi-truck me-1"></i>Free delivery</li>
                <li class="list-inline-item me-3"><i class="bi bi-arrow-counterclockwise me-1"></i>7-day returns</li>
                <li class="list-inline-item"><i class="bi bi-shield-check me-1"></i>1-yr warranty</li>
            </ul>
        </div>
    </div>
</div></div>

<div class="card uk-card"><div class="card-body">
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pv-desc">Description</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pv-spec">Specifications</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pv-rev">Reviews</button></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="pv-desc"><p class="mb-0">Immersive audio engineered for everyday life. Adaptive ANC tunes out the world while transparency mode keeps you aware. Touch controls, multipoint pairing and a compact charging case.</p></div>
        <div class="tab-pane fade" id="pv-spec">
            <table class="table table-sm mb-0"><tbody>
                <tr><th style="width:200px">Battery</th><td>8h + 24h (case)</td></tr>
                <tr><th>Connectivity</th><td>Bluetooth 5.3</td></tr>
                <tr><th>Water resistance</th><td>IPX5</td></tr>
                <tr><th>Weight</th><td>4.8 g / bud</td></tr>
            </tbody></table>
        </div>
        <div class="tab-pane fade" id="pv-rev">
            <div class="d-flex gap-2 mb-2"><span class="rounded-circle bg-primary-subtle text-primary d-grid" style="width:36px;height:36px;place-items:center;font-weight:600">AK</span>
                <div><div class="fw-medium small">Aman K. <span class="uk-rating">★★★★★</span></div><div class="text-secondary small">Great sound and battery life. Worth it!</div></div></div>
            <div class="d-flex gap-2"><span class="rounded-circle bg-success-subtle text-success d-grid" style="width:36px;height:36px;place-items:center;font-weight:600">RI</span>
                <div><div class="fw-medium small">Riya I. <span class="uk-rating">★★★★</span>☆</div><div class="text-secondary small">Comfortable, ANC could be stronger.</div></div></div>
        </div>
    </div>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.querySelectorAll('.uk-thumb').forEach(function (t) {
    t.addEventListener('click', function () {
        document.querySelectorAll('.uk-thumb').forEach(function (x) { x.classList.remove('active'); });
        t.classList.add('active');
        document.getElementById('galMain').innerHTML = t.innerHTML;
    });
});
</script>
<?= $this->endSection() ?>
