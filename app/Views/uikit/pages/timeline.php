<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Vertical activity timelines — order lifecycle, colored feed, with avatars and a compact status feed.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Order lifecycle</h2>
        <div class="uk-timeline mt-2">
            <?php foreach ([['Order placed','#10293 · ₹2,450','10:02 AM'],['Payment captured','Razorpay · txn_8821','10:03 AM'],['Packed','Warehouse BLR-1','11:40 AM'],['Out for delivery','Rider: Kabir','01:15 PM'],['Delivered','Signed by customer','03:48 PM']] as $t): ?>
                <div class="uk-timeline-item">
                    <div class="d-flex justify-content-between"><span class="fw-medium small"><?= $t[0] ?></span><span class="text-secondary small"><?= $t[2] ?></span></div>
                    <div class="text-secondary small"><?= $t[1] ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">With avatars</h2>
        <div class="uk-timeline mt-2">
            <?php foreach ([['RI','primary','Riya approved a vendor','Fresh Foods · 2h ago'],['SN','success','Sahil updated pricing','GST line fixed · 5h ago'],['AK','warning','Aman flagged an order','#10288 · yesterday']] as $t): ?>
                <div class="uk-timeline-item">
                    <div class="d-flex align-items-center gap-2"><span class="rounded-circle bg-<?= $t[1] ?>-subtle text-<?= $t[1] ?> d-grid" style="width:28px;height:28px;place-items:center;font-weight:600;font-size:.65rem"><?= $t[0] ?></span><span class="fw-medium small"><?= $t[2] ?></span></div>
                    <div class="text-secondary small ms-4 ps-1"><?= $t[3] ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <h2 class="uk-section-title mt-3 mb-2">Status feed</h2>
        <div class="uk-timeline">
            <div class="uk-timeline-item"><span class="badge bg-success-subtle text-success mb-1">Success</span><div class="small">Deployment finished</div></div>
            <div class="uk-timeline-item"><span class="badge bg-warning-subtle text-warning mb-1">Warning</span><div class="small">High memory usage</div></div>
            <div class="uk-timeline-item"><span class="badge bg-info-subtle text-info mb-1">Info</span><div class="small">New vendor registered</div></div>
        </div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
