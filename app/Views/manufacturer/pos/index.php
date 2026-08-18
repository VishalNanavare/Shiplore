<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Counter</h5>
    <?php if (! empty($unitOptions) && count($unitOptions) > 1): ?>
        <form method="get" class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 small text-secondary" for="unitPick">Selling from</label>
            <select class="form-select form-select-sm" id="unitPick" name="mshop_id" onchange="this.form.submit()">
                <?php foreach ($unitOptions as $uid => $uname): ?>
                    <option value="<?= (int) $uid ?>" <?= (int) $unitId === (int) $uid ? 'selected' : '' ?>><?= esc($uname) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<?php if (empty($canSell)): ?>
    <div class="alert alert-warning py-2">You can view the counter but not ring up sales.</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-body">
                <label class="form-label" for="posSearch">Find an item</label>
                <input class="form-control" id="posSearch" autocomplete="off"
                       placeholder="Scan a barcode or type a SKU / name" <?= empty($canSell) ? 'disabled' : '' ?>>
                <div id="posResults" class="list-group mt-2" style="max-height:260px;overflow:auto"></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header py-2"><strong>Cart</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Item</th><th style="width:110px">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th><th></th></tr></thead>
                    <tbody id="cartBody">
                        <tr id="cartEmpty"><td colspan="5" class="text-center text-secondary py-4">Nothing scanned yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header py-2"><strong>Payment</strong></div>
            <div class="card-body">
                <dl class="row mb-2">
                    <dt class="col-7 text-secondary small">Items</dt><dd class="col-5 text-end mb-1" id="sumCount">0</dd>
                    <dt class="col-7 text-secondary small">Subtotal</dt><dd class="col-5 text-end mb-1" id="sumSub">₹0.00</dd>
                    <dt class="col-7 fw-semibold">Total</dt><dd class="col-5 text-end fw-semibold" id="sumTotal">₹0.00</dd>
                </dl>
                <div class="form-text mb-3">Prices include GST. The exact tax split is computed on the server.</div>

                <div class="mb-2">
                    <label class="form-label" for="payMethod">Tender</label>
                    <select class="form-select form-select-sm" id="payMethod">
                        <option value="cash">Cash</option>
                        <option value="upi">UPI</option>
                        <option value="card">Card</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label" for="payAmount">Amount received</label>
                    <input class="form-control form-control-sm" id="payAmount" type="number" step="0.01" min="0">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="custName">Customer (optional)</label>
                    <input class="form-control form-control-sm" id="custName" placeholder="Name">
                </div>

                <button class="btn btn-primary w-100" id="posCheckout" <?= empty($canSell) ? 'disabled' : '' ?>>
                    Complete sale
                </button>
                <div class="small mt-2" id="posStatus" role="status" aria-live="polite"></div>
            </div>
        </div>

        <?php if (! empty($recent)): ?>
            <div class="card mt-3">
                <div class="card-header py-2"><strong>Recent</strong></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recent as $r): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                            <a class="small" href="<?= site_url('manufacturer/pos/receipt/' . (int) $r['id']) ?>" target="_blank" rel="noopener">
                                <?= esc((string) ($r['invoice_no'] ?? '')) ?>
                            </a>
                            <span class="small">₹<?= esc(number_format((float) ($r['grand_total'] ?? 0), 2)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
/*
 * Counter. The cart holds variant ids and quantities ONLY — every price, tax rate and
 * title is re-read server-side by resolveLines(), so a tampered cart cannot set a
 * price. The totals shown here are an estimate for the cashier; the server's figure is
 * what gets recorded and printed.
 */
(function () {
    var canSell = <?= empty($canSell) ? 'false' : 'true' ?>;
    if (!canSell) { return; }

    var unitId   = <?= (int) $unitId ?>;
    var csrfName = '<?= csrf_token() ?>';
    var csrf     = '<?= csrf_hash() ?>';
    var cart     = [];

    var searchUrl = '<?= site_url('manufacturer/pos/search') ?>';
    var saleUrl   = '<?= site_url('manufacturer/pos/sale') ?>';
    var receiptUrl = '<?= site_url('manufacturer/pos/receipt') ?>';

    var elSearch = document.getElementById('posSearch');
    var elResults = document.getElementById('posResults');
    var elBody   = document.getElementById('cartBody');
    var elStatus = document.getElementById('posStatus');

    function money(n) { return '₹' + Number(n || 0).toFixed(2); }

    function render() {
        var empty = document.getElementById('cartEmpty');
        if (empty) { empty.remove(); }
        elBody.innerHTML = '';
        var sub = 0, count = 0;

        cart.forEach(function (l, i) {
            var total = l.qty * l.unit_price;
            sub += total; count += l.qty;
            var tr = document.createElement('tr');

            var td1 = document.createElement('td');
            td1.className = 'small';
            td1.textContent = l.title + (l.sku ? ' · ' + l.sku : '');

            var td2 = document.createElement('td');
            var qty = document.createElement('input');
            qty.type = 'number'; qty.min = '0.001'; qty.step = '1'; qty.value = l.qty;
            qty.className = 'form-control form-control-sm';
            qty.addEventListener('change', function () {
                var v = parseFloat(qty.value);
                if (!v || v <= 0) { cart.splice(i, 1); } else { l.qty = v; }
                render();
            });
            td2.appendChild(qty);

            var td3 = document.createElement('td');
            td3.className = 'text-end'; td3.textContent = money(l.unit_price);
            var td4 = document.createElement('td');
            td4.className = 'text-end'; td4.textContent = money(total);

            var td5 = document.createElement('td');
            td5.className = 'text-end';
            var rm = document.createElement('button');
            rm.type = 'button'; rm.className = 'btn btn-sm btn-outline-danger';
            rm.innerHTML = '<i class="bi bi-x"></i>';
            rm.addEventListener('click', function () { cart.splice(i, 1); render(); });
            td5.appendChild(rm);

            [td1, td2, td3, td4, td5].forEach(function (td) { tr.appendChild(td); });
            elBody.appendChild(tr);
        });

        if (cart.length === 0) {
            var tr = document.createElement('tr');
            tr.id = 'cartEmpty';
            var td = document.createElement('td');
            td.colSpan = 5; td.className = 'text-center text-secondary py-4';
            td.textContent = 'Nothing scanned yet.';
            tr.appendChild(td); elBody.appendChild(tr);
        }

        document.getElementById('sumCount').textContent = String(count);
        document.getElementById('sumSub').textContent = money(sub);
        document.getElementById('sumTotal').textContent = money(Math.round(sub));
    }

    function add(row) {
        var found = cart.filter(function (c) { return c.variant_id === row.variant_id; })[0];
        if (found) { found.qty += 1; } else {
            cart.push({ variant_id: row.variant_id, title: row.title, sku: row.sku,
                        unit_price: Number(row.base_price), qty: 1 });
        }
        elResults.innerHTML = ''; elSearch.value = ''; elSearch.focus();
        render();
    }

    var timer = null;
    elSearch.addEventListener('input', function () {
        clearTimeout(timer);
        var q = elSearch.value.trim();
        if (q.length < 2) { elResults.innerHTML = ''; return; }
        timer = setTimeout(function () {
            fetch(searchUrl + '?q=' + encodeURIComponent(q) + '&mshop_id=' + unitId, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    elResults.innerHTML = '';
                    (j.rows || []).forEach(function (row) {
                        var a = document.createElement('button');
                        a.type = 'button';
                        a.className = 'list-group-item list-group-item-action d-flex justify-content-between';
                        var left = document.createElement('span');
                        left.textContent = row.title + (row.sku ? ' · ' + row.sku : '');
                        var right = document.createElement('span');
                        right.className = 'small text-secondary';
                        right.textContent = money(row.base_price) + ' · ' + Number(row.on_hand) + ' in stock';
                        a.appendChild(left); a.appendChild(right);
                        a.addEventListener('click', function () { add(row); });
                        elResults.appendChild(a);
                    });
                })
                .catch(function () { elResults.innerHTML = ''; });
        }, 200);
    });

    document.getElementById('posCheckout').addEventListener('click', function () {
        if (cart.length === 0) { elStatus.textContent = 'Cart is empty.'; return; }
        elStatus.textContent = 'Saving…';

        var fd = new FormData();
        fd.append(csrfName, csrf);
        fd.append('mshop_id', unitId);
        // A client-generated id, so a double-tapped button cannot record two sales.
        fd.append('client_uuid', 'web-' + unitId + '-' + Date.now() + '-' + Math.random().toString(16).slice(2));
        fd.append('customer_name', document.getElementById('custName').value);
        cart.forEach(function (l, i) {
            fd.append('items[' + i + '][variant_id]', l.variant_id);
            fd.append('items[' + i + '][qty]', l.qty);
        });
        fd.append('payments[0][tender_type]', document.getElementById('payMethod').value);
        fd.append('payments[0][amount]', document.getElementById('payAmount').value || '0');

        fetch(saleUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.csrf) { csrf = j.csrf; }
                if (!j.ok) { elStatus.textContent = j.error || 'Could not record the sale.'; return; }
                elStatus.textContent = 'Saved — ' + j.invoice_no + ', change ' + money(j.change);
                window.open(receiptUrl + '/' + j.sale_id, '_blank');
                cart = []; render();
                document.getElementById('payAmount').value = '';
                document.getElementById('custName').value = '';
            })
            .catch(function () { elStatus.textContent = 'Could not reach the server.'; });
    });

    render();
})();
</script>
<?= $this->endSection() ?>
