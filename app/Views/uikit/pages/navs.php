<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Navigation variants — basic, vertical, fill/justified and with icons.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Basic & with icons</h2>
        <ul class="nav mb-4">
            <li class="nav-item"><a class="nav-link active" href="#">Active</a></li>
            <li class="nav-item"><a class="nav-link" href="#">Link</a></li>
            <li class="nav-item"><a class="nav-link" href="#">Another</a></li>
            <li class="nav-item"><a class="nav-link disabled">Disabled</a></li>
        </ul>
        <ul class="nav nav-pills">
            <li class="nav-item"><a class="nav-link active" href="#"><i class="bi bi-house me-1"></i>Home</a></li>
            <li class="nav-item"><a class="nav-link" href="#"><i class="bi bi-person me-1"></i>Profile</a></li>
            <li class="nav-item"><a class="nav-link" href="#"><i class="bi bi-gear me-1"></i>Settings</a></li>
        </ul>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Fill & justified</h2>
        <ul class="nav nav-pills nav-fill mb-3 bg-light rounded p-1">
            <li class="nav-item"><a class="nav-link active" href="#">Daily</a></li>
            <li class="nav-item"><a class="nav-link" href="#">Weekly</a></li>
            <li class="nav-item"><a class="nav-link" href="#">Monthly</a></li>
        </ul>
        <h2 class="uk-section-title mb-2">Vertical</h2>
        <div class="nav flex-column nav-pills" style="max-width:200px">
            <a class="nav-link active" href="#">Account</a>
            <a class="nav-link" href="#">Notifications</a>
            <a class="nav-link" href="#">Billing</a>
        </div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
