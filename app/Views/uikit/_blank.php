<?php
/**
 * UI Kit blank shell — full-screen, menu-less layout used by the auth and
 * error demo pages. Expects: $title. A small link returns to the kit.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'Page') ?> · UI Kit</title>
    <link rel="icon" href="<?= asset('images/logo.svg') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/uikit.css') ?>">
    <?= $this->renderSection('head') ?>
</head>
<body class="uk-body">
<div class="uk-blank">
    <div class="w-100" style="max-width:960px">
        <div class="text-center mb-3">
            <a href="<?= site_url('ui-kit') ?>" class="text-decoration-none text-secondary small"><i class="bi bi-arrow-left me-1"></i>Back to UI Kit</a>
        </div>
        <?= $this->renderSection('content') ?>
    </div>
</div>
<script src="<?= asset('vendor/jquery/jquery.min.js') ?>"></script>
<script src="<?= asset('vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('js/uikit.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
