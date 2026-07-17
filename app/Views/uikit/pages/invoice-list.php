<?= $this->extend('uikit/_layout') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card uk-card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="uk-section-title mb-0">Invoices</h2>
        <a href="<?= site_url('ui-kit/invoice-edit') ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Invoice</a>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle" id="ukInv" style="width:100%">
        <thead><tr><th>#</th><th>Client</th><th>Issued</th><th>Due</th><th>Total</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php
        $inv = [
            ['INV-2026-06','Fresh Foods','01 Jun','15 Jun','₹30,074','Paid','success'],
            ['INV-2026-05','Style Hub','01 May','15 May','₹18,200','Paid','success'],
            ['INV-2026-04','Tech World','01 Apr','15 Apr','₹42,500','Overdue','danger'],
            ['INV-2026-03','Shoe Bazaar','01 Mar','15 Mar','₹9,900','Pending','warning'],
            ['INV-2026-02','Green Grocers','01 Feb','15 Feb','₹15,400','Paid','success'],
            ['INV-2026-01','Urban Wear','01 Jan','15 Jan','₹61,000','Draft','secondary'],
        ];
        foreach ($inv as $r): ?>
            <tr><td class="fw-medium"><?= $r[0] ?></td><td><?= $r[1] ?></td><td><?= $r[2] ?></td><td><?= $r[3] ?></td><td><?= $r[4] ?></td>
                <td><span class="badge bg-<?= $r[6] ?>-subtle text-<?= $r[6] ?>"><?= $r[5] ?></span></td>
                <td class="text-end">
                    <a href="<?= site_url('ui-kit/invoice') ?>" class="btn btn-sm btn-light"><i class="bi bi-eye"></i></a>
                    <a href="<?= site_url('ui-kit/invoice-edit') ?>" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                </td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/datatables/jquery.dataTables.min.js') ?>"></script>
<script src="<?= asset('plugins/datatables/dataTables.bootstrap5.min.js') ?>"></script>
<script>$(function(){ $('#ukInv').DataTable({ pageLength: 5, lengthMenu:[5,10,25], order:[[0,'desc']] }); });</script>
<?= $this->endSection() ?>
