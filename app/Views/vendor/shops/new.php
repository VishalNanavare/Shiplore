<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="row"><div class="col-lg-9">
    <div class="card"><div class="card-body p-4">
        <h2 class="h6 text-secondary text-uppercase mb-3">New shop</h2>
        <form method="post" action="<?= site_url('vendor/shops') ?>">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-12"><label class="form-label">Shop Name <span class="text-danger">*</span></label><input name="name" class="form-control" value="<?= esc(old('name'), 'attr') ?>" required></div>
                <?= $this->include('partials/_shop_location', ['mapsKey' => $mapsKey, 'states' => $states, 'maxRadius' => $maxRadius]) ?>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Add shop</button>
                <a href="<?= site_url('vendor/shops') ?>" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div></div>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= $this->include('partials/_shop_location_scripts', ['mapsKey' => $mapsKey, 'stateNameToCode' => $stateNameToCode]) ?>
<?= $this->endSection() ?>
