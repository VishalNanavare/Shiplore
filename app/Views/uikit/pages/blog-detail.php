<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card uk-card"><div class="card-body p-4">
            <span class="badge bg-primary-subtle text-primary mb-2">Engineering</span>
            <h1 class="h3">Scaling to 10,000 vendors</h1>
            <div class="d-flex align-items-center gap-2 text-secondary small mb-3">
                <span class="rounded-circle bg-primary-subtle text-primary d-grid" style="width:30px;height:30px;place-items:center;font-weight:600;font-size:.7rem">RI</span>
                Riya Iyer · 8 Jun 2026 · 5 min read
            </div>
            <div style="height:220px;background:linear-gradient(120deg,#5b6ef5,#00cfe8);border-radius:.6rem" class="d-grid mb-4"><i class="bi bi-graph-up-arrow align-self-center text-center text-white" style="font-size:3rem"></i></div>
            <p class="lead">When we crossed a few hundred vendors, our settlement job started taking hours. Here's how we fixed it.</p>
            <p>We moved from a monolithic nightly batch to an event-driven pipeline. Each order emits a settlement event that is aggregated per vendor and reconciled incrementally.</p>
            <h3 class="h5 mt-4">The bottleneck</h3>
            <p>Profiling showed 80% of the time was spent in a single N+1 query loop. Batching the reads and pre-aggregating in the database cut runtime by 12×.</p>
            <blockquote class="blockquote border-start border-3 border-primary ps-3 my-4"><p class="mb-0">Premature optimization is the root of all evil — but measured optimization is engineering.</p></blockquote>
            <h3 class="h5 mt-4">Results</h3>
            <ul><li>Settlement runtime: 3h → 14m</li><li>Reconciliation errors: down 96%</li><li>Vendor payout SLA: next-day, reliably</li></ul>
            <div class="d-flex gap-2 mt-4"><span class="badge bg-light text-secondary border">#scale</span><span class="badge bg-light text-secondary border">#payments</span><span class="badge bg-light text-secondary border">#architecture</span></div>
        </div></div>

        <div class="card uk-card mt-3"><div class="card-body">
            <h2 class="uk-section-title mb-3">3 Comments</h2>
            <?php foreach ([['AK','primary','Aman Khan','Brilliant write-up — the event-driven approach is clean.'],['MD','success','Meera Das','How do you handle late-arriving order events?']] as $c): ?>
                <div class="d-flex gap-2 mb-3"><span class="rounded-circle bg-<?= $c[1] ?>-subtle text-<?= $c[1] ?> d-grid flex-shrink-0" style="width:38px;height:38px;place-items:center;font-weight:600;font-size:.72rem"><?= $c[0] ?></span>
                    <div><div class="fw-medium small"><?= $c[2] ?></div><div class="small text-secondary"><?= $c[3] ?></div></div></div>
            <?php endforeach; ?>
            <div class="input-group mt-3"><input class="form-control" placeholder="Add a comment…"><button class="btn btn-primary">Post</button></div>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card uk-card mb-3"><div class="card-body text-center">
            <span class="rounded-circle bg-primary-subtle text-primary d-grid mx-auto mb-2" style="width:64px;height:64px;place-items:center;font-weight:700;font-size:1.4rem">RI</span>
            <h3 class="h6 mb-0">Riya Iyer</h3><div class="text-secondary small">Platform Engineer</div>
            <button class="btn btn-sm btn-outline-primary mt-2">Follow</button>
        </div></div>
        <div class="card uk-card"><div class="card-body">
            <h2 class="uk-section-title mb-3">Related</h2>
            <?php foreach (['GST automation, explained','Offline-first POS','Designing the dashboard'] as $r): ?>
                <a href="<?= site_url('ui-kit/blog-detail') ?>" class="d-block py-2 border-bottom text-reset text-decoration-none small"><?= $r ?></a>
            <?php endforeach; ?>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
