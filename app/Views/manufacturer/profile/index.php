<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>
<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header py-2"><strong>Business details</strong></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-4 text-secondary">Display name</dt>
                    <dd class="col-sm-8"><?= esc($manufacturer['display_name'] ?? '—') ?></dd>

                    <dt class="col-sm-4 text-secondary">Legal name</dt>
                    <dd class="col-sm-8"><?= esc($manufacturer['legal_name'] ?? '—') ?></dd>

                    <dt class="col-sm-4 text-secondary">GSTIN</dt>
                    <dd class="col-sm-8">
                        <?= esc($manufacturer['gstin'] ?? '—') ?>
                        <?php $gs = $manufacturer['gstin_status'] ?? 'unverified'; ?>
                        <span class="badge bg-<?= $gs === 'verified' ? 'success' : ($gs === 'failed' ? 'danger' : 'secondary') ?>">
                            <?= esc($gs) ?>
                        </span>
                    </dd>

                    <dt class="col-sm-4 text-secondary">Status</dt>
                    <dd class="col-sm-8">
                        <span class="badge bg-<?= ($manufacturer['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>">
                            <?= esc((string) ($manufacturer['status'] ?? '')) ?>
                        </span>
                    </dd>
                </dl>
                <hr>
                <p class="small text-secondary mb-0">
                    Legal name, GSTIN and approval status are verified records — contact support to
                    have them changed. Your manufacturing addresses are managed under
                    <a href="<?= site_url('manufacturer/units') ?>">Units</a>.
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header py-2"><strong>Logo</strong></div>
            <div class="card-body">
                <div class="mb-3 d-flex align-items-center gap-3">
                    <?php if (! empty($logoUuid)): ?>
                        <img src="<?= site_url('media/' . $logoUuid) ?>" alt="Current logo" width="72" height="72"
                             class="rounded border" style="object-fit:contain;background:#fff">
                    <?php else: ?>
                        <div class="rounded border d-flex align-items-center justify-content-center text-secondary"
                             style="width:72px;height:72px"><i class="bi bi-image"></i></div>
                    <?php endif; ?>
                    <div class="small text-secondary">
                        Shown on your monline listings and purchase orders.<br>
                        Square images work best.
                    </div>
                </div>
                <form method="post" action="<?= site_url('manufacturer/profile/logo') ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="mb-2">
                        <label class="form-label" for="logo">Upload a new logo</label>
                        <input class="form-control form-control-sm" id="logo" name="logo" type="file"
                               accept="image/jpeg,image/png,image/webp,image/gif" required>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">Upload</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
