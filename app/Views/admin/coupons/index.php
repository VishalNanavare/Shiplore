<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $badge = ['active' => 'success', 'disabled' => 'secondary', 'exhausted' => 'warning', 'expired' => 'danger']; ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Coupons</span><a href="<?= site_url('admin/coupons/new') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Coupon</a></div>
    <div class="card-body">
        <?php if (empty($coupons)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-ticket-perforated display-6 d-block mb-2"></i>No coupons yet.</div>
        <?php else: ?>
        <div class="table-responsive"><table id="couponsTable" class="table table-hover align-middle w-100">
            <thead class="table-light"><tr><th>Code</th><th>Promotion</th><th>Used / Limit</th><th>Per User</th><th>Validity</th><th>Status</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($coupons as $c): ?>
                <tr>
                    <td class="fw-semibold"><code class="uk-code"><?= esc($c['code']) ?></code></td>
                    <td><?= esc($c['promotion'] ?? '—') ?></td>
                    <td><?= esc($c['used_count']) ?> / <?= esc($c['usage_limit'] ?? '∞') ?></td>
                    <td><?= esc($c['per_user_limit'] ?? '∞') ?></td>
                    <td class="small text-secondary"><?= esc($c['valid_from'] ?? '—') ?><?= $c['valid_to'] ? ' → ' . esc($c['valid_to']) : '' ?></td>
                    <td><span class="badge text-bg-<?= esc($badge[$c['status']] ?? 'secondary', 'attr') ?>"><?= esc($c['status']) ?></span></td>
                    <td class="text-end"><a href="<?= site_url('admin/coupons/' . $c['id']) ?>" class="btn btn-sm btn-outline-primary" title="Usage &amp; details"><i class="bi bi-eye"></i></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/datatables/jquery.dataTables.min.js') ?>"></script>
<script src="<?= asset('plugins/datatables/dataTables.bootstrap5.min.js') ?>"></script>
<script>$(function(){ if($.fn.DataTable && document.getElementById('couponsTable')) $('#couponsTable').DataTable({ pageLength: 10 }); });</script>
<?= $this->endSection() ?>
