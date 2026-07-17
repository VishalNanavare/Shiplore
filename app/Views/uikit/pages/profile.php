<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="card uk-card overflow-hidden mb-3">
    <div style="height:120px;background:linear-gradient(120deg,#5b6ef5,#00cfe8)"></div>
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-end gap-3" style="margin-top:-48px">
            <span class="rounded-circle bg-white text-primary d-grid border border-3 border-white shadow-sm" style="width:96px;height:96px;place-items:center;font-weight:700;font-size:2rem">VN</span>
            <div class="flex-grow-1">
                <h2 class="h5 mb-0">Vishal Nanavare</h2>
                <div class="text-secondary small"><i class="bi bi-briefcase me-1"></i>Platform Administrator · <i class="bi bi-geo-alt ms-1 me-1"></i>Pune, IN</div>
            </div>
            <div><button class="btn btn-primary"><i class="bi bi-pencil me-1"></i>Edit Profile</button></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card uk-card mb-3"><div class="card-body">
            <h2 class="uk-section-title mb-3">About</h2>
            <ul class="list-unstyled small mb-0">
                <li class="mb-2"><i class="bi bi-envelope me-2 text-secondary"></i>vishal@commercehub.io</li>
                <li class="mb-2"><i class="bi bi-telephone me-2 text-secondary"></i>+91 98xxx xxxxx</li>
                <li class="mb-2"><i class="bi bi-calendar3 me-2 text-secondary"></i>Joined Jan 2026</li>
                <li><i class="bi bi-shield-check me-2 text-secondary"></i>Super Admin</li>
            </ul>
        </div></div>
        <div class="card uk-card"><div class="card-body">
            <h2 class="uk-section-title mb-3">Stats</h2>
            <div class="row text-center g-2">
                <div class="col-4"><div class="h5 mb-0">128</div><div class="text-secondary small">Vendors</div></div>
                <div class="col-4"><div class="h5 mb-0">2.4k</div><div class="text-secondary small">Orders</div></div>
                <div class="col-4"><div class="h5 mb-0">98%</div><div class="text-secondary small">Uptime</div></div>
            </div>
        </div></div>
    </div>
    <div class="col-lg-8">
        <div class="card uk-card"><div class="card-body">
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pf-act">Activity</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pf-proj">Projects</button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="pf-act">
                    <div class="uk-timeline">
                        <div class="uk-timeline-item"><div class="fw-medium small">Approved vendor "Fresh Foods"</div><div class="text-secondary small">2 hours ago</div></div>
                        <div class="uk-timeline-item"><div class="fw-medium small">Updated commission plan</div><div class="text-secondary small">Yesterday</div></div>
                        <div class="uk-timeline-item"><div class="fw-medium small">Resolved settlement dispute #482</div><div class="text-secondary small">2 days ago</div></div>
                    </div>
                </div>
                <div class="tab-pane fade" id="pf-proj">
                    <div class="d-flex justify-content-between small mb-1"><span>POS Offline Sync</span><span class="text-secondary">64%</span></div>
                    <div class="progress mb-3" style="height:7px"><div class="progress-bar" style="width:64%"></div></div>
                    <div class="d-flex justify-content-between small mb-1"><span>GST Integration</span><span class="text-secondary">28%</span></div>
                    <div class="progress" style="height:7px"><div class="progress-bar bg-warning" style="width:28%"></div></div>
                </div>
            </div>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
