<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<h1 class="h4 mb-3">My Addresses</h1>
<div class="row g-3">
    <div class="col-lg-7">
        <?php foreach ($addresses as $a): ?>
            <div class="card mb-2"><div class="card-body d-flex justify-content-between">
                <div><span class="fw-medium"><?= esc($a['label']) ?></span> <?php if ($a['is_default']): ?><span class="badge bg-primary-subtle text-primary">default</span><?php endif; ?>
                    <div class="text-secondary small"><?= esc($a['line1']) ?><?= $a['line2'] ? ', ' . esc($a['line2']) : '' ?>, <?= esc($a['city']) ?> <?= esc($a['pincode']) ?> (<?= esc($a['state_code']) ?>)</div></div>
                <form method="post" action="<?= site_url('store/account/addresses/' . $a['id'] . '/delete') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button></form>
            </div></div>
        <?php endforeach; ?>
        <?php if (empty($addresses)): ?><div class="st-empty"><i class="bi bi-geo-alt"></i>No saved addresses.</div><?php endif; ?>
    </div>
    <div class="col-lg-5"><div class="card"><div class="card-body">
        <h2 class="h6 mb-3">Add address</h2>
        <form method="post" action="<?= site_url('store/account/addresses') ?>" class="row g-2">
            <?= csrf_field() ?>
            <div class="col-12"><input name="label" class="form-control form-control-sm" placeholder="Label (Home/Work)"></div>
            <div class="col-12"><input name="line1" class="form-control form-control-sm" placeholder="Address line 1" required></div>
            <div class="col-12"><input name="line2" class="form-control form-control-sm" placeholder="Address line 2"></div>
            <div class="col-6"><input name="city" class="form-control form-control-sm" placeholder="City" required></div>
            <div class="col-3"><input name="state_code" class="form-control form-control-sm" placeholder="State" maxlength="2"></div>
            <div class="col-3"><input name="pincode" class="form-control form-control-sm" placeholder="PIN"></div>
            <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_default" id="ad"><label class="form-check-label small" for="ad">Set as default</label></div></div>
            <div class="col-12"><button class="btn btn-sm btn-primary">Save address</button></div>
        </form>
    </div></div></div>
</div>
<?= $this->endSection() ?>
