<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Flexible content containers — basic, header/footer, statistics, covers, colored, tabs, list, overlay, actions and groups.</p>

<!-- Basic -->
<h2 class="uk-section-title mb-2">Basic</h2>
<div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3"><div class="card uk-card h-100"><div class="card-body">
        <h5 class="card-title">Card title</h5><h6 class="card-subtitle mb-2 text-secondary">Subtitle</h6>
        <p class="card-text small">Quick example text to build on the card title.</p><a href="#" class="card-link">Action</a><a href="#" class="card-link">More</a>
    </div></div></div>
    <div class="col-md-6 col-lg-3"><div class="card uk-card h-100">
        <div class="card-header fw-semibold">Header</div><div class="card-body"><p class="card-text small mb-0">Card with a header strip.</p></div><div class="card-footer text-secondary small">2 mins ago</div>
    </div></div>
    <div class="col-md-6 col-lg-3"><div class="card uk-card h-100 border-primary"><div class="card-body">
        <h5 class="card-title text-primary">Bordered</h5><p class="card-text small mb-0">Accent with a colored border.</p></div></div></div>
    <div class="col-md-6 col-lg-3"><div class="card uk-card h-100 text-center"><div class="card-body">
        <span class="rounded-circle bg-info-subtle text-info d-grid mx-auto mb-2" style="width:54px;height:54px;place-items:center;font-size:1.4rem"><i class="bi bi-person"></i></span>
        <h6 class="mb-0">Profile</h6><div class="text-secondary small">Designer</div><button class="btn btn-sm btn-outline-primary mt-2">Follow</button></div></div></div>
</div>

<!-- Statistics -->
<h2 class="uk-section-title mb-2">Statistics</h2>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3"><div class="card uk-card"><div class="card-body uk-stat">
        <div class="uk-stat-icon bg-primary-subtle text-primary"><i class="bi bi-cart-check"></i></div>
        <div><div class="text-secondary small">Orders</div><div class="h4 mb-0">1,932</div><span class="small text-success"><i class="bi bi-arrow-up"></i> 9%</span></div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card uk-card"><div class="card-body d-flex justify-content-between">
        <div><div class="text-secondary small">Revenue</div><div class="h4 mb-0">₹8.4L</div><span class="small text-success">+18% MoM</span></div>
        <div class="uk-stat-icon bg-success-subtle text-success"><i class="bi bi-currency-rupee"></i></div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card uk-card"><div class="card-body">
        <div class="d-flex justify-content-between"><span class="text-secondary small">Conversion</span><span class="badge bg-warning-subtle text-warning">62%</span></div>
        <div class="h4 my-1">3,204</div><div class="progress" style="height:5px"><div class="progress-bar bg-warning" style="width:62%"></div></div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card uk-card text-bg-primary"><div class="card-body">
        <div class="d-flex justify-content-between"><div><div class="small opacity-75">Customers</div><div class="h4 mb-0">6,540</div></div><i class="bi bi-people fs-2 opacity-50"></i></div></div></div></div>
</div>

<!-- Cover / colored -->
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card uk-card h-100">
        <div style="height:120px;background:linear-gradient(120deg,#5b6ef5,#00cfe8)" class="d-grid"><i class="bi bi-image align-self-center text-center text-white fs-2"></i></div>
        <div class="card-body"><h5 class="card-title">Image cover</h5><p class="card-text small mb-0">A card with a media header.</p></div></div></div>
    <div class="col-md-4"><div class="card uk-card text-bg-success h-100"><div class="card-body"><h5 class="card-title">Filled</h5><p class="card-text small mb-0">Use <code class="uk-code text-white">.text-bg-success</code>.</p></div></div></div>
    <div class="col-md-4"><div class="card uk-card h-100 bg-warning-subtle border-0"><div class="card-body"><h5 class="card-title text-warning-emphasis">Soft tone</h5><p class="card-text small mb-0">Subtle background variant.</p></div></div></div>
</div>

<!-- Tabs / list / actions -->
<div class="row g-3 mb-4">
    <div class="col-lg-4"><div class="card uk-card h-100">
        <div class="card-header p-0"><ul class="nav nav-tabs card-header-tabs px-2 pt-2">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#cd1">Activity</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#cd2">Stats</button></li>
        </ul></div>
        <div class="card-body tab-content">
            <div class="tab-pane fade show active small" id="cd1">Recent activity feed…</div>
            <div class="tab-pane fade small" id="cd2">Statistics content…</div>
        </div></div></div>
    <div class="col-lg-4"><div class="card uk-card h-100">
        <div class="card-header fw-semibold">List card</div>
        <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex justify-content-between small">Inbox <span class="badge bg-primary rounded-pill">14</span></li>
            <li class="list-group-item d-flex justify-content-between small">Sent <span class="badge bg-secondary rounded-pill">120</span></li>
            <li class="list-group-item small text-secondary">Archived</li>
        </ul></div></div>
    <div class="col-lg-4"><div class="card uk-card h-100" id="actCard">
        <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Actions</span>
            <div class="d-flex gap-1">
                <button class="btn btn-sm btn-light" onclick="document.getElementById('actBody').classList.toggle('d-none')"><i class="bi bi-chevron-up"></i></button>
                <button class="btn btn-sm btn-light" onclick="if(window.toastr)toastr.info('Refreshed')"><i class="bi bi-arrow-clockwise"></i></button>
                <button class="btn btn-sm btn-light text-danger" onclick="document.getElementById('actCard').remove()"><i class="bi bi-x-lg"></i></button>
            </div></div>
        <div class="card-body" id="actBody"><p class="card-text small mb-0">Collapse, refresh or remove this card via the header toolbar.</p></div></div></div>
</div>

<!-- Horizontal / overlay -->
<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card"><div class="row g-0">
        <div class="col-4 d-grid" style="background:#fbfcfe"><i class="bi bi-box-seam align-self-center text-center text-primary fs-1"></i></div>
        <div class="col-8"><div class="card-body"><h5 class="card-title">Horizontal</h5><p class="card-text small text-secondary mb-0">Media beside content using the grid.</p></div></div>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card text-white"><div style="height:160px;background:linear-gradient(120deg,#1e2233,#5b6ef5)"></div>
        <div class="card-img-overlay d-flex flex-column justify-content-end"><h5 class="card-title">Overlay</h5><p class="card-text small mb-0">Text over a media background.</p></div></div></div>
</div>
<?= $this->endSection() ?>
