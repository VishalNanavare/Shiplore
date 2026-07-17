<?= $this->extend('uikit/_layout') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Per-column search (footer inputs), multi-column sort, horizontal scroll and a custom toolbar — core DataTables, no extensions.</p>

<div class="card uk-card"><div class="card-body">
    <h2 class="uk-section-title mb-3">Vendors</h2>
    <div class="table-responsive">
        <table class="table table-hover align-middle" id="ukAdv" style="width:100%">
            <thead><tr><th>Code</th><th>Vendor</th><th>City</th><th>Plan</th><th>GST</th><th>Status</th><th class="text-end">Revenue</th></tr></thead>
            <tfoot><tr><?php for ($i=0;$i<7;$i++): ?><th></th><?php endfor; ?></tr></tfoot>
            <tbody>
            <?php
            $rows = [
                ['V-001','Fresh Foods','Mumbai','Growth','Verified','Active','success','420000'],
                ['V-002','Style Hub','Pune','Starter','Verified','Active','success','280000'],
                ['V-003','Tech World','Bengaluru','Enterprise','Pending','Pending','warning','0'],
                ['V-004','Shoe Bazaar','Delhi','Growth','Verified','Suspended','danger','90000'],
                ['V-005','Green Grocers','Hyderabad','Starter','Verified','Active','success','155000'],
                ['V-006','Gadget Point','Chennai','Growth','Rejected','Inactive','secondary','0'],
                ['V-007','Urban Wear','Kolkata','Enterprise','Verified','Active','success','610000'],
                ['V-008','Daily Mart','Ahmedabad','Starter','Verified','Active','success','98000'],
                ['V-009','Sports Co','Jaipur','Growth','Pending','Pending','warning','0'],
                ['V-010','Home Decor','Surat','Starter','Verified','Active','success','132000'],
            ];
            foreach ($rows as $r): ?>
                <tr><td><?= $r[0] ?></td><td class="fw-medium"><?= $r[1] ?></td><td><?= $r[2] ?></td><td><?= $r[3] ?></td>
                    <td><?= $r[4] ?></td>
                    <td><span class="badge bg-<?= $r[6] ?>-subtle text-<?= $r[6] ?>"><?= $r[5] ?></span></td>
                    <td class="text-end"><?= $r[7] == 0 ? '—' : '₹' . number_format((int)$r[7]) ?></td></tr>
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
$(function () {
    // per-column footer search inputs
    $('#ukAdv tfoot th').each(function () {
        $(this).html('<input type="text" class="form-control form-control-sm" placeholder="Search">');
    });
    var table = $('#ukAdv').DataTable({
        pageLength: 5,
        lengthMenu: [5, 10, 25],
        scrollX: true,
        order: [[5, 'asc'], [1, 'asc']],
        dom: "<'row mb-2'<'col-sm-6'l><'col-sm-6 text-end'f>>tip"
    });
    table.columns().every(function () {
        var col = this;
        $('input', this.footer()).on('keyup change clear', function () {
            if (col.search() !== this.value) col.search(this.value).draw();
        });
    });
});
</script>
<?= $this->endSection() ?>
