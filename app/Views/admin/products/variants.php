<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?= $this->include('partials/_product_variants_body') ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/select2/select2.min.js') ?>"></script>
<?= $this->endSection() ?>
