<?= $this->extend('layouts/main') ?>
<?php helper('media'); ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<?php
$crumbs = [['Media', site_url('admin/media')]];
if (($mode ?? '') === 'vendor') {
    $crumbs[] = [$vendor['display_name'] ?? 'Vendor', null];
} elseif (($mode ?? '') === 'shop') {
    $crumbs[] = [$shop['vendor_name'] ?? 'Vendor', site_url('admin/media/vendor/' . (int) $shop['vendor_id'])];
    $crumbs[] = [$shop['name'] ?? 'Shop', null];
}
?>
<nav style="--bs-breadcrumb-divider:'/'" class="mb-3"><ol class="breadcrumb mb-0">
    <?php foreach ($crumbs as $i => [$label, $url]): ?>
        <?php if ($url && $i < count($crumbs) - 1): ?>
            <li class="breadcrumb-item"><a href="<?= $url ?>"><?= esc($label) ?></a></li>
        <?php else: ?>
            <li class="breadcrumb-item active"><?= esc($label) ?></li>
        <?php endif; ?>
    <?php endforeach; ?>
</ol></nav>

<div class="card"><div class="card-body">
<?php if (($mode ?? '') === 'vendors'): ?>
    <h2 class="h6 mb-3"><i class="bi bi-folder2 me-1"></i>Vendors</h2>
    <div class="row g-3">
        <?php foreach ($vendors as $v): ?>
            <div class="col-6 col-md-4 col-xl-3">
                <a href="<?= site_url('admin/media/vendor/' . (int) $v['id']) ?>" class="text-decoration-none text-reset">
                    <div class="border rounded p-3 h-100 d-flex align-items-center">
                        <i class="bi bi-folder-fill text-warning me-3" style="font-size:2rem"></i>
                        <div class="overflow-hidden">
                            <div class="fw-medium text-truncate"><?= esc($v['display_name'] ?: ('Vendor #' . $v['id'])) ?></div>
                            <div class="small text-secondary"><?= (int) ($counts[(int) $v['id']] ?? 0) ?> file(s)</div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
        <?php if (empty($vendors)): ?><div class="col-12 text-center text-secondary py-5">No vendors.</div><?php endif; ?>
    </div>

<?php else: ?>
    <?php if (($mode ?? '') === 'vendor' && ! empty($shops)): ?>
        <h2 class="h6 mb-3"><i class="bi bi-shop me-1"></i>Shops</h2>
        <div class="row g-3 mb-4">
            <?php foreach ($shops as $s): ?>
                <div class="col-6 col-md-4 col-xl-3">
                    <a href="<?= site_url('admin/media/shop/' . (int) $s['id']) ?>" class="text-decoration-none text-reset">
                        <div class="border rounded p-3 h-100 d-flex align-items-center">
                            <i class="bi bi-folder-fill text-info me-3" style="font-size:2rem"></i>
                            <div class="overflow-hidden">
                                <div class="fw-medium text-truncate"><?= esc($s['name'] ?: ('Shop #' . $s['id'])) ?></div>
                                <div class="small text-secondary"><?= (int) ($shopCounts[(int) $s['id']] ?? 0) ?> file(s)</div>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
    // Upload target = the vendor or shop folder currently open.
    $upOwner = ($mode ?? '') === 'shop' ? 'shop' : 'vendor';
    $upId    = ($mode ?? '') === 'shop' ? (int) ($shop['id'] ?? 0) : (int) ($vendor['id'] ?? 0);
    $base    = site_url('admin/media/' . $upOwner . '/' . $upId);
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <h2 class="h6 mb-0"><i class="bi bi-files me-1"></i><?= ($mode ?? '') === 'shop' ? 'Shop files' : 'Business files' ?></h2>
        <form method="get" class="d-flex gap-2">
            <select name="type" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                <?php foreach (['' => 'All types', 'image' => 'Images', 'pdf' => 'PDFs', 'video' => 'Video', 'doc' => 'Documents'] as $tv => $tl): ?>
                    <option value="<?= $tv ?>" <?= ($fType ?? '') === $tv ? 'selected' : '' ?>><?= $tl ?></option>
                <?php endforeach; ?>
            </select>
            <div class="input-group input-group-sm" style="width:220px">
                <input name="q" class="form-control" placeholder="Search filename…" value="<?= esc($fQ ?? '', 'attr') ?>">
                <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
            </div>
            <?php if (($fType ?? '') !== '' || ($fQ ?? '') !== ''): ?><a href="<?= $base ?>" class="btn btn-sm btn-link">Clear</a><?php endif; ?>
        </form>
    </div>

    <form method="post" action="<?= $base ? site_url('admin/media/upload/' . $upOwner . '/' . $upId) : '#' ?>" enctype="multipart/form-data" class="mb-3" data-no-ajax>
        <?= csrf_field() ?>
        <div class="border border-2 border-dashed rounded p-3 d-flex flex-wrap align-items-center gap-2" style="background:#fbfcfe">
            <i class="bi bi-cloud-arrow-up fs-4 text-secondary"></i>
            <span class="small text-secondary me-2">Upload to <strong><?= esc(($mode ?? '') === 'shop' ? ($shop['name'] ?? 'this shop') : ($vendor['display_name'] ?? 'this vendor')) ?></strong> (images, PDF, DOC):</span>
            <input type="file" name="files[]" class="form-control form-control-sm" style="max-width:340px" accept="image/*,.pdf,.doc,.docx" multiple required>
            <button class="btn btn-sm btn-primary"><i class="bi bi-upload me-1"></i>Upload</button>
        </div>
    </form>

    <div class="row g-3">
        <?php foreach ($files as $f): [$ic, $clr] = media_icon((string) $f['mime']); ?>
            <div class="col-6 col-md-4 col-xl-3">
                <div class="position-relative h-100">
                    <a href="<?= site_url('admin/media/' . $f['id'] . '/view') ?>" target="_blank" class="text-decoration-none text-reset">
                        <div class="border rounded p-3 h-100 text-center">
                            <i class="bi bi-<?= $ic ?> text-<?= $clr ?>" style="font-size:2.4rem"></i>
                            <div class="small fw-medium text-truncate mt-2" title="<?= esc(media_name($f), 'attr') ?>"><?= esc(media_name($f)) ?></div>
                            <div class="text-secondary" style="font-size:.72rem"><?= esc(media_size($f['size_bytes'] ?? null)) ?></div>
                        </div>
                    </a>
                    <form method="post" action="<?= site_url('admin/media/' . $f['id'] . '/delete') ?>" class="position-absolute top-0 end-0 m-1" data-confirm="Delete this file? This cannot be undone."><?= csrf_field() ?><button class="btn btn-sm btn-light text-danger py-0 px-1" title="Delete"><i class="bi bi-trash"></i></button></form>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($files)): ?><div class="col-12 text-center text-secondary py-4">No files here.</div><?php endif; ?>
    </div>
<?php endif; ?>
</div></div>
<?= $this->endSection() ?>
