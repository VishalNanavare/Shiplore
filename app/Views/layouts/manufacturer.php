<?= $this->include('partials/_head') ?>
<body>
<div class="app-shell" id="appShell">
    <?= $this->include('partials/_manufacturer_sidebar') ?>
    <div class="sidebar-backdrop"></div>
    <div class="app-main">
        <?= $this->include('partials/_topbar') ?>
        <?= $this->include('partials/_impersonation_banner') ?>
        <main class="app-content">
            <?= $this->renderSection('content') ?>
        </main>
        <?= $this->include('partials/_footer') ?>
    </div>
</div>
<?= $this->include('partials/_scripts') ?>
<?= $this->renderSection('scripts') ?>
</body>
</html>
