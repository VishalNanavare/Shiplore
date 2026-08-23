<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php
$isEdit    = ! empty($staff);
$action    = $isEdit ? site_url('vendor/staff/' . $staff['id'] . '/update') : site_url('vendor/staff');
// _form_fields.php reads $editStaff, not $staff — see its own header comment
// for why (index.php's $staff is an unrelated staff LIST).
$editStaff = $staff;
?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<?php if (! empty($asRequest)): ?>
    <div class="alert alert-info d-flex align-items-center"><i class="bi bi-shield-check me-2"></i>This change will be <strong class="mx-1">submitted to the vendor for approval</strong> before it takes effect.</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0"><i class="bi bi-person-badge me-1"></i><?= $isEdit ? 'Edit staff' : 'Add staff' ?></h1>
    <a href="<?= site_url('vendor/staff') ?>" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<form method="post" action="<?= $action ?>">
    <?= csrf_field() ?>
    <?= $this->include('vendor/staff/_form_fields') ?>
    <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i><?= $isEdit ? 'Save changes' : 'Create staff' ?></button></div>
</form>
<?= $this->endSection() ?>
