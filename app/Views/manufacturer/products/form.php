<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php
$isEdit  = ! empty($product);
$action  = $isEdit ? 'manufacturer/products/' . (int) $product['id'] . '/update' : 'manufacturer/products/store';
$making  = old('making_price', $isEdit ? ($product['making_price'] ?? '') : '');
$selling = old('base_price', $isEdit ? ($product['base_price'] ?? '') : '');
?>

<div class="card">
    <div class="card-header"><?= $isEdit ? 'Edit Product' : 'New Product' ?></div>
    <div class="card-body">
        <form method="post" action="<?= site_url($action) ?>" class="row g-3" id="mfgProductForm">
            <?= csrf_field() ?>

            <div class="col-md-8">
                <label class="form-label">Title <span class="text-danger">*</span></label>
                <input name="title" class="form-control" required
                       value="<?= esc(old('title', $isEdit ? ($product['title'] ?? '') : ''), 'attr') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Category <span class="text-danger">*</span></label>
                <select name="category_id" class="form-select" required <?= $isEdit ? '' : '' ?>>
                    <option value="">Choose category…</option>
                    <?php foreach (($categories ?? []) as $c): ?>
                        <option value="<?= esc((string) $c['id'], 'attr') ?>"
                            <?= (string) old('category_id', $isEdit ? (string) ($product['category_id'] ?? '') : '') === (string) $c['id'] ? 'selected' : '' ?>>
                            <?= esc($c['name'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3"><?= esc(old('description', $isEdit ? ($product['description'] ?? '') : '')) ?></textarea>
            </div>

            <?php
            /*
             * Manufacturer pricing. Vendors use MRP + selling price; manufacturers use
             * MAKING price (production cost) + selling price, and making must be strictly
             * below selling. The check below is a courtesy for the user — the real
             * enforcement is server-side in ManufacturerPricing, applied by the
             * controller, the repository and the autosave path alike.
             *
             * Making price is INTERNAL. It must never be shown to a buyer on msonline.
             */
            ?>
            <div class="col-12"><hr class="my-1"><h6 class="mb-0">Pricing</h6></div>

            <div class="col-md-4">
                <label class="form-label">Making price (₹) <span class="text-danger">*</span></label>
                <input name="making_price" id="mfgMaking" type="number" step="0.01" min="0" class="form-control"
                       required value="<?= esc((string) $making, 'attr') ?>">
                <div class="form-text">What it costs you to produce. Internal — buyers never see this.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Selling price (₹) <span class="text-danger">*</span></label>
                <input name="base_price" id="mfgSelling" type="number" step="0.01" min="0" class="form-control"
                       required value="<?= esc((string) $selling, 'attr') ?>">
                <div class="form-text">What a vendor or shop pays on msonline.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Margin</label>
                <div class="form-control-plaintext fs-5" id="mfgMargin">—</div>
            </div>
            <div class="col-12">
                <div class="alert alert-danger py-2 small d-none" id="mfgPriceWarn">
                    Making price must be less than the selling price.
                </div>
            </div>

            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create product' ?></button>
                <a class="btn btn-outline-secondary" href="<?= site_url('manufacturer/products') ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var making = document.getElementById('mfgMaking'),
        sell   = document.getElementById('mfgSelling'),
        out    = document.getElementById('mfgMargin'),
        warn   = document.getElementById('mfgPriceWarn'),
        form   = document.getElementById('mfgProductForm');
    if (!making || !sell) { return; }

    function bad() {
        var m = parseFloat(making.value), s = parseFloat(sell.value);
        return !isNaN(m) && !isNaN(s) && m >= s;
    }
    function refresh() {
        var m = parseFloat(making.value), s = parseFloat(sell.value);
        out.textContent = (!isNaN(m) && !isNaN(s) && s > 0) ? (((s - m) / s) * 100).toFixed(1) + '%' : '—';
        warn.classList.toggle('d-none', !bad());
    }
    making.addEventListener('input', refresh);
    sell.addEventListener('input', refresh);
    // Client-side only stops an obvious typo early; the server rejects it regardless.
    form.addEventListener('submit', function (e) { if (bad()) { e.preventDefault(); refresh(); } });
    refresh();
})();
</script>
<?= $this->endSection() ?>
