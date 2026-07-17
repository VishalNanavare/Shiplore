<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Contextual feedback — solid, soft, outline, with icons, links, actions, dismissible and rich content.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Solid contextual</h2>
        <?php foreach (['primary'=>'A simple primary alert.','success'=>'Saved successfully!','warning'=>'Check your input.','danger'=>'Something went wrong.','info'=>'New update available.'] as $t=>$m): ?>
            <div class="alert alert-<?= $t ?> py-2" role="alert"><?= $m ?></div>
        <?php endforeach; ?>
        <h2 class="uk-section-title mt-3 mb-3">With links</h2>
        <div class="alert alert-primary py-2">Read the <a href="#" class="alert-link">documentation</a> for details.</div>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Soft (subtle)</h2>
        <?php foreach (['primary'=>'check-circle','success'=>'check-circle','warning'=>'exclamation-triangle','danger'=>'x-circle','info'=>'info-circle'] as $t=>$ic): ?>
            <div class="alert bg-<?= $t ?>-subtle text-<?= $t ?>-emphasis border-0 py-2 d-flex align-items-center"><i class="bi bi-<?= $ic ?> me-2"></i><?= ucfirst($t) ?> soft alert</div>
        <?php endforeach; ?>
        <h2 class="uk-section-title mt-3 mb-3">Outline</h2>
        <?php foreach (['primary','success','danger'] as $t): ?>
            <div class="alert py-2 border border-<?= $t ?> text-<?= $t ?> bg-transparent">Outline <?= $t ?></div>
        <?php endforeach; ?>
    </div></div></div>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Dismissible</h2>
        <div class="alert alert-warning alert-dismissible fade show">Dismiss me.<button class="btn-close" data-bs-dismiss="alert"></button></div>
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center"><i class="bi bi-check-circle-fill me-2"></i><div>Profile updated.</div><button class="btn-close" data-bs-dismiss="alert"></button></div>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">With heading & actions</h2>
        <div class="alert alert-danger">
            <h6 class="alert-heading"><i class="bi bi-exclamation-octagon me-1"></i>Payment failed</h6>
            <p class="small mb-2">Your last transaction could not be processed. Update your payment method to continue.</p>
            <hr><button class="btn btn-sm btn-danger">Retry</button> <button class="btn btn-sm btn-outline-danger">Dismiss</button>
        </div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
