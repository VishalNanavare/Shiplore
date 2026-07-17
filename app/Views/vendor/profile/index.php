<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card"><div class="card-body text-center">
            <h2 class="h6 mb-3"><i class="bi bi-building me-1"></i>Business logo</h2>
            <div class="border rounded d-inline-flex align-items-center justify-content-center mb-3" style="width:160px;height:160px;overflow:hidden;background:#f8f9fb">
                <?php if (! empty($logoUuid)): ?>
                    <img src="<?= site_url('media/' . $logoUuid) ?>" alt="Logo" style="max-width:100%;max-height:100%">
                <?php else: ?>
                    <i class="bi bi-shop text-secondary" style="font-size:3rem"></i>
                <?php endif; ?>
            </div>
            <div class="fw-medium"><?= esc($vendorName ?? 'Vendor') ?></div>
        </div></div>
    </div>
    <div class="col-lg-7">
        <div class="card"><div class="card-body">
            <h2 class="h6 mb-3"><i class="bi bi-cloud-arrow-up me-1"></i>Upload a new logo</h2>
            <form method="post" action="<?= site_url('vendor/profile/logo') ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="file" name="logo" class="form-control mb-2" accept=".jpg,.jpeg,.png,.webp,.gif,image/*" required>
                <div class="small text-secondary mb-3">JPG, PNG, WEBP or GIF · up to 5 MB. Stored on your S3 media server.</div>
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save logo</button>
            </form>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
