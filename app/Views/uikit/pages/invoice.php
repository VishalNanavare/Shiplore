<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-end gap-2 mb-3">
    <button class="btn btn-light" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
    <button class="btn btn-primary"><i class="bi bi-download me-1"></i>Download PDF</button>
</div>

<div class="card uk-card"><div class="card-body p-4 p-md-5">
    <div class="d-flex flex-wrap justify-content-between mb-4">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1"><img src="<?= asset('images/logo.svg') ?>" width="28" height="28"><span class="h5 mb-0">Shiplore</span></div>
            <div class="text-secondary small">2nd Floor, Tech Park<br>Pune, MH 411001<br>GSTIN: 27ABCDE1234F1Z5</div>
        </div>
        <div class="text-md-end">
            <div class="h4 mb-1">Invoice</div>
            <div class="text-secondary small">#INV-2026-06<br>Date: 01 Jun 2026<br>Due: 15 Jun 2026</div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-sm-6"><div class="text-secondary small text-uppercase mb-1">Bill To</div>
            <div class="fw-medium">Fresh Foods Pvt Ltd</div><div class="text-secondary small">Shop 14, Market Road<br>Mumbai, MH 400001<br>GSTIN: 27FRESH9876F1Z2</div></div>
        <div class="col-sm-6 text-sm-end mt-3 mt-sm-0"><div class="text-secondary small text-uppercase mb-1">Payment</div>
            <div class="fw-medium">Bank Transfer</div><div class="text-secondary small">A/C: xxxx 4821 · HDFC</div></div>
    </div>

    <div class="table-responsive"><table class="table align-middle">
        <thead class="table-light"><tr><th>#</th><th>Description</th><th class="text-center">Qty</th><th class="text-end">Rate</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        <?php
        $items = [['Platform subscription (Jun)',1,21186.44],['Transaction fees',1,3200.00],['SMS credits',2,250.00]];
        $sub = 0;
        foreach ($items as $i=>$it): $amt = $it[1]*$it[2]; $sub += $amt; ?>
            <tr><td><?= $i+1 ?></td><td><?= $it[0] ?></td><td class="text-center"><?= $it[1] ?></td><td class="text-end">₹<?= number_format($it[2],2) ?></td><td class="text-end">₹<?= number_format($amt,2) ?></td></tr>
        <?php endforeach; $gst = $sub*0.18; $total = $sub+$gst; ?>
        </tbody>
    </table></div>

    <div class="row justify-content-end"><div class="col-sm-5">
        <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">Subtotal</span><span>₹<?= number_format($sub,2) ?></span></div>
        <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">GST (18%)</span><span>₹<?= number_format($gst,2) ?></span></div>
        <hr class="my-2">
        <div class="d-flex justify-content-between fw-semibold"><span>Total</span><span class="text-primary">₹<?= number_format($total,2) ?></span></div>
    </div></div>

    <hr class="my-4">
    <div class="text-secondary small"><strong>Notes:</strong> Payment due within 14 days. Thank you for your business.</div>
</div></div>
<?= $this->endSection() ?>
