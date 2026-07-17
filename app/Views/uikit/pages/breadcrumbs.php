<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Location indicators — default, icons, custom dividers, no-divider, and in a page header.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Default</h2>
        <nav class="mb-3"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="#">Home</a></li><li class="breadcrumb-item"><a href="#">Library</a></li><li class="breadcrumb-item active">Data</li></ol></nav>
        <h2 class="uk-section-title mb-3">With icons</h2>
        <nav class="mb-3"><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="#"><i class="bi bi-house-door me-1"></i>Home</a></li>
            <li class="breadcrumb-item"><a href="#"><i class="bi bi-shop me-1"></i>Vendors</a></li>
            <li class="breadcrumb-item active"><i class="bi bi-person me-1"></i>Profile</li>
        </ol></nav>
        <h2 class="uk-section-title mb-3">Icon-only home</h2>
        <nav><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="#"><i class="bi bi-house-door"></i></a></li><li class="breadcrumb-item"><a href="#">Orders</a></li><li class="breadcrumb-item active">#10293</li></ol></nav>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Custom dividers</h2>
        <nav class="mb-2" style="--bs-breadcrumb-divider:'›'"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="#">Dashboard</a></li><li class="breadcrumb-item"><a href="#">Orders</a></li><li class="breadcrumb-item active">Detail</li></ol></nav>
        <nav class="mb-2" style="--bs-breadcrumb-divider:'»'"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="#">A</a></li><li class="breadcrumb-item"><a href="#">B</a></li><li class="breadcrumb-item active">C</li></ol></nav>
        <nav style="--bs-breadcrumb-divider:url(&#34;data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%278%27 height=%278%27%3E%3Cpath d=%27M2.5 0L1 1.5 3.5 4 1 6.5 2.5 8l4-4-4-4z%27 fill=%27%236c757d%27/%3E%3C/svg%3E&#34;)"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="#">Home</a></li><li class="breadcrumb-item active">SVG divider</li></ol></nav>
        <h2 class="uk-section-title mt-3 mb-2">Page header</h2>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 border rounded p-3">
            <div><h6 class="mb-1">Vendor Detail</h6><nav><ol class="breadcrumb mb-0 small"><li class="breadcrumb-item"><a href="#">Admin</a></li><li class="breadcrumb-item"><a href="#">Vendors</a></li><li class="breadcrumb-item active">Fresh Foods</li></ol></nav></div>
            <button class="btn btn-sm btn-primary"><i class="bi bi-pencil me-1"></i>Edit</button>
        </div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
