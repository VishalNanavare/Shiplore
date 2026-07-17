<?= $this->extend('layouts/vendor') ?>
<?php helper('media'); ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<?php
// Folder list: All, Business (owner only), then each shop.
$folders = [['all', 'All files', 'collection', $allCount]];
if ($isOwner) {
    $folders[] = ['vendor', 'Business files', 'briefcase', $vendorCount];
}
foreach ($shops as $sid => $sname) {
    $folders[] = ['shop:' . $sid, $sname, 'shop', (int) ($shopCounts[$sid] ?? 0)];
}
?>
<div class="row g-3">
    <div class="col-lg-3">
        <div class="card mb-3"><div class="card-body">
            <button id="dz" type="button" class="btn btn-primary w-100 mb-3"><i class="bi bi-cloud-arrow-up me-1"></i>Upload files</button>
            <label class="form-label small text-secondary mb-1">Upload to</label>
            <select id="dzTarget" class="form-select form-select-sm mb-2">
                <?php if ($isOwner): ?><option value="vendor">Business files</option><?php endif; ?>
                <?php foreach ($shops as $sid => $sname): ?>
                    <option value="shop:<?= (int) $sid ?>"<?= $folder === 'shop:' . $sid ? ' selected' : '' ?>><?= esc($sname) ?></option>
                <?php endforeach; ?>
            </select>
            <input id="dzInput" type="file" class="d-none" multiple
                   accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.svg,.mp4,.webm,.mov,.mp3,.wav,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip">
            <div id="dzList"></div>
            <div class="small text-secondary mt-1">Images, PDF, video, docs · up to 200&nbsp;MB each</div>
        </div></div>
        <div class="card"><div class="list-group list-group-flush">
            <?php foreach ($folders as [$key, $label, $icon, $count]): ?>
                <a href="<?= site_url('vendor/media?folder=' . rawurlencode($key)) ?>"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $folder === $key ? 'active' : '' ?>">
                    <span class="text-truncate"><i class="bi bi-<?= $icon ?> me-2"></i><?= esc($label) ?></span>
                    <span class="badge rounded-pill text-bg-<?= $folder === $key ? 'light' : 'secondary' ?>"><?= (int) $count ?></span>
                </a>
            <?php endforeach; ?>
        </div></div>
    </div>

    <div class="col-lg-9">
        <div class="card"><div class="card-body">
            <nav style="--bs-breadcrumb-divider:'/'" class="mb-3">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= site_url('vendor/media?folder=all') ?>">Media</a></li>
                    <li class="breadcrumb-item active"><?= esc($crumb) ?></li>
                </ol>
            </nav>
            <div class="row g-3">
                <?php foreach ($files as $f): [$ic, $clr] = media_icon((string) $f['mime']); ?>
                    <div class="col-6 col-md-4 col-xl-3">
                        <div class="border rounded p-3 h-100 text-center position-relative">
                            <div class="dropdown position-absolute top-0 end-0 m-1">
                                <button class="btn btn-sm btn-link text-secondary p-1" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="<?= site_url('vendor/media/' . $f['id'] . '/view') ?>" target="_blank"><i class="bi bi-eye me-2"></i>View</a></li>
                                    <li>
                                        <form method="post" action="<?= site_url('vendor/media/' . $f['id'] . '/delete?folder=' . rawurlencode($folder)) ?>" data-confirm="Remove this file?">
                                            <?= csrf_field() ?><button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Delete</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                            <a href="<?= site_url('vendor/media/' . $f['id'] . '/view') ?>" target="_blank" class="text-decoration-none text-reset">
                                <i class="bi bi-<?= $ic ?> text-<?= $clr ?>" style="font-size:2.4rem"></i>
                                <div class="small fw-medium text-truncate mt-2" title="<?= esc(media_name($f), 'attr') ?>"><?= esc(media_name($f)) ?></div>
                                <div class="text-secondary" style="font-size:.72rem"><?= esc(media_size($f['size_bytes'] ?? null)) ?></div>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($files)): ?>
                    <div class="col-12 text-center text-secondary py-5">
                        <i class="bi bi-folder2-open display-6 d-block mb-2"></i>No files here yet — use <strong>Upload files</strong>.
                    </div>
                <?php endif; ?>
            </div>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var dz = document.getElementById('dz'), input = document.getElementById('dzInput'),
        list = document.getElementById('dzList'), target = document.getElementById('dzTarget');
    if (!dz || !target) { return; }
    var presignUrl = <?= json_encode(site_url('vendor/media/presign')) ?>;
    var confirmUrl = <?= json_encode(site_url('vendor/media/confirm')) ?>;
    var MAX = 209715200;

    function meta(n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.getAttribute('content') : ''; }
    function setHash(h) { if (h) { var m = document.querySelector('meta[name="csrf-hash"]'); if (m) { m.setAttribute('content', h); } } }
    function row(name) {
        var el = document.createElement('div');
        el.className = 'd-flex justify-content-between align-items-center border rounded p-1 mb-1 small';
        el.innerHTML = '<span class="text-truncate me-2"></span><span class="st text-secondary"></span>';
        el.firstChild.textContent = name; list.prepend(el);
        return el.querySelector('.st');
    }
    function set(st, txt, cls) { st.textContent = txt; st.className = 'st ' + (cls || 'text-secondary'); }
    var anyOk = false;

    function upload(file) {
        var st = row(file.name);
        if (file.size > MAX) { set(st, 'Too big (> 200 MB)', 'text-danger'); return Promise.resolve(); }
        set(st, 'Preparing…');
        var fd = new FormData();
        fd.append(meta('csrf-name'), meta('csrf-hash'));
        fd.append('target', target.value);
        fd.append('filename', file.name); fd.append('content_type', file.type || 'application/octet-stream'); fd.append('size', file.size);
        return fetch(presignUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                setHash(res.csrf);
                if (!res.ok) { set(st, res.message || 'Failed', 'text-danger'); throw new Error('presign'); }
                set(st, 'Uploading…');
                return fetch(res.url, { method: 'PUT', credentials: res.mode === 'dummy' ? 'same-origin' : 'omit', headers: res.headers || {}, body: file })
                    .then(function (pr) {
                        if (!pr.ok) { throw new Error('put'); }
                        set(st, 'Saving…');
                        var cf = new FormData();
                        cf.append(meta('csrf-name'), meta('csrf-hash'));
                        cf.append('target', target.value); cf.append('key', res.key);
                        cf.append('content_type', file.type || 'application/octet-stream'); cf.append('filename', file.name); cf.append('size', file.size);
                        return fetch(confirmUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: cf });
                    });
            })
            .then(function (r) { return r.json(); })
            .then(function (res) { setHash(res.csrf); if (res.ok) { anyOk = true; } set(st, res.ok ? 'Uploaded ✓' : 'Save failed', res.ok ? 'text-success' : 'text-danger'); })
            .catch(function () { if (/Preparing|Uploading|Saving/.test(st.textContent)) { set(st, 'Failed', 'text-danger'); } });
    }

    // Sequential — keeps the rotating CSRF token valid; reload once the queue drains.
    function handle(files) {
        var arr = Array.prototype.slice.call(files);
        (function next() {
            if (!arr.length) { if (anyOk) { setTimeout(function () { location.reload(); }, 700); } return; }
            upload(arr.shift()).then(next, next);
        })();
    }
    dz.addEventListener('click', function () { input.click(); });
    input.addEventListener('change', function () { handle(input.files); input.value = ''; });
})();
</script>
<?= $this->endSection() ?>
