<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Dynamically add and remove repeating field groups (line items, contacts, attributes).</p>

<div class="card uk-card"><div class="card-body">
    <h2 class="uk-section-title mb-3">Order line items</h2>
    <div id="ukRepeater">
        <div class="row g-2 align-items-end mb-2 uk-rep-row">
            <div class="col-md-5"><label class="form-label small">Item</label><input class="form-control" placeholder="Product name"></div>
            <div class="col-md-3"><label class="form-label small">Qty</label><input type="number" class="form-control" value="1"></div>
            <div class="col-md-3"><label class="form-label small">Price</label><div class="input-group"><span class="input-group-text">₹</span><input class="form-control" value="0"></div></div>
            <div class="col-md-1"><button type="button" class="btn btn-light text-danger w-100 uk-rep-del"><i class="bi bi-trash"></i></button></div>
        </div>
    </div>
    <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="ukRepAdd"><i class="bi bi-plus-lg me-1"></i>Add item</button>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var wrap = document.getElementById('ukRepeater');
    document.getElementById('ukRepAdd').addEventListener('click', function () {
        var row = wrap.querySelector('.uk-rep-row').cloneNode(true);
        row.querySelectorAll('input').forEach(function (i) { i.value = i.type === 'number' ? '1' : ''; });
        wrap.appendChild(row);
    });
    wrap.addEventListener('click', function (e) {
        var b = e.target.closest('.uk-rep-del');
        if (b && wrap.querySelectorAll('.uk-rep-row').length > 1) b.closest('.uk-rep-row').remove();
    });
})();
</script>
<?= $this->endSection() ?>
