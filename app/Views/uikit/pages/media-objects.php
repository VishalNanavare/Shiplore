<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Media object pattern — an image/icon aligned beside a block of content (comments, notifications, feeds).</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Comments</h2>
        <?php foreach ([['AK','primary','Aman Khan','2h','Great work on the dashboard — the charts load fast now.'],['RI','success','Riya Iyer','5h','Can we add a date filter to the report?'],['MD','warning','Meera Das','1d','Approved the vendor application.']] as $m): ?>
            <div class="d-flex gap-3 mb-3">
                <span class="rounded-circle bg-<?= $m[1] ?>-subtle text-<?= $m[1] ?> d-grid flex-shrink-0" style="width:44px;height:44px;place-items:center;font-weight:600"><?= $m[0] ?></span>
                <div><div class="d-flex gap-2 align-items-center"><span class="fw-medium small"><?= $m[2] ?></span><span class="text-secondary small"><?= $m[3] ?></span></div><div class="small text-secondary"><?= $m[4] ?></div></div>
            </div>
        <?php endforeach; ?>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Notifications</h2>
        <?php foreach ([['cart-check','success','New order #10293','₹2,450 · 2 min ago'],['person-plus','primary','New vendor signup','Fresh Foods · 1h ago'],['exclamation-triangle','warning','Low stock alert','Earbuds — 4 left'],['cash-stack','info','Settlement processed','₹42,000 · today']] as $n): ?>
            <div class="d-flex gap-3 align-items-center mb-3">
                <span class="rounded bg-<?= $n[1] ?>-subtle text-<?= $n[1] ?> d-grid flex-shrink-0" style="width:40px;height:40px;place-items:center"><i class="bi bi-<?= $n[0] ?>"></i></span>
                <div class="flex-grow-1"><div class="fw-medium small"><?= $n[2] ?></div><div class="text-secondary small"><?= $n[3] ?></div></div>
                <i class="bi bi-chevron-right text-secondary"></i>
            </div>
        <?php endforeach; ?>
    </div></div></div>
</div>
<?= $this->endSection() ?>
