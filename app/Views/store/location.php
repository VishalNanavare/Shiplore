<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<div class="row justify-content-center"><div class="col-lg-7">
    <div class="card"><div class="card-body p-4">
        <h1 class="h4 mb-1"><i class="bi bi-geo-alt text-primary me-1"></i>Set your delivery location</h1>
        <p class="text-secondary small">We show only shops that deliver to you (within their delivery radius).</p>
        <?php if ($location): ?><div class="alert alert-light border small">Current: <strong><?= esc($location['label']) ?></strong> <?= $location['pincode'] ? '· ' . esc($location['pincode']) : '' ?></div><?php endif; ?>

        <h2 class="uk-section-title h6 mt-3">Quick pick</h2>
        <div class="row g-2 mb-3">
            <?php foreach ($presets as [$label, $lat, $lng, $pin]): ?>
                <div class="col-sm-6"><form method="post" action="<?= site_url('store/location') ?>"><?= csrf_field() ?>
                    <input type="hidden" name="lat" value="<?= $lat ?>"><input type="hidden" name="lng" value="<?= $lng ?>">
                    <input type="hidden" name="label" value="<?= esc($label, 'attr') ?>"><input type="hidden" name="pincode" value="<?= $pin ?>">
                    <button class="btn btn-outline-primary w-100 text-start"><i class="bi bi-geo me-1"></i><?= esc($label) ?></button>
                </form></div>
            <?php endforeach; ?>
        </div>

        <h2 class="uk-section-title h6">Or enter coordinates</h2>
        <form method="post" action="<?= site_url('store/location') ?>" class="row g-2">
            <?= csrf_field() ?>
            <div class="col-md-4"><input name="lat" class="form-control" placeholder="Latitude" required></div>
            <div class="col-md-4"><input name="lng" class="form-control" placeholder="Longitude" required></div>
            <div class="col-md-4"><input name="pincode" class="form-control" placeholder="Pincode"></div>
            <div class="col-12"><input name="label" class="form-control" placeholder="Label (e.g. Home)"></div>
            <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Use this location</button></div>
        </form>
    </div></div>
</div></div>
<?= $this->endSection() ?>
