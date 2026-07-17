<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<form class="row g-3">
    <div class="col-lg-8"><div class="card uk-card"><div class="card-body p-4">
        <div class="d-flex justify-content-between flex-wrap mb-4">
            <div class="d-flex align-items-center gap-2"><img src="<?= asset('images/logo.svg') ?>" width="28" height="28"><span class="h5 mb-0">Shiplore</span></div>
            <div class="text-end"><div class="input-group input-group-sm"><span class="input-group-text">#</span><input class="form-control" value="INV-2026-07" style="width:130px"></div></div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-sm-6"><label class="form-label small text-uppercase text-secondary">Bill To</label><input class="form-control mb-2" placeholder="Client name"><textarea class="form-control" rows="2" placeholder="Address"></textarea></div>
            <div class="col-sm-6"><label class="form-label small">Issue date</label><input type="date" class="form-control mb-2"><label class="form-label small">Due date</label><input type="date" class="form-control"></div>
        </div>
        <table class="table align-middle" id="invItems">
            <thead class="table-light"><tr><th>Item</th><th style="width:90px">Qty</th><th style="width:130px">Rate</th><th style="width:130px" class="text-end">Amount</th><th></th></tr></thead>
            <tbody>
                <tr><td><input class="form-control form-control-sm" value="Platform subscription"></td><td><input type="number" class="form-control form-control-sm" value="1"></td><td><input class="form-control form-control-sm" value="21186"></td><td class="text-end">₹21,186</td><td><button type="button" class="btn btn-sm btn-light text-danger inv-del"><i class="bi bi-x-lg"></i></button></td></tr>
                <tr><td><input class="form-control form-control-sm" value="Transaction fees"></td><td><input type="number" class="form-control form-control-sm" value="1"></td><td><input class="form-control form-control-sm" value="3200"></td><td class="text-end">₹3,200</td><td><button type="button" class="btn btn-sm btn-light text-danger inv-del"><i class="bi bi-x-lg"></i></button></td></tr>
            </tbody>
        </table>
        <button type="button" class="btn btn-sm btn-outline-primary" id="invAdd"><i class="bi bi-plus-lg me-1"></i>Add item</button>
    </div></div></div>
    <div class="col-lg-4">
        <div class="card uk-card mb-3"><div class="card-body">
            <h2 class="uk-section-title mb-3">Summary</h2>
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">Subtotal</span><span>₹24,386</span></div>
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">GST (18%)</span><span>₹4,389</span></div>
            <hr class="my-2"><div class="d-flex justify-content-between fw-semibold"><span>Total</span><span class="text-primary">₹28,775</span></div>
        </div></div>
        <div class="card uk-card mb-3"><div class="card-body">
            <label class="form-label">Status</label><select class="form-select mb-2"><option>Draft</option><option>Sent</option><option>Paid</option></select>
            <label class="form-label">Payment terms</label><select class="form-select"><option>Net 15</option><option>Net 30</option></select>
        </div></div>
        <button class="btn btn-primary w-100 mb-2" type="button"><i class="bi bi-send me-1"></i>Save & Send</button>
        <a href="<?= site_url('ui-kit/invoice') ?>" class="btn btn-light w-100">Preview</a>
    </div>
</form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var body = document.querySelector('#invItems tbody');
    document.getElementById('invAdd').addEventListener('click', function () {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><input class="form-control form-control-sm" placeholder="Description"></td>'
            + '<td><input type="number" class="form-control form-control-sm" value="1"></td>'
            + '<td><input class="form-control form-control-sm" value="0"></td>'
            + '<td class="text-end">₹0</td>'
            + '<td><button type="button" class="btn btn-sm btn-light text-danger inv-del"><i class="bi bi-x-lg"></i></button></td>';
        body.appendChild(tr);
    });
    body.addEventListener('click', function (e) { var b = e.target.closest('.inv-del'); if (b) b.closest('tr').remove(); });
})();
</script>
<?= $this->endSection() ?>
