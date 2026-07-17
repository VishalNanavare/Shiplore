<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<style>
.pos-wrap{display:grid;grid-template-columns:1fr 360px;gap:1rem}
.pos-cartrow td{vertical-align:middle}
.pos-results{position:absolute;z-index:30;left:0;right:0;max-height:320px;overflow:auto;background:#fff;border:1px solid #e7eaf3;border-radius:.4rem;box-shadow:0 8px 24px rgba(0,0,0,.08)}
.pos-results .item{padding:.45rem .6rem;cursor:pointer;border-bottom:1px solid #f2f4f9}.pos-results .item:hover,.pos-results .item.active{background:#eef4ff}
.pos-pay-btn.active{box-shadow:0 0 0 2px #0d6efd inset}
@media(max-width:992px){.pos-wrap{grid-template-columns:1fr}}
</style>

<div id="pos" data-shop="<?= esc($shopId, 'attr') ?>"
     data-search="<?= site_url('vendor/pos/search') ?>" data-sale="<?= site_url('vendor/pos/sale') ?>"
     data-receipt="<?= site_url('vendor/pos/receipt') ?>">
    <?= csrf_field() ?>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h1 class="h5 mb-0"><i class="bi bi-shop me-1"></i>Point of Sale</h1>
        <form method="get" class="d-flex align-items-center gap-2">
            <label class="small text-secondary">Store</label>
            <select name="shop_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                <?php foreach ($shops as $s): ?><option value="<?= esc($s['id'], 'attr') ?>" <?= (int) $s['id'] === (int) $shopId ? 'selected' : '' ?>><?= esc($s['name']) ?></option><?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if (empty($shops)): ?>
        <div class="alert alert-warning">Create a store first — POS bills against a specific store.</div>
    <?php else: ?>
    <div class="d-flex gap-2 mb-3">
        <div class="position-relative flex-fill">
            <input id="posSearch" class="form-control form-control-lg" placeholder="Scan barcode or search name / SKU…  (F2)" autocomplete="off" autofocus>
            <div id="posResults" class="pos-results d-none"></div>
        </div>
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#tempModal" title="Add a one-off item not in the catalogue"><i class="bi bi-plus-square me-1"></i>Temp item</button>
        <a href="<?= site_url('vendor/pos/credits') ?>" class="btn btn-outline-secondary" title="Outstanding udhaar"><i class="bi bi-cash-coin"></i></a>
        <a href="<?= site_url('vendor/pos/returns') ?>" class="btn btn-outline-secondary" title="Returns / exchange"><i class="bi bi-arrow-return-left"></i></a>
        <a href="<?= site_url('vendor/pos/credit-note') ?>" class="btn btn-outline-secondary" title="Walk-in credit note"><i class="bi bi-receipt"></i></a>
        <a href="<?= site_url('vendor/pos/reports') ?>" class="btn btn-outline-secondary" title="POS reports"><i class="bi bi-graph-up"></i></a>
        <a href="<?= site_url('vendor/pos/settings') ?>" class="btn btn-outline-secondary" title="Bill settings"><i class="bi bi-gear"></i></a>
    </div>

    <div class="modal fade" id="tempModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content">
        <div class="modal-header py-2"><h6 class="modal-title">Temporary item</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <input id="tempName" class="form-control form-control-sm mb-2" placeholder="Item name">
            <div class="row g-2">
                <div class="col"><input id="tempPrice" type="number" min="0" step="0.01" class="form-control form-control-sm" placeholder="Price (incl. tax)"></div>
                <div class="col"><input id="tempQty" type="number" min="1" step="1" value="1" class="form-control form-control-sm" placeholder="Qty"></div>
            </div>
            <input id="tempTax" type="number" min="0" step="0.01" value="0" class="form-control form-control-sm mt-2" placeholder="GST % (0 if none)">
        </div>
        <div class="modal-footer py-2"><button type="button" id="tempAdd" class="btn btn-sm btn-primary" data-bs-dismiss="modal">Add to cart</button></div>
    </div></div></div>

    <div class="pos-wrap">
        <!-- CART -->
        <div class="card"><div class="table-responsive" style="min-height:300px">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Item</th><th style="width:130px" class="text-center">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th><th></th></tr></thead>
                <tbody id="posCart"><tr id="posEmpty"><td colspan="5" class="text-center text-secondary py-5">Scan or search to add items.</td></tr></tbody>
            </table>
        </div></div>

        <!-- TOTALS + PAYMENT -->
        <div>
            <div class="card mb-2"><div class="card-body py-2">
                <div class="d-flex justify-content-between small"><span class="text-secondary">Subtotal</span><span id="posSub">₹0.00</span></div>
                <div class="d-flex justify-content-between small align-items-center"><span class="text-secondary">Discount (F6)</span><input id="posDisc" type="number" min="0" step="0.01" class="form-control form-control-sm text-end" style="width:110px" value="0" <?= empty($can['discount']) ? 'readonly title="No discount permission"' : '' ?>></div>
                <div class="d-flex justify-content-between small"><span class="text-secondary">GST</span><span id="posGst">₹0.00</span></div>
                <div class="d-flex justify-content-between small"><span class="text-secondary">Round off</span><span id="posRound">₹0.00</span></div>
                <hr class="my-2">
                <div class="d-flex justify-content-between fw-semibold fs-5"><span>TOTAL</span><span id="posTotal" class="text-primary">₹0.00</span></div>
            </div></div>

            <div class="card"><div class="card-body py-2">
                <div class="d-flex gap-1 mb-2" id="posTenders">
                    <?php foreach (['cash' => 'Cash', 'upi' => 'UPI', 'card' => 'Card', 'wallet' => 'Wallet'] as $t => $l): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary flex-fill pos-pay-btn" data-tender="<?= $t ?>"><?= $l ?></button>
                    <?php endforeach; ?>
                </div>
                <div id="posPayRows"></div>
                <div class="d-flex justify-content-between small text-secondary mt-1"><span>Paid <span id="posPaid">₹0.00</span></span><span>Change <span id="posChange">₹0.00</span></span></div>
                <?php if (! empty($can['credit'])): ?><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" id="posCredit"><label class="form-check-label small" for="posCredit">Credit / Udhaar (pay later)</label></div><?php else: ?><input type="hidden" id="posCredit"><?php endif; ?>
                <div id="posCreditFields" class="d-none mt-1">
                    <input id="custName" class="form-control form-control-sm mb-1" placeholder="Customer name *">
                    <input id="custMobile" class="form-control form-control-sm mb-1" placeholder="Mobile number *">
                    <input id="custDue" type="date" class="form-control form-control-sm" title="Due date (optional)">
                    <div class="form-text">Unpaid balance ₹<span id="posUnpaid">0.00</span> will be recorded as udhaar.</div>
                </div>
                <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" id="posDeliver"><label class="form-check-label small" for="posDeliver">Deliver this order</label></div>
                <div id="posDeliverFields" class="d-none mt-1">
                    <input id="delName" class="form-control form-control-sm mb-1" placeholder="Recipient name *">
                    <input id="delPhone" class="form-control form-control-sm mb-1" placeholder="Phone">
                    <textarea id="delAddress" class="form-control form-control-sm mb-1" rows="2" placeholder="Delivery address *"></textarea>
                    <div class="row g-1">
                        <div class="col-6"><input id="delFee" type="number" min="0" step="0.01" class="form-control form-control-sm" placeholder="Delivery fee" value="0"></div>
                        <div class="col-6"><input id="delDate" type="date" class="form-control form-control-sm" title="Delivery date"></div>
                    </div>
                    <div class="form-text">Adds the fee to the bill and creates a delivery to assign a rider.</div>
                </div>
                <button id="posSave" class="btn btn-primary w-100 mt-2" disabled><i class="bi bi-check2-circle me-1"></i>Save &amp; Print (F12)</button>
                <button id="posClear" class="btn btn-link btn-sm w-100 text-secondary">Clear cart</button>
            </div></div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('js/pos-billing.js') ?>"></script>
<?= $this->endSection() ?>
