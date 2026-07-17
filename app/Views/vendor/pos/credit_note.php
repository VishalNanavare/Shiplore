<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="row g-3" id="cnApp" data-search="<?= site_url('vendor/pos/search') ?>" data-shop="<?= (int) $shopId ?>">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-receipt me-1"></i>New Credit Note (walk-in refund)</span>
                <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('vendor/pos/returns') ?>">Against a bill →</a>
            </div>
            <form method="post" action="<?= site_url('vendor/pos/credit-note') ?>" class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="shop_id" value="<?= (int) $shopId ?>">
                <div class="row g-2 mb-3">
                    <div class="col"><label class="form-label small">Customer name</label><input name="customer_name" class="form-control form-control-sm"></div>
                    <div class="col"><label class="form-label small">Mobile</label><input name="customer_phone" class="form-control form-control-sm"></div>
                </div>

                <label class="form-label small">Search a product to add it as a returned line</label>
                <div class="position-relative mb-2">
                    <input id="cnSearch" class="form-control" placeholder="Type product name, SKU or barcode…" autocomplete="off">
                    <div id="cnResults" class="list-group position-absolute w-100 shadow-sm d-none" style="z-index:30;max-height:280px;overflow:auto"></div>
                </div>

                <table class="table table-sm align-middle">
                    <thead class="table-light"><tr><th>Returned item</th><th style="width:80px">Qty</th><th style="width:100px">Price</th><th style="width:70px">GST%</th><th style="width:36px"></th></tr></thead>
                    <tbody id="cnLines">
                        <tr class="text-secondary" id="cnEmpty"><td colspan="5" class="text-center small py-2">Search above, or add a manual line.</td></tr>
                    </tbody>
                </table>
                <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="cnAddManual"><i class="bi bi-plus-lg me-1"></i>Manual line</button>

                <div class="row g-2 align-items-end">
                    <div class="col"><label class="form-label small">Reason</label><input name="reason" class="form-control form-control-sm" placeholder="Damaged / wrong item…"></div>
                    <div class="col-auto"><label class="form-label small">Refund mode</label>
                        <select name="refund_method" class="form-select form-select-sm"><option value="cash">Cash</option><option value="upi">UPI</option><option value="card">Card</option><option value="wallet">Wallet</option></select>
                    </div>
                    <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-receipt me-1"></i>Issue credit note</button></div>
                </div>
            </form>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold">Recent credit notes</div>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>CN No.</th><th>Against</th><th>Refund</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td class="small fw-semibold"><?= esc($r['credit_note_no'] ?? ('#' . $r['id'])) ?></td>
                        <td class="small text-secondary"><?= esc($r['server_invoice_no'] ?? ($r['customer_name'] ?? 'walk-in')) ?></td>
                        <td>₹<?= esc(number_format((float) $r['refund_amount'], 2)) ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= site_url('vendor/pos/credit-note/' . $r['id']) ?>"><i class="bi bi-printer"></i></a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recent)): ?><tr><td colspan="4" class="text-center text-secondary py-4">No credit notes yet.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    const app = document.getElementById('cnApp');
    if (!app) return;
    const searchUrl = app.dataset.search, shop = app.dataset.shop;
    const box = document.getElementById('cnSearch'), results = document.getElementById('cnResults');
    const lines = document.getElementById('cnLines'), empty = document.getElementById('cnEmpty');

    function addLine(title, variantId, price, tax) {
        if (empty) empty.remove();
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input name="line_title[]" class="form-control form-control-sm" value="' + (title || '').replace(/"/g, '&quot;') + '">' +
            '<input type="hidden" name="line_variant[]" value="' + (variantId || '') + '"></td>' +
            '<td><input name="line_qty[]" type="number" min="0" step="0.001" value="1" class="form-control form-control-sm"></td>' +
            '<td><input name="line_price[]" type="number" min="0" step="0.01" value="' + (price || '') + '" class="form-control form-control-sm"></td>' +
            '<td><input name="line_tax[]" type="number" min="0" step="0.01" value="' + (tax || 0) + '" class="form-control form-control-sm"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger cnDel">&times;</button></td>';
        lines.appendChild(tr);
        tr.querySelector('.cnDel').addEventListener('click', () => tr.remove());
    }

    document.getElementById('cnAddManual').addEventListener('click', () => addLine('', '', '', 0));

    let t = null;
    box.addEventListener('input', function () {
        clearTimeout(t);
        const q = box.value.trim();
        if (q.length < 2) { results.classList.add('d-none'); return; }
        t = setTimeout(async () => {
            try {
                const res = await fetch(searchUrl + '?shop_id=' + encodeURIComponent(shop) + '&q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                results.innerHTML = '';
                (data.items || []).forEach(it => {
                    const a = document.createElement('button');
                    a.type = 'button';
                    a.className = 'list-group-item list-group-item-action py-1';
                    a.innerHTML = '<span class="fw-semibold">' + (it.title || '') + '</span> <span class="text-secondary small">' + (it.sku || '') + ' · ₹' + (it.base_price || 0) + '</span>';
                    a.addEventListener('click', () => {
                        addLine(it.title, it.id, it.base_price, it.tax_rate || 0);
                        results.classList.add('d-none'); box.value = ''; box.focus();
                    });
                    results.appendChild(a);
                });
                results.classList.toggle('d-none', (data.items || []).length === 0);
            } catch (e) { results.classList.add('d-none'); }
        }, 220);
    });
    document.addEventListener('click', e => { if (!app.contains(e.target)) results.classList.add('d-none'); });
})();
</script>
<?= $this->endSection() ?>
