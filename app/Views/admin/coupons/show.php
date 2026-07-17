<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $sb = ['active' => 'success', 'inactive' => 'secondary', 'expired' => 'danger', 'disabled' => 'dark']; $today = date('Y-m-d'); ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="mb-3 d-flex gap-2">
    <a href="<?= site_url('admin/coupons') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back</a>
    <a href="<?= site_url('admin/coupons/' . $coupon['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-header fw-semibold">Coupon <code><?= esc($coupon['code']) ?></code></div><div class="card-body">
            <dl class="row mb-0 small">
                <dt class="col-5 text-secondary fw-normal">Status</dt><dd class="col-7"><span class="badge text-bg-<?= esc($sb[$coupon['status']] ?? 'secondary', 'attr') ?>"><?= esc($coupon['status']) ?></span></dd>
                <dt class="col-5 text-secondary fw-normal">Promotion</dt><dd class="col-7"><?= esc($coupon['promotion'] ?? '—') ?></dd>
                <dt class="col-5 text-secondary fw-normal">Used</dt><dd class="col-7"><?= (int) ($coupon['used_count'] ?? 0) ?><?= ! empty($coupon['usage_limit']) ? ' / ' . (int) $coupon['usage_limit'] : '' ?></dd>
                <dt class="col-5 text-secondary fw-normal">Per-user limit</dt><dd class="col-7"><?= esc($coupon['per_user_limit'] ?: '—') ?></dd>
                <dt class="col-5 text-secondary fw-normal">Valid from</dt><dd class="col-7"><?= esc($coupon['valid_from'] ?: '—') ?></dd>
                <dt class="col-5 text-secondary fw-normal">Valid to</dt><dd class="col-7"><?= esc($coupon['valid_to'] ?: '—') ?><?php if (! empty($coupon['valid_to']) && $coupon['valid_to'] < $today): ?> <span class="badge text-bg-danger">expired</span><?php endif; ?></dd>
            </dl>
        </div></div>
        <div class="row g-2">
            <div class="col-6"><div class="card text-center"><div class="card-body py-3"><div class="h4 mb-0"><?= (int) $stats['count'] ?></div><div class="small text-secondary">redemptions</div></div></div></div>
            <div class="col-6"><div class="card text-center"><div class="card-body py-3"><div class="h4 mb-0">₹<?= esc(number_format($stats['total'], 0)) ?></div><div class="small text-secondary">total discount</div></div></div></div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card"><div class="card-header fw-semibold">Redemption history</div>
            <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>When</th><th>Customer</th><th>Order</th><th class="text-end">Discount</th></tr></thead>
                <tbody>
                <?php foreach ($redemptions as $r): ?>
                    <tr>
                        <td class="small text-secondary"><?= esc($r['redeemed_at'] ?? '') ?></td>
                        <td><?= esc($r['customer'] ?? '—') ?></td>
                        <td><?php if (! empty($r['order_id'])): ?><a href="<?= site_url('admin/orders/' . (int) $r['order_id']) ?>"><?= esc($r['order_no'] ?? '') ?></a><?php else: ?><?= esc($r['order_no'] ?? '—') ?><?php endif; ?></td>
                        <td class="text-end">₹<?= esc(number_format((float) $r['discount_amount'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($redemptions)): ?><tr><td colspan="4" class="text-center text-secondary py-4">Not redeemed yet.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
