<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>
<?php
// The same variant builder the vendor and admin panels render. The controller hands
// it priceA = making_price instead of mrp; everything else is identical.
?>
<?= $this->include('partials/_product_variants_body') ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/select2/select2.min.js') ?>"></script>
<?= $this->endSection() ?>
