<?= $this->extend('layouts/rider') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success py-2 small"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger py-2 small"><?= esc($m) ?></div><?php endif; ?>

<h1 class="h5 mb-2">My trips</h1>
<div class="d-flex gap-1 mb-3 overflow-auto pb-1">
    <?php foreach (['' => 'All', 'active' => 'Active', 'delivered' => 'Delivered', 'failed' => 'Failed'] as $sv => $sl): ?>
        <a href="<?= site_url('rider/deliveries' . ($sv ? '?status=' . $sv : '')) ?>" class="btn btn-sm <?= ($status ?? '') === $sv ? 'btn-rd' : 'btn-outline-secondary' ?>"><?= $sl ?></a>
    <?php endforeach; ?>
</div>

<?php foreach ($deliveries as $t): ?>
    <?= view('rider/_trip_card', ['t' => $t, 'offer' => $t['assignment_status'] === 'offered']) ?>
<?php endforeach; ?>
<?php if (empty($deliveries)): ?><div class="text-center text-secondary py-5"><i class="bi bi-inbox display-6 d-block mb-2"></i>No trips here.</div><?php endif; ?>
<?= $this->endSection() ?>
