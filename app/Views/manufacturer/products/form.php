<?= $this->extend('layouts/manufacturer') ?>

<?= $this->section('content') ?>
<?php
// The SAME shell the vendor and admin panels render — sticky bar, context rail,
// 11-section accordion, completeness meter, autosave. It was previously a bespoke
// 6-field form here, which is what made this screen look and behave like a different
// product than the vendor one.
//
// Two things the partial takes from us rather than assuming: the location picker is a
// manufacturing unit (mshop_id) instead of a shop, and price/SKU/stock live on the
// Variants page for every panel, so they are deliberately absent here.
?>
<?= $this->include('partials/_product_form_body') ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/quill/quill.min.js') ?>"></script>
<script src="<?= asset('plugins/select2/select2.min.js') ?>"></script>
<script src="<?= asset('js/product-form.js') ?>"></script>
<?= $this->endSection() ?>
