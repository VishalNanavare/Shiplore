<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Bootstrap tables — striped, hover, bordered, borderless, small, dark head, contextual rows and rich cells.</p>

<div class="card uk-card mb-3"><div class="card-body">
    <h2 class="uk-section-title mb-3">Striped & hover with rich cells</h2>
    <div class="table-responsive"><table class="table table-striped table-hover align-middle">
        <thead><tr><th>Vendor</th><th>City</th><th>Status</th><th style="width:160px">Performance</th><th class="text-end">Revenue</th></tr></thead>
        <tbody>
        <?php foreach ([
            ['Fresh Foods','FF','primary','Mumbai','Active','success',82,'₹4,20,000'],
            ['Style Hub','SH','success','Pune','Active','success',64,'₹2,80,000'],
            ['Tech World','TW','warning','Bengaluru','Pending','warning',30,'—'],
            ['Shoe Bazaar','SB','danger','Delhi','Suspended','danger',12,'₹90,000'],
        ] as $r): ?>
            <tr>
                <td><div class="d-flex align-items-center gap-2"><span class="rounded-circle bg-<?= $r[2] ?>-subtle text-<?= $r[2] ?> d-grid" style="width:32px;height:32px;place-items:center;font-weight:600;font-size:.7rem"><?= $r[1] ?></span><span class="fw-medium"><?= $r[0] ?></span></div></td>
                <td><?= $r[3] ?></td>
                <td><span class="badge bg-<?= $r[5] ?>-subtle text-<?= $r[5] ?>"><?= $r[4] ?></span></td>
                <td><div class="progress" style="height:6px"><div class="progress-bar bg-<?= $r[5] ?>" style="width:<?= $r[6] ?>%"></div></div></td>
                <td class="text-end"><?= $r[7] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div></div>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Bordered & small</h2>
        <table class="table table-bordered table-sm">
            <thead class="table-light"><tr><th>SKU</th><th>Product</th><th>Qty</th><th>Price</th></tr></thead>
            <tbody>
                <tr><td>SKU-001</td><td>Earbuds</td><td>120</td><td>₹1,999</td></tr>
                <tr class="table-warning"><td>SKU-002</td><td>Watch</td><td>4</td><td>₹4,999</td></tr>
                <tr class="table-success"><td>SKU-003</td><td>Shoes</td><td>56</td><td>₹2,499</td></tr>
            </tbody>
        </table>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Dark head & borderless</h2>
        <table class="table table-borderless">
            <thead class="table-dark"><tr><th>#</th><th>User</th><th>Role</th></tr></thead>
            <tbody>
                <tr><td>1</td><td>Riya Iyer</td><td><span class="badge bg-danger-subtle text-danger">Admin</span></td></tr>
                <tr><td>2</td><td>Sahil Nair</td><td><span class="badge bg-primary-subtle text-primary">Vendor</span></td></tr>
                <tr><td>3</td><td>Aman Khan</td><td><span class="badge bg-info-subtle text-info">Staff</span></td></tr>
            </tbody>
        </table>
        <p class="text-secondary small mb-0">For search/sort/paginate, see <a href="<?= site_url('ui-kit/table-datatable') ?>">DataTables</a>.</p>
    </div></div></div>
</div>
<?= $this->endSection() ?>
