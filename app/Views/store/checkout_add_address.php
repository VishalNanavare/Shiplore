<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<nav class="small mb-2 text-secondary"><a href="<?= site_url('store/checkout') ?>" class="text-decoration-none">Delivery address</a> › <?= esc($title) ?></nav>
<h1 class="h4 mb-3"><?= esc($title) ?></h1>

<div class="card"><?= view('partials/_store_address_form', [
    'action'      => site_url('store/checkout/save-address'),
    'e'           => $edit,
    'profile'     => $profile,
    'mapsKey'     => $mapsKey,
    'submitLabel' => 'Save & continue',
    'formAttrs'   => '',
]) ?></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>window.__caMapsKey = <?= json_encode($mapsKey) ?>;</script>
<script src="<?= asset('js/checkout-address.js') ?>"></script>
<?= $this->endSection() ?>
