<?= $this->extend('layouts/main') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $badge = ['pending' => 'warning', 'published' => 'success', 'rejected' => 'danger', 'flagged' => 'info']; ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Review Moderation</span><span class="text-secondary small"><?= count($reviews) ?> total</span></div>
    <div class="card-body">
        <?php if (empty($reviews)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-star display-6 d-block mb-2"></i>No reviews yet.</div>
        <?php else: ?>
        <div class="table-responsive" data-ajax-region><table id="reviewsTable" class="table table-hover align-middle w-100">
            <thead class="table-light"><tr><th>Product</th><th>Rating</th><th>Review</th><th>Verified</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($reviews as $r): ?>
                <tr>
                    <td class="fw-semibold"><?= esc($r['product'] ?? '—') ?></td>
                    <td class="uk-rating text-warning text-nowrap"><?php for ($i = 1; $i <= 5; $i++) echo $i <= (int) $r['rating'] ? '★' : '☆'; ?></td>
                    <td style="max-width:320px"><div class="fw-medium small"><?= esc($r['title'] ?? '') ?></div><div class="text-secondary small text-truncate"><?= esc($r['body'] ?? '') ?></div></td>
                    <td><?= $r['is_verified_purchase'] ? '<span class="badge bg-success-subtle text-success">Yes</span>' : '<span class="text-secondary small">No</span>' ?></td>
                    <td><span class="badge text-bg-<?= esc($badge[$r['status']] ?? 'secondary', 'attr') ?>"><?= esc($r['status']) ?></span></td>
                    <td class="text-end">
                        <?php if (in_array($r['status'], ['pending', 'flagged'], true)): ?>
                            <form method="post" action="<?= site_url('admin/reviews/' . $r['id'] . '/publish') ?>" class="d-inline" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Publish</button></form>
                            <form method="post" action="<?= site_url('admin/reviews/' . $r['id'] . '/reject') ?>" class="d-inline" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> Reject</button></form>
                        <?php else: ?>
                            <form method="post" action="<?= site_url('admin/reviews/' . $r['id'] . '/restore') ?>" class="d-inline" data-ajax-refresh><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i> Restore</button></form>
                        <?php endif; ?>
                    </td>
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
<script>$(function(){ if($.fn.DataTable && document.getElementById('reviewsTable')) $('#reviewsTable').DataTable({ pageLength: 10, columnDefs:[{orderable:false,targets:5}] }); });</script>
<?= $this->endSection() ?>
