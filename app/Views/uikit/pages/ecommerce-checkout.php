<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between mb-3" id="coSteps">
            <?php foreach (['Cart','Address','Payment'] as $i=>$s): ?>
                <div class="text-center flex-fill"><span class="rounded-circle d-grid mx-auto mb-1 <?= $i===0?'bg-primary text-white':'bg-light text-secondary' ?>" style="width:36px;height:36px;place-items:center;font-weight:600"><?= $i+1 ?></span><div class="small fw-medium"><?= $s ?></div></div>
            <?php endforeach; ?>
        </div>

        <div class="card uk-card mb-3"><div class="card-body">
            <h2 class="uk-section-title mb-3">Cart items</h2>
            <?php foreach ([['Wireless Earbuds','box-seam','danger','₹1,999',1],['Running Shoes','bag','primary','₹2,499',2],['Coffee Beans 1kg','cup-hot','warning','₹899',1]] as $c): ?>
                <div class="d-flex align-items-center gap-3 py-2 border-bottom">
                    <span class="rounded bg-light d-grid" style="width:52px;height:52px;place-items:center"><i class="bi bi-<?= $c[1] ?> text-<?= $c[2] ?>" style="font-size:1.4rem"></i></span>
                    <div class="flex-grow-1"><div class="fw-medium small"><?= $c[0] ?></div><div class="text-secondary small">Qty: <?= $c[4] ?></div></div>
                    <div class="fw-semibold"><?= $c[3] ?></div>
                    <button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
                </div>
            <?php endforeach; ?>
        </div></div>

        <div class="card uk-card"><div class="card-body">
            <h2 class="uk-section-title mb-3">Shipping address</h2>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Full name</label><input class="form-control"></div>
                <div class="col-md-6"><label class="form-label">Mobile</label><input class="form-control"></div>
                <div class="col-12"><label class="form-label">Address</label><input class="form-control"></div>
                <div class="col-md-5"><label class="form-label">City</label><input class="form-control"></div>
                <div class="col-md-4"><label class="form-label">State</label><select class="form-select"><option>MH</option><option>KA</option></select></div>
                <div class="col-md-3"><label class="form-label">PIN</label><input class="form-control"></div>
            </div>
        </div></div>
    </div>

    <div class="col-lg-4">
        <div class="card uk-card mb-3"><div class="card-body">
            <h2 class="uk-section-title mb-3">Order summary</h2>
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">Subtotal (4 items)</span><span>₹5,397</span></div>
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">Shipping</span><span class="text-success">Free</span></div>
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">GST (18%)</span><span>₹971</span></div>
            <div class="input-group input-group-sm my-2"><input class="form-control" placeholder="Promo code"><button class="btn btn-outline-secondary">Apply</button></div>
            <hr class="my-2"><div class="d-flex justify-content-between fw-semibold"><span>Total</span><span class="text-primary">₹6,368</span></div>
        </div></div>
        <div class="card uk-card mb-3"><div class="card-body">
            <h2 class="uk-section-title mb-3">Payment</h2>
            <?php foreach (['UPI'=>'phone','Card'=>'credit-card','Net Banking'=>'bank','Cash on Delivery'=>'cash'] as $m=>$ic): ?>
                <div class="form-check"><input class="form-check-input" type="radio" name="pay" <?= $m==='UPI'?'checked':'' ?>><label class="form-check-label small"><i class="bi bi-<?= $ic ?> me-1"></i><?= $m ?></label></div>
            <?php endforeach; ?>
        </div></div>
        <button class="btn btn-primary w-100"><i class="bi bi-lock me-1"></i>Place Order</button>
    </div>
</div>
<?= $this->endSection() ?>
