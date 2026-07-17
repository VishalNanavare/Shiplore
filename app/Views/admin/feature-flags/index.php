<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<div class="card"><div class="card-header fw-semibold">Feature Flags</div>
<div class="table-responsive" data-ajax-region><table class="table align-middle mb-0">
    <thead class="table-light"><tr><th>Key</th><th>Description</th><th>State</th><th class="text-end"></th></tr></thead>
    <tbody>
    <?php foreach ($flags as $f): ?>
        <tr>
            <td><code><?= esc($f['key']) ?></code></td>
            <td class="small text-secondary"><?= esc($f['description'] ?? '') ?></td>
            <td><span class="badge text-bg-<?= (int) $f['is_enabled'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $f['is_enabled'] === 1 ? 'enabled' : 'disabled' ?></span></td>
            <td class="text-end"><form method="post" action="<?= site_url('admin/feature-flags/' . $f['id'] . '/toggle') ?>" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary"><i class="bi bi-toggle-on me-1"></i>Toggle</button></form></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($flags)): ?><tr><td colspan="4" class="text-center text-secondary py-4">No feature flags.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->endSection() ?>
