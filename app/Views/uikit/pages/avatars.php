<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Avatars — sizes, shapes, colors, initials, icons, status dots, badges and stacked groups.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Sizes</h2>
        <div class="d-flex align-items-center gap-3 mb-4"><?php foreach (['28px'=>'.7rem','36px'=>'.8rem','48px'=>'1rem','64px'=>'1.3rem','80px'=>'1.7rem'] as $sz=>$fs): ?><span class="rounded-circle bg-primary-subtle text-primary d-grid" style="width:<?= $sz ?>;height:<?= $sz ?>;place-items:center;font-weight:600;font-size:<?= $fs ?>">VN</span><?php endforeach; ?></div>
        <h2 class="uk-section-title mb-3">Shapes</h2>
        <div class="d-flex align-items-center gap-3 mb-4">
            <span class="rounded-circle bg-info-subtle text-info d-grid" style="width:48px;height:48px;place-items:center;font-weight:600">CI</span>
            <span class="rounded bg-success-subtle text-success d-grid" style="width:48px;height:48px;place-items:center;font-weight:600">SQ</span>
            <span class="bg-warning-subtle text-warning d-grid" style="width:48px;height:48px;place-items:center;font-weight:600;border-radius:0">NA</span>
        </div>
        <h2 class="uk-section-title mb-3">Colors & icons</h2>
        <div class="d-flex gap-2 flex-wrap"><?php foreach (['primary','success','warning','danger','info','secondary'] as $c): ?><span class="rounded-circle bg-<?= $c ?>-subtle text-<?= $c ?> d-grid" style="width:44px;height:44px;place-items:center"><i class="bi bi-person"></i></span><?php endforeach; ?></div>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Status & badges</h2>
        <div class="d-flex gap-4 mb-4">
            <?php foreach (['success'=>'online','warning'=>'away','secondary'=>'offline','danger'=>'busy'] as $c=>$lbl): ?>
                <div class="text-center"><div class="position-relative d-inline-block"><span class="rounded-circle bg-primary-subtle text-primary d-grid" style="width:48px;height:48px;place-items:center;font-weight:600">VN</span><span class="position-absolute bottom-0 end-0 bg-<?= $c ?> rounded-circle border border-2 border-white" style="width:13px;height:13px"></span></div><div class="small text-secondary mt-1"><?= $lbl ?></div></div>
            <?php endforeach; ?>
        </div>
        <h2 class="uk-section-title mb-3">With counter badge</h2>
        <div class="position-relative d-inline-block mb-4">
            <span class="rounded-circle bg-info-subtle text-info d-grid" style="width:56px;height:56px;place-items:center;font-weight:600">MD</span>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">5</span>
        </div>
        <h2 class="uk-section-title mb-3">Stacked group</h2>
        <div class="d-flex">
            <?php foreach (['AK'=>'primary','RI'=>'success','MD'=>'warning','SN'=>'info'] as $in=>$c): ?><span class="rounded-circle bg-<?= $c ?>-subtle text-<?= $c ?> d-grid border border-2 border-white" style="width:40px;height:40px;place-items:center;font-weight:600;margin-left:-10px"><?= $in ?></span><?php endforeach; ?>
            <span class="rounded-circle bg-light text-secondary d-grid border border-2 border-white" style="width:40px;height:40px;place-items:center;margin-left:-10px">+5</span>
        </div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
