<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="card mb-4"><div class="card-body">
    <form method="get" action="<?= site_url('admin/reports/gst') ?>" class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small">From</label><input type="date" name="start" class="form-control" value="<?= esc($start, 'attr') ?>"></div>
        <div class="col-md-3"><label class="form-label small">To</label><input type="date" name="end" class="form-control" value="<?= esc($end, 'attr') ?>"></div>
        <div class="col-md-6"><button class="btn btn-primary">View</button>
            <a href="<?= site_url('admin/reports/export-sales?start=' . urlencode($start) . '&end=' . urlencode($end)) ?>" class="btn btn-outline-secondary ms-2"><i class="bi bi-download me-1"></i>Export CSV</a>
        </div>
    </form>
</div></div>

<div class="row g-3">
    <?php foreach ([
        ['Taxable value', $gst['taxable'], 'primary'], ['CGST', $gst['cgst'], 'info'], ['SGST', $gst['sgst'], 'info'],
        ['IGST', $gst['igst'], 'info'], ['Grand total', $gst['grand'], 'success'], ['Orders', $gst['orders'], 'secondary'],
    ] as [$label, $val, $color]): ?>
        <div class="col-md-4 col-lg-2"><div class="card h-100"><div class="card-body text-center py-3">
            <div class="text-secondary small"><?= esc($label) ?></div>
            <div class="h5 mb-0 text-<?= $color ?>"><?= $label === 'Orders' ? esc($val) : '₹' . esc(number_format((float) $val, 2)) ?></div>
        </div></div></div>
    <?php endforeach; ?>
</div>
<p class="text-secondary small mt-3"><i class="bi bi-info-circle me-1"></i>GST collected on sub-orders placed between <?= esc($start) ?> and <?= esc($end) ?> (CGST+SGST intra-state, IGST inter-state). Export the CSV for GSTR filing.</p>
<?= $this->endSection() ?>
