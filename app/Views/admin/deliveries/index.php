<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $badge = ['pending' => 'secondary', 'assigned' => 'info', 'picked_up' => 'info', 'out_for_delivery' => 'warning', 'delivered' => 'success', 'failed' => 'danger', 'returned' => 'danger']; ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Delivery Monitoring</span><span class="text-secondary small"><?= count($deliveries) ?> total</span></div>
    <div class="card-body">
        <?php if (empty($deliveries)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-truck display-6 d-block mb-2"></i>No deliveries yet.</div>
        <?php else: ?>
        <div class="table-responsive"><table id="deliveriesTable" class="table table-hover align-middle w-100">
            <thead class="table-light"><tr><th>Sub-order</th><th>Shop</th><th>Mode</th><th>Fee</th><th>ETA</th><th>Status</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($deliveries as $d): ?>
                <tr>
                    <td class="fw-semibold small"><?= esc($d['sub_order_no'] ?? '—') ?></td>
                    <td><?= esc($d['shop'] ?? '—') ?></td>
                    <td class="text-capitalize small"><?= esc($d['mode'] ?? '—') ?></td>
                    <td>₹<?= esc(number_format((float) ($d['delivery_fee'] ?? 0), 2)) ?></td>
                    <td class="small text-secondary"><?= esc(substr((string) ($d['eta_at'] ?? ''), 0, 16) ?: '—') ?></td>
                    <td><span class="badge text-bg-<?= esc($badge[$d['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $d['status'])) ?></span></td>
                    <td class="text-end"><a href="<?= site_url('admin/deliveries/' . $d['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('deliveriesTable')) $('#deliveriesTable').DataTable({ pageLength: 10 }); });</script>
<?= $this->endSection() ?>
