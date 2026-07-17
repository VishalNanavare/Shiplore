<?= $this->include('partials/_head') ?>
<body>
<div class="auth-wrapper">
    <?= $this->renderSection('content') ?>
</div>
<?= $this->include('partials/_scripts') ?>
<?= $this->renderSection('scripts') ?>
</body>
</html>
