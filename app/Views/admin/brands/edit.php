<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= site_url('admin/brands') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i></a>
    <h5 class="mb-0">Edit Brand — <?= esc($brand['name']) ?></h5>
    <span class="badge text-bg-<?= $brand['status'] === 'active' ? 'success' : ($brand['status'] === 'pending' ? 'warning' : 'secondary') ?>"><?= esc($brand['status']) ?></span>
</div>

<div class="row g-3">
    <!-- Brand name form -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold">Brand details</div>
            <div class="card-body">
                <form method="post" action="<?= site_url('admin/brands/' . $brand['id'] . '/update') ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Brand name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= esc($brand['name'], 'attr') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small">Slug</label>
                        <input type="text" class="form-control form-control-sm text-secondary" value="<?= esc($brand['slug'], 'attr') ?>" disabled>
                        <div class="form-text">Slug is auto-generated and cannot be changed.</div>
                    </div>
                    <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save brand</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Category mapping form -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Category mapping</span>
                <span class="text-secondary small"><?= count($mapped) ?> mapped — <?= count($mapped) === 0 ? '<span class="text-success">global (shows in all categories)</span>' : count($categories) - count($mapped) . ' unmapped' ?></span>
            </div>
            <div class="card-body">
                <p class="text-secondary small mb-3">
                    Check the categories where this brand should appear on the product form.
                    Leave <strong>all unchecked</strong> to make this brand global (visible in every category).
                </p>
                <form method="post" action="<?= site_url('admin/brands/' . $brand['id'] . '/categories') ?>">
                    <?= csrf_field() ?>
                    <div class="mb-2 d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('#catGrid input').forEach(function(c){c.checked=true})">Check all</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('#catGrid input').forEach(function(c){c.checked=false})">Uncheck all (global)</button>
                    </div>
                    <div id="catGrid" style="max-height:420px;overflow-y:auto" class="border rounded p-3">
                        <?php
                        $perCol   = (int) ceil(count($categories) / 3);
                        $chunks   = array_chunk($categories, $perCol ?: 1);
                        ?>
                        <div class="row g-0">
                        <?php foreach ($chunks as $chunk): ?>
                            <div class="col-md-4">
                            <?php foreach ($chunk as $c): ?>
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="categories[]"
                                           value="<?= esc($c['id'], 'attr') ?>"
                                           id="cat<?= $c['id'] ?>"
                                           <?= in_array((int) $c['id'], $mapped, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="cat<?= $c['id'] ?>"><?= esc($c['name']) ?></label>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button class="btn btn-primary"><i class="bi bi-diagram-3 me-1"></i>Save category mapping</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
