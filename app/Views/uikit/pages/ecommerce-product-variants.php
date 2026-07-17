<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Split a single product into variants by combining options (e.g. Size × Color). Each generated combination gets its own SKU, price and stock.</p>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card uk-card"><div class="card-body">
            <h2 class="uk-section-title mb-3">Options</h2>
            <div class="mb-3">
                <label class="form-label">Size</label>
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach (['S','M','L','XL'] as $s): ?><span class="badge bg-primary-subtle text-primary"><?= $s ?> <i class="bi bi-x"></i></span><?php endforeach; ?>
                </div>
                <input class="form-control form-control-sm mt-2" placeholder="Add size + Enter">
            </div>
            <div class="mb-0">
                <label class="form-label">Color</label>
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach (['Black','White','Blue'] as $c): ?><span class="badge bg-info-subtle text-info"><?= $c ?> <i class="bi bi-x"></i></span><?php endforeach; ?>
                </div>
                <input class="form-control form-control-sm mt-2" placeholder="Add color + Enter">
            </div>
            <hr>
            <button class="btn btn-primary w-100"><i class="bi bi-diagram-3 me-1"></i>Generate variants</button>
        </div></div>
    </div>

    <div class="col-lg-8">
        <div class="card uk-card"><div class="card-body p-0">
            <div class="d-flex justify-content-between align-items-center p-3 pb-2">
                <h2 class="uk-section-title mb-0">12 variants</h2>
                <div class="d-flex gap-2">
                    <input class="form-control form-control-sm" style="width:120px" placeholder="Bulk price ₹">
                    <button class="btn btn-sm btn-outline-secondary">Apply all</button>
                </div>
            </div>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead><tr><th><input type="checkbox" class="form-check-input"></th><th>Variant</th><th>SKU</th><th>Price</th><th>Stock</th><th></th></tr></thead>
                <tbody>
                <?php
                $sizes = ['S','M','L','XL']; $colors = ['Black','White','Blue']; $n = 1;
                foreach ($sizes as $s) {
                    foreach ($colors as $c) {
                        $sku = 'TS-' . strtoupper(substr($c,0,3)) . '-' . $s;
                        $stock = (7 * $n) % 40;
                        echo '<tr>'
                            . '<td><input type="checkbox" class="form-check-input"></td>'
                            . '<td><span class="badge bg-light text-dark border me-1">'.$s.'</span><span class="badge bg-light text-dark border">'.$c.'</span></td>'
                            . '<td><input class="form-control form-control-sm" style="width:130px" value="'.$sku.'"></td>'
                            . '<td><div class="input-group input-group-sm" style="width:120px"><span class="input-group-text">₹</span><input class="form-control" value="699"></div></td>'
                            . '<td><input type="number" class="form-control form-control-sm" style="width:80px" value="'.$stock.'"></td>'
                            . '<td><button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button></td>'
                            . '</tr>';
                        $n++;
                    }
                }
                ?>
                </tbody>
            </table></div>
            <div class="p-3 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save variants</button></div>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
