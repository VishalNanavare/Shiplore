<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Formatted inputs powered by IMask (loaded locally) — phone, card, GSTIN, dates, currency.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Common masks</h2>
        <div class="mb-3"><label class="form-label">Mobile (IN)</label><input class="form-control" id="mPhone" placeholder="+91 00000 00000"></div>
        <div class="mb-3"><label class="form-label">Credit card</label><input class="form-control" id="mCard" placeholder="0000 0000 0000 0000"></div>
        <div class="mb-0"><label class="form-label">Expiry</label><input class="form-control" id="mExp" placeholder="MM/YY"></div>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Domain masks</h2>
        <div class="mb-3"><label class="form-label">GSTIN</label><input class="form-control text-uppercase" id="mGst" placeholder="22AAAAA0000A1Z5"></div>
        <div class="mb-3"><label class="form-label">PIN code</label><input class="form-control" id="mPin" placeholder="000000"></div>
        <div class="mb-0"><label class="form-label">Amount (₹)</label><input class="form-control" id="mMoney" placeholder="₹ 0"></div>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/imask/imask.min.js') ?>"></script>
<script>
IMask(document.getElementById('mPhone'), { mask: '+{91} 00000 00000' });
IMask(document.getElementById('mCard'),  { mask: '0000 0000 0000 0000' });
IMask(document.getElementById('mExp'),   { mask: 'MM/YY', blocks: { MM: { mask: IMask.MaskedRange, from: 1, to: 12 }, YY: { mask: '00' } } });
IMask(document.getElementById('mGst'),   { mask: 'aaaaaaaaaaaaaaa', prepare: function (s) { return s.toUpperCase(); } });
IMask(document.getElementById('mPin'),   { mask: '000000' });
IMask(document.getElementById('mMoney'), { mask: '₹ num', blocks: { num: { mask: Number, thousandsSeparator: ',' } } });
</script>
<?= $this->endSection() ?>
