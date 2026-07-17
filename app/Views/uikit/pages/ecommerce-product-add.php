<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<form class="row g-3">
    <div class="col-lg-8">
        <div class="card uk-card mb-3"><div class="card-body">
            <h2 class="uk-section-title mb-3">Product information</h2>
            <div class="mb-3"><label class="form-label">Name</label><input class="form-control" placeholder="e.g. Wireless Earbuds"></div>
            <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" rows="4" placeholder="Describe the product…"></textarea></div>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">SKU</label><input class="form-control" placeholder="SKU-0001"></div>
                <div class="col-md-6"><label class="form-label">Barcode</label><input class="form-control" placeholder="EAN / UPC"></div>
            </div>
        </div></div>

        <div class="card uk-card mb-3"><div class="card-body">
            <h2 class="uk-section-title mb-3">Pricing & tax</h2>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">MRP</label><div class="input-group"><span class="input-group-text">₹</span><input class="form-control" placeholder="0.00"></div></div>
                <div class="col-md-4"><label class="form-label">Selling price</label><div class="input-group"><span class="input-group-text">₹</span><input class="form-control" placeholder="0.00"></div></div>
                <div class="col-md-4"><label class="form-label">GST slab</label><select class="form-select"><option>0%</option><option>5%</option><option selected>18%</option><option>28%</option></select></div>
                <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="incl" checked><label class="form-check-label" for="incl">Price is GST-inclusive</label></div></div>
            </div>
        </div></div>

        <div class="card uk-card"><div class="card-body">
            <h2 class="uk-section-title mb-3">Inventory</h2>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Stock qty</label><input type="number" class="form-control" value="0"></div>
                <div class="col-md-4"><label class="form-label">Low-stock alert</label><input type="number" class="form-control" value="5"></div>
                <div class="col-md-4"><label class="form-label">Warehouse</label><select class="form-select"><option>BLR-1</option><option>MUM-2</option></select></div>
            </div>
        </div></div>
    </div>

    <div class="col-lg-4">
        <div class="card uk-card mb-3"><div class="card-body">
            <h2 class="uk-section-title mb-3">Organize</h2>
            <div class="mb-3"><label class="form-label">Category</label><select class="form-select"><option>Electronics</option><option>Apparel</option><option>Grocery</option></select></div>
            <div class="mb-3"><label class="form-label">Brand</label><select class="form-select"><option>Generic</option><option>Acme</option></select></div>
            <div class="mb-0"><label class="form-label">Status</label><select class="form-select"><option>Draft</option><option>Published</option></select></div>
        </div></div>
        <div class="card uk-card mb-3"><div class="card-body">
            <h2 class="uk-section-title mb-3">Media</h2>
            <div class="border rounded text-center p-4 text-secondary" style="border-style:dashed!important">
                <i class="bi bi-cloud-arrow-up fs-2 d-block mb-2"></i><div class="small">Drag images here or click to upload</div>
                <input type="file" class="form-control mt-2" multiple>
            </div>
        </div></div>
        <button class="btn btn-primary w-100 mb-2" type="button"><i class="bi bi-check-lg me-1"></i>Save product</button>
        <button class="btn btn-light w-100" type="button">Save as draft</button>
    </div>
</form>
<?= $this->endSection() ?>
