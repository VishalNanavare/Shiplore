<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $badge = ['active' => 'success', 'inactive' => 'secondary', 'sandbox' => 'warning']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="small text-secondary"><i class="bi bi-shield-lock me-1"></i>The checkout uses the highest-priority <strong>active</strong> gateway for each channel.</div>
    <a href="<?= site_url('admin/payment-gateways/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Gateway</a>
</div>

<div class="card">
    <div class="card-header fw-semibold">Payment Gateways</div>
    <div class="card-body">
        <?php if (empty($gateways)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-credit-card-2-back display-6 d-block mb-2"></i>No gateways configured. Add PayU, Razorpay or Stripe.</div>
        <?php else: ?>
        <div class="table-responsive" data-ajax-region><table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Provider</th><th>Channel</th><th>Priority</th><th>Status</th><th>Updated</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($gateways as $g): ?>
                <tr>
                    <td class="fw-semibold"><?= esc($providers[$g['provider']] ?? $g['provider']) ?></td>
                    <td class="text-capitalize small"><?= esc($g['channel']) ?></td>
                    <td><?= esc($g['priority']) ?></td>
                    <td><span class="badge text-bg-<?= esc($badge[$g['status']] ?? 'secondary', 'attr') ?>"><?= esc($g['status']) ?></span></td>
                    <td class="small text-secondary"><?= esc(substr((string) ($g['updated_at'] ?? ''), 0, 16)) ?></td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1">
                            <form method="post" action="<?= site_url('admin/payment-gateways/' . $g['id'] . '/test') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-info" title="Test"><i class="bi bi-plug"></i></button></form>
                            <?php if ($g['status'] === 'active'): ?>
                                <form method="post" action="<?= site_url('admin/payment-gateways/' . $g['id'] . '/disable') ?>" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" title="Disable"><i class="bi bi-pause"></i></button></form>
                            <?php else: ?>
                                <form method="post" action="<?= site_url('admin/payment-gateways/' . $g['id'] . '/enable') ?>" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-success" title="Enable"><i class="bi bi-play"></i></button></form>
                            <?php endif; ?>
                            <a href="<?= site_url('admin/payment-gateways/' . $g['id'] . '/edit') ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= site_url('admin/payment-gateways/' . $g['id'] . '/delete') ?>" data-confirm="Remove this gateway?" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button></form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
