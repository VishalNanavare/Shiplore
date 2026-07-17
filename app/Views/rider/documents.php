<?= $this->extend('layouts/rider') ?>

<?= $this->section('content') ?>
<?php $db = ['uploaded' => 'warning', 'verified' => 'success', 'rejected' => 'danger']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success py-2 small"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger py-2 small"><?= esc($m) ?></div><?php endif; ?>

<h1 class="h5 mb-3">My documents</h1>

<div class="rd-card p-3 mb-3">
    <form method="post" action="<?= site_url('rider/documents/upload') ?>" enctype="multipart/form-data" data-no-ajax>
        <?= csrf_field() ?>
        <label class="form-label small mb-1">Upload a document</label>
        <div class="row g-2">
            <div class="col-6"><select name="doc_type" class="form-select form-select-sm"><?php foreach ($types as $t): ?><option value="<?= $t ?>"><?= strtoupper($t) ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><input type="date" name="expires_at" class="form-control form-control-sm" title="Expiry (optional)"></div>
            <div class="col-12"><input type="file" name="document" class="form-control form-control-sm" accept="image/*,.pdf" required></div>
            <div class="col-12"><button class="btn btn-rd btn-sm w-100"><i class="bi bi-cloud-arrow-up me-1"></i>Upload</button></div>
        </div>
    </form>
</div>

<?php foreach ($docs as $d): ?>
    <div class="rd-card p-3 mb-2 d-flex justify-content-between align-items-center">
        <div>
            <div class="fw-medium text-uppercase"><?= esc($d['doc_type']) ?></div>
            <div class="small"><span class="rd-badge st-assigned text-bg-<?= esc($db[$d['status']] ?? 'secondary', 'attr') ?>"><?= esc($d['status']) ?></span>
                <?php if (! empty($d['expires_at'])): ?><span class="text-secondary">· exp <?= esc($d['expires_at']) ?></span><?php endif; ?></div>
            <?php if (! empty($d['note'])): ?><div class="text-danger small"><?= esc($d['note']) ?></div><?php endif; ?>
        </div>
        <div class="d-flex gap-1">
            <?php if (! empty($d['uuid'])): ?><a href="<?= site_url('media/' . $d['uuid']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a><?php endif; ?>
            <?php if ($d['status'] !== 'verified'): ?><form method="post" action="<?= site_url('rider/documents/' . $d['id'] . '/delete') ?>" data-confirm="Remove this document?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form><?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
<?php if (empty($docs)): ?><div class="text-center text-secondary py-4"><i class="bi bi-file-earmark display-6 d-block mb-2"></i>No documents yet. Upload your licence, RC &amp; insurance.</div><?php endif; ?>
<?= $this->endSection() ?>
