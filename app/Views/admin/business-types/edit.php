<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= site_url('admin/business-types') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h6 class="mb-0 fw-semibold"><?= esc($type['name']) ?></h6>
    <span class="badge text-bg-<?= $type['status'] === 'active' ? 'success' : 'secondary' ?>"><?= esc($type['status']) ?></span>
</div>

<form method="post" action="<?= site_url('admin/business-types/' . $type['id'] . '/update') ?>">
    <?= csrf_field() ?>

    <div class="row g-3">
        <!-- Settings card -->
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header fw-semibold">Settings</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Code</label>
                        <input type="text" class="form-control form-control-sm bg-light" value="<?= esc($type['code']) ?>" disabled>
                        <div class="form-text">Code cannot be changed.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Name</label>
                        <input type="text" class="form-control form-control-sm bg-light" value="<?= esc($type['name']) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label for="desc" class="form-label small text-secondary">Description</label>
                        <textarea id="desc" name="description" class="form-control form-control-sm" rows="3"><?= esc($type['description'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="comm" class="form-label small text-secondary">Default Commission %</label>
                        <input id="comm" name="default_commission_rate" type="number" step="0.01" min="0" max="100"
                               class="form-control form-control-sm" value="<?= esc($type['default_commission_rate'] ?? '') ?>">
                    </div>
                    <div class="d-grid">
                        <button class="btn btn-primary btn-sm"><i class="bi bi-floppy me-1"></i>Save Changes</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Category mapping card -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Allowed Categories</span>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge text-bg-primary" id="selectedCount"><?= count($mappedIds) ?> selected</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSelectAll">Select all</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClearAll">Clear all</button>
                    </div>
                </div>
                <div class="card-body" style="max-height:560px;overflow-y:auto;">
                    <?php
                    $mappedSet = array_flip($mappedIds);
                    $grouped   = [];
                    foreach ($allCats as $c) {
                        $group = $c['parent_name'] ?: '— Top-level categories —';
                        $grouped[$group][] = $c;
                    }
                    ksort($grouped);
                    // Move top-level to front
                    if (isset($grouped['— Top-level categories —'])) {
                        $top = $grouped['— Top-level categories —'];
                        unset($grouped['— Top-level categories —']);
                        $grouped = ['— Top-level categories —' => $top] + $grouped;
                    }
                    ?>
                    <?php foreach ($grouped as $groupName => $cats): ?>
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="text-secondary small fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.06em"><?= esc($groupName) ?></span>
                                <hr class="flex-grow-1 my-0">
                            </div>
                            <div class="row g-1">
                            <?php foreach ($cats as $cat): ?>
                                <div class="col-6 col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input cat-check" type="checkbox"
                                               name="category_ids[]"
                                               id="cat<?= $cat['id'] ?>"
                                               value="<?= $cat['id'] ?>"
                                               <?= isset($mappedSet[$cat['id']]) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="cat<?= $cat['id'] ?>"><?= esc($cat['name']) ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="card-footer text-end">
                    <button class="btn btn-primary btn-sm"><i class="bi bi-floppy me-1"></i>Save Changes</button>
                </div>
            </div>
        </div>
    </div>
</form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function(){
    const checks  = document.querySelectorAll('.cat-check');
    const counter = document.getElementById('selectedCount');
    function updateCount(){
        const n = [...checks].filter(c=>c.checked).length;
        counter.textContent = n + ' selected';
    }
    checks.forEach(c => c.addEventListener('change', updateCount));
    document.getElementById('btnSelectAll').addEventListener('click', () => {
        checks.forEach(c=>c.checked=true); updateCount();
    });
    document.getElementById('btnClearAll').addEventListener('click', () => {
        checks.forEach(c=>c.checked=false); updateCount();
    });
})();
</script>
<?= $this->endSection() ?>
