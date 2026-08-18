<?= $this->extend('uikit/_layout') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row g-3 mb-3">
    <?php foreach ([['Total Users','6,540','people','primary'],['Active','5,980','person-check','success'],['Pending','420','person-dash','warning'],['Suspended','140','person-x','danger']] as $k): ?>
        <div class="col-6 col-xl-3"><div class="card uk-card"><div class="card-body uk-stat">
            <div class="uk-stat-icon bg-<?= $k[3] ?>-subtle text-<?= $k[3] ?>"><i class="bi bi-<?= $k[2] ?>"></i></div>
            <div><div class="text-secondary small"><?= $k[0] ?></div><div class="h5 mb-0"><?= $k[1] ?></div></div>
        </div></div></div>
    <?php endforeach; ?>
</div>

<div class="card uk-card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="uk-section-title mb-0">Users</h2>
        <button class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Add User</button>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle" id="ukUsers" style="width:100%">
        <thead><tr><th>User</th><th>Role</th><th>Plan</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php
        $users = [
            ['Riya Iyer','riya@example.com','RI','primary','Admin','danger','Enterprise','Active','success'],
            ['Sahil Nair','sahil@example.com','SN','success','Vendor','primary','Growth','Active','success'],
            ['Aman Khan','aman@example.com','AK','warning','Staff','info','Starter','Pending','warning'],
            ['Meera Das','meera@example.com','MD','info','Vendor','primary','Growth','Active','success'],
            ['Vivaan N.','vivaan@example.com','VN','danger','Delivery','secondary','—','Suspended','danger'],
            ['Kiara Menon','kiara@example.com','KM','primary','Vendor','primary','Starter','Active','success'],
        ];
        foreach ($users as $u): ?>
            <tr>
                <td><div class="d-flex align-items-center gap-2">
                    <span class="rounded-circle bg-<?= $u[3] ?>-subtle text-<?= $u[3] ?> d-grid" style="width:36px;height:36px;place-items:center;font-weight:600;font-size:.75rem"><?= $u[2] ?></span>
                    <div><a href="<?= site_url('ui-kit/app-user-view') ?>" class="fw-medium small text-reset text-decoration-none d-block"><?= $u[0] ?></a><span class="text-secondary small"><?= $u[1] ?></span></div>
                </div></td>
                <td><span class="badge bg-<?= $u[5] ?>-subtle text-<?= $u[5] ?>"><?= $u[4] ?></span></td>
                <td><?= $u[6] ?></td>
                <td><span class="badge bg-<?= $u[8] ?>-subtle text-<?= $u[8] ?>"><?= $u[7] ?></span></td>
                <td class="text-end">
                    <a href="<?= site_url('ui-kit/app-user-view') ?>" class="btn btn-sm btn-light"><i class="bi bi-eye"></i></a>
                    <a href="<?= site_url('ui-kit/app-user-edit') ?>" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/datatables/jquery.dataTables.min.js') ?>"></script>
<script src="<?= asset('plugins/datatables/dataTables.bootstrap5.min.js') ?>"></script>
<script>$(function(){ $('#ukUsers').DataTable({ pageLength: 5, lengthMenu:[5,10,25] }); });</script>
<?= $this->endSection() ?>
