<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<?php $sb = ['uploaded' => 'warning', 'verified' => 'success', 'rejected' => 'danger']; $today = date('Y-m-d'); ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-file-earmark-text me-1"></i>KYC documents — <?= esc($vendor['display_name'] ?? 'Vendor') ?> <span class="text-secondary small fw-normal">(<?= count($docs) ?>)</span></span>
        <a href="<?= site_url('admin/vendors') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to vendors</a>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Type</th><th>Status</th><th>Uploaded</th><th>Expires</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($docs as $d): ?>
            <?php $expired = ! empty($d['expires_at']) && $d['expires_at'] < $today; ?>
            <tr>
                <td class="fw-medium text-uppercase"><?= esc($d['doc_type']) ?></td>
                <td>
                    <span class="badge text-bg-<?= esc($sb[$d['status']] ?? 'secondary', 'attr') ?>"><?= esc($d['status']) ?></span>
                    <?php if ($expired): ?><span class="badge text-bg-danger ms-1">expired</span><?php endif; ?>
                </td>
                <td class="small text-secondary"><?= esc($d['created_at']) ?></td>
                <td class="small text-secondary"><?= esc($d['expires_at'] ?: '—') ?></td>
                <td class="text-end">
                    <?php if (! empty($d['uuid'])): ?>
                        <a href="<?= site_url('media/' . $d['uuid']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye me-1"></i>View</a>
                    <?php else: ?>
                        <span class="text-secondary small">file missing</span>
                    <?php endif; ?>
                    <?php if ($d['status'] !== 'verified'): ?>
                        <form method="post" action="<?= site_url('admin/vendors/' . $vendor['id'] . '/documents/' . $d['id'] . '/verify') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-success" title="Verify"><i class="bi bi-check2"></i></button></form>
                    <?php endif; ?>
                    <?php if ($d['status'] !== 'rejected'): ?>
                        <form method="post" action="<?= site_url('admin/vendors/' . $vendor['id'] . '/documents/' . $d['id'] . '/reject') ?>" class="d-inline" data-confirm="Reject this document?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" title="Reject"><i class="bi bi-x-lg"></i></button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($docs)): ?><tr><td colspan="5" class="text-center text-secondary py-4">This vendor hasn't uploaded any documents yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
