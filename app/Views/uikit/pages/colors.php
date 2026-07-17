<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Theme palette, subtle tones, filled, text emphasis, borders, gradients and grays.</p>

<div class="card uk-card mb-3"><div class="card-body">
    <h2 class="uk-section-title mb-3">Theme colors</h2>
    <div class="row g-3">
        <?php foreach (['primary'=>'#5b6ef5','success'=>'#28c76f','warning'=>'#ff9f43','danger'=>'#ea5455','info'=>'#00cfe8','secondary'=>'#6c757d','dark'=>'#1e2233','light'=>'#f4f6fb'] as $name=>$hex): ?>
            <div class="col-6 col-md-3"><div class="d-flex align-items-center border rounded overflow-hidden">
                <div class="uk-swatch bg-<?= $name ?>" style="width:56px"></div>
                <div class="px-2 py-1 small"><div class="fw-medium text-capitalize"><?= $name ?></div><div class="text-secondary"><?= $hex ?></div></div>
            </div></div>
        <?php endforeach; ?>
    </div>
</div></div>

<div class="row g-3">
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Subtle backgrounds</h2>
        <div class="d-flex flex-column gap-2"><?php foreach (['primary','success','warning','danger','info','secondary'] as $c): ?><div class="p-2 rounded bg-<?= $c ?>-subtle text-<?= $c ?>-emphasis">.bg-<?= $c ?>-subtle</div><?php endforeach; ?></div>
    </div></div></div>
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Filled</h2>
        <div class="d-flex flex-column gap-2"><?php foreach (['primary','success','warning','danger','info','dark'] as $c): ?><div class="p-2 rounded text-bg-<?= $c ?>">.text-bg-<?= $c ?></div><?php endforeach; ?></div>
    </div></div></div>
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Text emphasis & borders</h2>
        <div class="mb-3"><?php foreach (['primary','success','danger','warning'] as $c): ?><span class="text-<?= $c ?>-emphasis me-2">emphasis</span><?php endforeach; ?></div>
        <div class="d-flex gap-2 flex-wrap mb-3"><?php foreach (['primary','success','danger','warning','info'] as $c): ?><span class="d-inline-block border border-2 border-<?= $c ?> rounded" style="width:38px;height:38px"></span><?php endforeach; ?></div>
        <h2 class="uk-section-title mb-2">Gradients</h2>
        <div class="rounded p-3 text-white mb-2" style="background:linear-gradient(120deg,#5b6ef5,#00cfe8)">Primary → Info</div>
        <div class="rounded p-3 text-white" style="background:linear-gradient(120deg,#28c76f,#ff9f43)">Success → Warning</div>
    </div></div></div>
</div>

<div class="card uk-card mt-3"><div class="card-body">
    <h2 class="uk-section-title mb-3">Gray scale</h2>
    <div class="d-flex flex-wrap">
        <?php foreach (['#f8f9fa','#e9ecef','#dee2e6','#ced4da','#adb5bd','#6c757d','#495057','#343a40','#212529'] as $g): ?>
            <div class="text-center"><div style="width:56px;height:48px;background:<?= $g ?>"></div><div class="small text-secondary" style="font-size:.65rem"><?= $g ?></div></div>
        <?php endforeach; ?>
    </div>
</div></div>
<?= $this->endSection() ?>
