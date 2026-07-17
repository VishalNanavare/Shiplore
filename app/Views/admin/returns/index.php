<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<?php
$badge = ['requested' => 'warning', 'approved' => 'info', 'rejected' => 'danger', 'pickup_scheduled' => 'primary',
          'picked_up' => 'primary', 'qc' => 'secondary', 'refund_approved' => 'info',
          'refunded' => 'success', 'restocked' => 'success', 'write_off' => 'dark'];
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold">Returns (RMA pipeline)</span>
        <div class="d-flex gap-1 flex-wrap">
            <a class="btn btn-sm btn-outline-secondary <?= $status === '' ? 'active' : '' ?>" href="<?= site_url('admin/returns') ?>">All</a>
            <?php foreach (array_keys($badge) as $s): ?>
                <a class="btn btn-sm btn-outline-secondary <?= $status === $s ? 'active' : '' ?>" href="<?= site_url('admin/returns?status=' . $s) ?>">
                    <?= esc(str_replace('_', ' ', $s)) ?><?php if (isset($counts[$s])): ?> <span class="badge text-bg-light"><?= (int) $counts[$s] ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="table-responsive" data-ajax-region><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>#</th><th>Order</th><th>Customer</th><th>Vendor</th><th>Channel</th><th>Reason</th><th>Refund to</th><th>Status</th><th>Date</th><th class="text-end">Move to</th></tr></thead>
        <tbody>
        <?php foreach ($returns as $r): ?>
            <tr>
                <td class="fw-semibold">#<?= esc($r['id']) ?></td>
                <td class="small"><?= esc($r['order_no'] ?? $r['sub_order_no'] ?? '—') ?></td>
                <td class="small"><?= esc($r['customer'] ?? '—') ?></td>
                <td class="small"><?= esc($r['vendor'] ?? '—') ?></td>
                <td class="text-uppercase small"><?= esc($r['channel']) ?></td>
                <td class="small text-secondary"><?= esc($r['reason'] ?? '—') ?></td>
                <td class="small text-capitalize"><?= esc($r['refund_to']) ?></td>
                <td><span class="badge text-bg-<?= esc($badge[$r['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $r['status'])) ?></span></td>
                <td class="text-secondary small"><?= esc(substr((string) $r['created_at'], 0, 10)) ?></td>
                <td class="text-end">
                    <?php $next = $allowed[$r['status']] ?? []; ?>
                    <div class="d-flex gap-1 justify-content-end align-items-center">
                        <a href="<?= site_url('admin/returns/' . $r['id']) ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="bi bi-eye"></i></a>
                        <?php if ($next !== []): ?>
                            <form method="post" action="<?= site_url('admin/returns/' . $r['id'] . '/transition') ?>" class="d-flex gap-1">
                                <?= csrf_field() ?>
                                <?php foreach ($next as $to): ?>
                                    <button class="btn btn-sm <?= in_array($to, ['rejected', 'write_off'], true) ? 'btn-outline-danger' : 'btn-outline-primary' ?>" name="to" value="<?= esc($to, 'attr') ?>"><?= esc(str_replace('_', ' ', $to)) ?></button>
                                <?php endforeach; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($returns)): ?><tr><td colspan="10" class="text-center text-secondary py-4">No returns in this state.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
