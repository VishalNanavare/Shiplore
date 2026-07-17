<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="row g-3">
            <?php
            $posts = [
                ['Scaling to 10,000 vendors','Engineering','primary','graph-up-arrow','How we re-architected settlements for scale.','RI','8 Jun'],
                ['GST automation, explained','Product','success','receipt','Inclusive vs exclusive tax, done right.','SN','5 Jun'],
                ['Offline-first POS','Engineering','info','pc-display','Designing for unreliable connectivity.','AK','2 Jun'],
                ['Designing the dashboard','Design','warning','easel','Lessons from our redesign.','MD','28 May'],
            ];
            foreach ($posts as $p): ?>
                <div class="col-md-6"><div class="card uk-card h-100">
                    <div style="height:150px;background:#fbfcfe" class="d-grid border-bottom"><i class="bi bi-<?= $p[3] ?> align-self-center text-center text-<?= $p[2] ?>" style="font-size:2.6rem"></i></div>
                    <div class="card-body">
                        <span class="badge bg-<?= $p[2] ?>-subtle text-<?= $p[2] ?> mb-2"><?= $p[1] ?></span>
                        <h3 class="h6"><a href="<?= site_url('ui-kit/blog-detail') ?>" class="text-reset text-decoration-none"><?= $p[0] ?></a></h3>
                        <p class="text-secondary small"><?= $p[4] ?></p>
                        <div class="d-flex align-items-center gap-2 small text-secondary">
                            <span class="rounded-circle bg-<?= $p[2] ?>-subtle text-<?= $p[2] ?> d-grid" style="width:26px;height:26px;place-items:center;font-weight:600;font-size:.62rem"><?= $p[5] ?></span>
                            <?= $p[6] ?> · 5 min read
                        </div>
                    </div>
                </div></div>
            <?php endforeach; ?>
        </div>
        <nav class="mt-3"><ul class="pagination justify-content-center mb-0"><li class="page-item active"><a class="page-link" href="#">1</a></li><li class="page-item"><a class="page-link" href="#">2</a></li><li class="page-item"><a class="page-link" href="#">3</a></li></ul></nav>
    </div>
    <div class="col-lg-4">
        <div class="card uk-card mb-3"><div class="card-body">
            <h2 class="uk-section-title mb-3">Categories</h2>
            <?php foreach (['Engineering'=>14,'Product'=>9,'Design'=>6,'Business'=>4] as $c=>$n): ?>
                <a href="#" class="d-flex justify-content-between py-1 text-reset text-decoration-none small"><span><?= $c ?></span><span class="badge bg-light text-secondary border"><?= $n ?></span></a>
            <?php endforeach; ?>
        </div></div>
        <div class="card uk-card"><div class="card-body">
            <h2 class="uk-section-title mb-3">Tags</h2>
            <?php foreach (['scale','gst','pos','design','api','security','payments'] as $t): ?><span class="badge bg-light text-secondary border me-1 mb-1">#<?= $t ?></span><?php endforeach; ?>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
