<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Tabs & pills — basic, with icons, pills, vertical, fill/justified and card-integrated.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Tabs with icons</h2>
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#t1"><i class="bi bi-house me-1"></i>Overview</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t2"><i class="bi bi-person me-1"></i>Profile</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t3"><i class="bi bi-gear me-1"></i>Settings</button></li>
            <li class="nav-item"><button class="nav-link" disabled>Disabled</button></li>
        </ul>
        <div class="tab-content pt-3 small">
            <div class="tab-pane fade show active" id="t1">Overview — account summary.</div>
            <div class="tab-pane fade" id="t2">Profile — personal details.</div>
            <div class="tab-pane fade" id="t3">Settings — preferences.</div>
        </div>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Pills & fill</h2>
        <ul class="nav nav-pills mb-3">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#p1">Home</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#p2">Messages</button></li>
        </ul>
        <div class="tab-content small mb-3"><div class="tab-pane fade show active" id="p1">Home pane.</div><div class="tab-pane fade" id="p2">Messages pane.</div></div>
        <ul class="nav nav-pills nav-justified bg-light rounded p-1">
            <li class="nav-item"><a class="nav-link active" href="#">Daily</a></li>
            <li class="nav-item"><a class="nav-link" href="#">Weekly</a></li>
            <li class="nav-item"><a class="nav-link" href="#">Monthly</a></li>
        </ul>
    </div></div></div>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Vertical pills</h2>
        <div class="d-flex">
            <ul class="nav nav-pills flex-column me-3" style="min-width:140px">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#v1">General</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#v2">Billing</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#v3">Security</button></li>
            </ul>
            <div class="tab-content flex-grow-1 small">
                <div class="tab-pane fade show active" id="v1">General settings.</div>
                <div class="tab-pane fade" id="v2">Billing settings.</div>
                <div class="tab-pane fade" id="v3">Security settings.</div>
            </div>
        </div>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100">
        <div class="card-header p-0"><ul class="nav nav-tabs card-header-tabs px-2 pt-2">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#c1">Details</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#c2">Reviews</button></li>
        </ul></div>
        <div class="card-body tab-content small">
            <div class="tab-pane fade show active" id="c1">Card-integrated tabs in the header.</div>
            <div class="tab-pane fade" id="c2">Reviews content.</div>
        </div>
    </div></div>
</div>
<?= $this->endSection() ?>
