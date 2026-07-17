<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?= $this->include('partials/_product_form_body') ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/quill/quill.min.js') ?>"></script>
<script src="<?= asset('plugins/select2/select2.min.js') ?>"></script>
<script src="<?= asset('js/product-form.js') ?>"></script>
<?= $this->endSection() ?>
