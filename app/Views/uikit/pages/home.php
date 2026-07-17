<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="card uk-card mb-4">
    <div class="card-body p-4 d-md-flex align-items-center justify-content-between">
        <div>
            <h2 class="h4 mb-1">Shiplore UI Kit</h2>
            <p class="text-secondary mb-0" style="max-width:48rem">
                A standalone design reference — dashboards, components, extensions, forms, tables and charts —
                so the team has a single living style guide. These pages are intentionally <strong>not wired</strong>
                to the application; data shown is sample data.
            </p>
        </div>
        <div class="d-none d-md-block display-6 text-primary"><i class="bi bi-palette2"></i></div>
    </div>
</div>

<div class="row g-3">
    <?php
    $sections = [
        ['Dashboards', 'bi-speedometer2', 'primary', '4 ready-made dashboards', 'dashboard-analytics'],
        ['Components', 'bi-puzzle', 'success', '18 Bootstrap component demos', 'badges'],
        ['Extensions', 'bi-box', 'warning', 'SweetAlert2 & Toastr', 'sweetalert'],
        ['Forms & Tables', 'bi-ui-checks-grid', 'info', 'Elements, validation, DataTables', 'form-elements'],
        ['Charts', 'bi-bar-chart-line', 'danger', 'Chart.js line / bar / pie', 'charts-chartjs'],
        ['User Interface', 'bi-brush', 'secondary', 'Typography, colors, icons', 'typography'],
    ];
    foreach ($sections as [$name, $icon, $color, $desc, $slug]): ?>
        <div class="col-sm-6 col-lg-4">
            <a href="<?= site_url('ui-kit/' . $slug) ?>" class="card uk-card h-100 text-decoration-none text-reset">
                <div class="card-body">
                    <div class="uk-stat-icon bg-<?= $color ?>-subtle text-<?= $color ?> mb-3"><i class="bi <?= $icon ?>"></i></div>
                    <h3 class="h6 mb-1"><?= esc($name) ?></h3>
                    <p class="text-secondary small mb-0"><?= esc($desc) ?></p>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
<?= $this->endSection() ?>
