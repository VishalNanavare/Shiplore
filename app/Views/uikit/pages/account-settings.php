<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="card uk-card"><div class="card-body">
    <ul class="nav nav-pills mb-4 gap-1">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#st-gen"><i class="bi bi-person me-1"></i>General</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#st-sec"><i class="bi bi-shield-lock me-1"></i>Security</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#st-not"><i class="bi bi-bell me-1"></i>Notifications</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#st-bill"><i class="bi bi-credit-card me-1"></i>Billing</button></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="st-gen">
            <div class="row g-3" style="max-width:640px">
                <div class="col-md-6"><label class="form-label">First name</label><input class="form-control" value="Alex"></div>
                <div class="col-md-6"><label class="form-label">Last name</label><input class="form-control" value="Doe"></div>
                <div class="col-md-8"><label class="form-label">Email</label><input class="form-control" value="user@example.com"></div>
                <div class="col-md-4"><label class="form-label">Timezone</label><select class="form-select"><option>IST (UTC+5:30)</option><option>UTC</option></select></div>
                <div class="col-12"><button class="btn btn-primary">Save changes</button> <button class="btn btn-light">Cancel</button></div>
            </div>
        </div>
        <div class="tab-pane fade" id="st-sec">
            <div class="row g-3" style="max-width:520px">
                <div class="col-12"><label class="form-label">Current password</label><input type="password" class="form-control"></div>
                <div class="col-md-6"><label class="form-label">New password</label><input type="password" class="form-control"></div>
                <div class="col-md-6"><label class="form-label">Confirm</label><input type="password" class="form-control"></div>
                <div class="col-12"><hr><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="2fa" checked><label class="form-check-label" for="2fa">Two-factor authentication</label></div></div>
                <div class="col-12"><button class="btn btn-primary">Update password</button></div>
            </div>
        </div>
        <div class="tab-pane fade" id="st-not">
            <?php foreach (['New order placed'=>true,'Vendor application'=>true,'Settlement processed'=>false,'Weekly summary email'=>true,'Security alerts'=>true] as $label=>$on): ?>
                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" <?= $on?'checked':'' ?>><label class="form-check-label"><?= $label ?></label></div>
            <?php endforeach; ?>
        </div>
        <div class="tab-pane fade" id="st-bill">
            <div class="alert alert-primary d-flex justify-content-between align-items-center"><div><strong>Enterprise plan</strong> — ₹24,999 / mo · renews 01 Jul 2026</div><button class="btn btn-sm btn-primary">Manage</button></div>
            <table class="table"><thead><tr><th>Invoice</th><th>Date</th><th>Amount</th><th></th></tr></thead>
                <tbody>
                    <tr><td>INV-2026-05</td><td>01 May 2026</td><td>₹24,999</td><td><a href="<?= site_url('ui-kit/invoice') ?>">View</a></td></tr>
                    <tr><td>INV-2026-04</td><td>01 Apr 2026</td><td>₹24,999</td><td><a href="<?= site_url('ui-kit/invoice') ?>">View</a></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div></div>
<?= $this->endSection() ?>
