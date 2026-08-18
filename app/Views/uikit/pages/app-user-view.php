<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card uk-card mb-3"><div class="card-body text-center">
            <span class="rounded-circle bg-primary-subtle text-primary d-grid mx-auto mb-2" style="width:84px;height:84px;place-items:center;font-weight:700;font-size:1.8rem">RI</span>
            <h2 class="h5 mb-0">Riya Iyer</h2>
            <div class="text-secondary small">riya@example.com</div>
            <span class="badge bg-danger-subtle text-danger mt-2">Admin</span>
            <div class="row text-center mt-3">
                <div class="col-4"><div class="h6 mb-0">128</div><div class="text-secondary small">Tasks</div></div>
                <div class="col-4"><div class="h6 mb-0">24</div><div class="text-secondary small">Projects</div></div>
                <div class="col-4"><div class="h6 mb-0">98%</div><div class="text-secondary small">Done</div></div>
            </div>
            <div class="d-grid gap-2 mt-3"><a href="<?= site_url('ui-kit/app-user-edit') ?>" class="btn btn-primary"><i class="bi bi-pencil me-1"></i>Edit</a></div>
        </div></div>
        <div class="card uk-card"><div class="card-body">
            <h2 class="uk-section-title mb-3">Details</h2>
            <ul class="list-unstyled small mb-0">
                <li class="d-flex justify-content-between py-1"><span class="text-secondary">Status</span><span class="badge bg-success-subtle text-success">Active</span></li>
                <li class="d-flex justify-content-between py-1"><span class="text-secondary">Plan</span><span>Enterprise</span></li>
                <li class="d-flex justify-content-between py-1"><span class="text-secondary">Mobile</span><span>+91 98xxx</span></li>
                <li class="d-flex justify-content-between py-1"><span class="text-secondary">Joined</span><span>Jan 2026</span></li>
            </ul>
        </div></div>
    </div>
    <div class="col-lg-8"><div class="card uk-card"><div class="card-body">
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#uv-act">Activity</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#uv-perm">Permissions</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#uv-sec">Security</button></li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="uv-act"><div class="uk-timeline">
                <div class="uk-timeline-item"><div class="fw-medium small">Approved vendor "Fresh Foods"</div><div class="text-secondary small">2h ago</div></div>
                <div class="uk-timeline-item"><div class="fw-medium small">Updated commission plan</div><div class="text-secondary small">Yesterday</div></div>
                <div class="uk-timeline-item"><div class="fw-medium small">Logged in from new device</div><div class="text-secondary small">2 days ago</div></div>
            </div></div>
            <div class="tab-pane fade" id="uv-perm">
                <table class="table table-sm"><thead><tr><th>Module</th><th class="text-center">View</th><th class="text-center">Edit</th><th class="text-center">Delete</th></tr></thead>
                <tbody>
                    <?php foreach (['Vendors','Shops','Orders','Settlements'] as $mod): ?>
                        <tr><td><?= $mod ?></td><td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td><td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td><td class="text-center"><i class="bi bi-x-circle text-secondary"></i></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
            </div>
            <div class="tab-pane fade" id="uv-sec">
                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" checked><label class="form-check-label">Two-factor authentication</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox"><label class="form-check-label">Login alerts</label></div>
            </div>
        </div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
