<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0"><i class="bi bi-search me-1"></i>Find stock across your stores</h1>
    <a href="<?= site_url('vendor/transfers') ?>" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Transfers</a>
</div>

<form method="get" action="<?= site_url('vendor/transfers/find') ?>" class="mb-3">
    <div class="input-group">
        <input name="q" class="form-control" value="<?= esc($q, 'attr') ?>" placeholder="Search product name, SKU or barcode…" autofocus>
        <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Search</button>
    </div>
    <div class="form-text">Shows every one of your stores that has this product in stock, so you can request a transfer to your store.</div>
</form>

<?php if ($q !== ''): ?>
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Product</th><th>Available at store</th><th class="text-end">Available</th><th style="min-width:280px">Request transfer to</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
            <tr>
                <td class="small"><?= esc($r['title'] ?? '—') ?><div class="text-secondary"><?= esc($r['sku'] ?? '') ?></div></td>
                <td><span class="badge bg-primary-subtle text-primary"><?= esc($r['shop'] ?? '—') ?></span></td>
                <td class="text-end fw-semibold"><?= esc(rtrim(rtrim((string) $r['available'], '0'), '.')) ?></td>
                <td>
                    <form method="post" action="<?= site_url('vendor/transfers') ?>" class="row g-1 align-items-center">
                        <?= csrf_field() ?>
                        <input type="hidden" name="from_shop_id" value="<?= esc($r['shop_id'], 'attr') ?>">
                        <input type="hidden" name="variant_id" value="<?= esc($r['variant_id'], 'attr') ?>">
                        <div class="col"><select name="to_shop_id" class="form-select form-select-sm" required>
                            <option value="">To which store…</option>
                            <?php foreach ($shops as $s): if ((int) $s['id'] === (int) $r['shop_id']) { continue; } ?><option value="<?= esc($s['id'], 'attr') ?>"><?= esc($s['name']) ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="col-auto"><input name="qty" type="number" min="1" step="0.001" max="<?= esc($r['available'], 'attr') ?>" class="form-control form-control-sm" style="width:80px" value="1" required></div>
                        <div class="col-auto"><button class="btn btn-sm btn-success">Request</button></div>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($results)): ?><tr><td colspan="4" class="text-center text-secondary py-4">No store has "<?= esc($q) ?>" in stock right now.</td></tr><?php endif; ?>
        </tbody>
    </table></div></div>
<?php endif; ?>
<?= $this->endSection() ?>
