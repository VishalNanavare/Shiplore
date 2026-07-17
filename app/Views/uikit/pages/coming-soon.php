<?= $this->extend('uikit/_blank') ?>

<?= $this->section('content') ?>
<div class="text-center" style="max-width:560px;margin:0 auto">
    <div class="display-3 text-primary mb-2"><i class="bi bi-rocket-takeoff"></i></div>
    <h1 class="h3">We're launching soon</h1>
    <p class="text-secondary">Our new platform is almost ready. Sign up to be notified the moment we go live.</p>
    <div class="d-flex justify-content-center gap-2 my-4" id="csTimer">
        <?php foreach (['12'=>'Days','08'=>'Hours','45'=>'Mins','20'=>'Secs'] as $n=>$l): ?>
            <div class="card uk-card" style="width:78px"><div class="card-body p-2"><div class="h4 mb-0"><?= $n ?></div><div class="text-secondary small"><?= $l ?></div></div></div>
        <?php endforeach; ?>
    </div>
    <div class="input-group mx-auto" style="max-width:380px">
        <input class="form-control" placeholder="you@example.com">
        <button class="btn btn-primary">Notify me</button>
    </div>
</div>
<?= $this->endSection() ?>
