<?= $this->extend('uikit/_layout') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">DataTables — searching, sorting and pagination on a standard table. Loaded locally.</p>

<div class="card uk-card"><div class="card-body">
    <h2 class="uk-section-title mb-3">Orders</h2>
    <div class="table-responsive">
        <table class="table table-hover align-middle" id="ukDataTable" style="width:100%">
            <thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Total</th><th>Status</th></tr></thead>
            <tbody>
            <?php
            $rows = [
                ['#10293','Aarav Sharma','2026-06-01','₹2,450','Paid','success'],
                ['#10294','Diya Patel','2026-06-02','₹980','Pending','warning'],
                ['#10295','Vivaan Mehta','2026-06-02','₹5,120','Paid','success'],
                ['#10296','Anaya Rao','2026-06-03','₹430','Refunded','danger'],
                ['#10297','Kabir Singh','2026-06-03','₹1,760','Shipped','info'],
                ['#10298','Ishaan Gupta','2026-06-04','₹3,300','Paid','success'],
                ['#10299','Myra Joshi','2026-06-05','₹640','Pending','warning'],
                ['#10300','Reyansh Nair','2026-06-05','₹7,890','Paid','success'],
                ['#10301','Saanvi Das','2026-06-06','₹1,210','Cancelled','secondary'],
                ['#10302','Ayaan Khan','2026-06-07','₹2,050','Shipped','info'],
                ['#10303','Kiara Menon','2026-06-07','₹990','Paid','success'],
                ['#10304','Vihaan Rao','2026-06-08','₹4,560','Pending','warning'],
            ];
            foreach ($rows as $r): ?>
                <tr><td><?= $r[0] ?></td><td><?= $r[1] ?></td><td><?= $r[2] ?></td><td><?= $r[3] ?></td>
                    <td><span class="badge bg-<?= $r[5] ?>-subtle text-<?= $r[5] ?>"><?= $r[4] ?></span></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/datatables/jquery.dataTables.min.js') ?>"></script>
<script src="<?= asset('plugins/datatables/dataTables.bootstrap5.min.js') ?>"></script>
<script>
$(function () { $('#ukDataTable').DataTable({ pageLength: 8, lengthMenu: [5, 8, 10, 25], order: [[2, 'desc']] }); });
</script>
<?= $this->endSection() ?>
