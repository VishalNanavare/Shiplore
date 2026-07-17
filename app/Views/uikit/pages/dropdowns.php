<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Toggleable overlays — variants, directions, split, dark, rich menus, headers, forms and alignment.</p>

<div class="card uk-card mb-3"><div class="card-body">
    <h2 class="uk-section-title mb-3">Variants & directions</h2>
    <div class="d-flex gap-2 flex-wrap">
        <?php foreach (['primary','success','danger','warning','info'] as $c): ?>
            <div class="dropdown"><button class="btn btn-<?= $c ?> dropdown-toggle" data-bs-toggle="dropdown"><?= ucfirst($c) ?></button><ul class="dropdown-menu"><li><a class="dropdown-item" href="#">Action</a></li><li><a class="dropdown-item" href="#">Another</a></li></ul></div>
        <?php endforeach; ?>
        <div class="dropup"><button class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown">Dropup</button><ul class="dropdown-menu"><li><a class="dropdown-item" href="#">Up</a></li></ul></div>
        <div class="dropend"><button class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown">Dropend</button><ul class="dropdown-menu"><li><a class="dropdown-item" href="#">Side</a></li></ul></div>
        <div class="dropstart"><button class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown">Dropstart</button><ul class="dropdown-menu"><li><a class="dropdown-item" href="#">Left</a></li></ul></div>
    </div>
</div></div>

<div class="row g-3">
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Split & sizes</h2>
        <div class="btn-group mb-2"><button class="btn btn-success">Save</button><button class="btn btn-success dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button><ul class="dropdown-menu"><li><a class="dropdown-item" href="#">Save & new</a></li><li><a class="dropdown-item" href="#">Save as draft</a></li></ul></div><br>
        <div class="btn-group btn-group-sm mb-2"><button class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Small</button><ul class="dropdown-menu"><li><a class="dropdown-item" href="#">Item</a></li></ul></div><br>
        <div class="btn-group btn-group-lg"><button class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Large</button><ul class="dropdown-menu"><li><a class="dropdown-item" href="#">Item</a></li></ul></div>
    </div></div></div>
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Rich menu</h2>
        <div class="dropdown"><button class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-person-circle me-1"></i>Account</button>
            <ul class="dropdown-menu">
                <li><h6 class="dropdown-header">Signed in as Admin</h6></li>
                <li><a class="dropdown-item" href="#"><i class="bi bi-person me-2"></i>Profile</a></li>
                <li><a class="dropdown-item d-flex justify-content-between" href="#"><span><i class="bi bi-bell me-2"></i>Alerts</span><span class="badge bg-danger rounded-pill">3</span></a></li>
                <li><a class="dropdown-item active" href="#"><i class="bi bi-gear me-2"></i>Settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="#"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div></div></div>
    <div class="col-lg-4"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Dark & form</h2>
        <div class="dropdown mb-3"><button class="btn btn-dark dropdown-toggle" data-bs-toggle="dropdown">Dark menu</button>
            <ul class="dropdown-menu dropdown-menu-dark"><li><a class="dropdown-item active" href="#">Action</a></li><li><a class="dropdown-item" href="#">Another</a></li><li><hr class="dropdown-divider"></li><li><a class="dropdown-item" href="#">Separated</a></li></ul></div>
        <div class="dropdown"><button class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">Form</button>
            <div class="dropdown-menu p-3" style="min-width:240px">
                <div class="mb-2"><input class="form-control form-control-sm" placeholder="Email"></div>
                <div class="mb-2"><input type="password" class="form-control form-control-sm" placeholder="Password"></div>
                <button class="btn btn-sm btn-primary w-100">Sign in</button>
            </div>
        </div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
