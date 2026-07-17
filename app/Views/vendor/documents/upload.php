<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card"><div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h2 class="h6 mb-0"><i class="bi bi-cloud-arrow-up me-1"></i>Upload documents</h2>
                <select id="dzType" class="form-select form-select-sm w-auto">
                    <?php foreach ($types as $t): ?><option value="<?= esc($t, 'attr') ?>"><?= esc(strtoupper($t)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div id="dz" class="border border-2 border-dashed rounded text-center text-secondary py-5" style="cursor:pointer">
                <i class="bi bi-files display-6 d-block mb-2"></i>
                <div>Drag &amp; drop, or <span class="text-primary">click to choose</span></div>
                <div class="small">PDF or images · up to 10 MB each</div>
            </div>
            <input id="dzInput" type="file" class="d-none" accept=".pdf,application/pdf,image/*" multiple>
            <div id="dzList" class="mt-3"></div>
        </div></div>
    </div>
    <div class="col-lg-5">
        <div class="card"><div class="card-header fw-semibold">Your documents</div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Type</th><th>File</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="small text-uppercase"><?= esc($d['doc_type']) ?></td>
                        <td class="small text-truncate" style="max-width:160px"><?= esc(basename((string) ($d['object_key'] ?? ''))) ?></td>
                        <td><span class="badge text-bg-<?= $d['status'] === 'verified' ? 'success' : ($d['status'] === 'rejected' ? 'danger' : 'secondary') ?>"><?= esc($d['status']) ?></span></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= site_url('vendor/kyc/' . $d['id'] . '/view') ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0" title="View"><i class="bi bi-eye"></i></a>
                            <form method="post" action="<?= site_url('vendor/kyc/' . $d['id'] . '/delete') ?>" data-confirm="Remove this document?" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger py-0">&times;</button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($docs)): ?><tr><td colspan="4" class="text-center text-secondary py-4">No documents yet.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var dz = document.getElementById('dz'), input = document.getElementById('dzInput'), list = document.getElementById('dzList');
    if (!dz) { return; }
    var presignUrl = <?= json_encode(site_url('vendor/kyc/presign')) ?>;
    var confirmUrl = <?= json_encode(site_url('vendor/kyc/confirm')) ?>;
    var ALLOWED = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];

    function meta(n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.getAttribute('content') : ''; }
    function setHash(h) { if (h) { var m = document.querySelector('meta[name="csrf-hash"]'); if (m) { m.setAttribute('content', h); } } }
    function row(name) {
        var el = document.createElement('div');
        el.className = 'd-flex justify-content-between align-items-center border rounded p-2 mb-1 small';
        el.innerHTML = '<span class="text-truncate me-2"></span><span class="st text-secondary"></span>';
        el.firstChild.textContent = name;
        list.prepend(el);
        return el.querySelector('.st');
    }
    function set(st, txt, cls) { st.textContent = txt; st.className = 'st ' + (cls || 'text-secondary'); }

    function upload(file) {
        var st = row(file.name);
        if (ALLOWED.indexOf(file.type) < 0) { set(st, 'Only PDF / images', 'text-danger'); return Promise.resolve(); }
        if (file.size > 10485760) { set(st, 'Too big (> 10 MB)', 'text-danger'); return Promise.resolve(); }
        set(st, 'Preparing…');

        var fd = new FormData();
        fd.append(meta('csrf-name'), meta('csrf-hash'));
        fd.append('filename', file.name); fd.append('content_type', file.type); fd.append('size', file.size);

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
                        cf.append('key', res.key); cf.append('content_type', file.type); cf.append('doc_type', document.getElementById('dzType').value);
                        return fetch(confirmUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: cf });
                    });
            })
            .then(function (r) { return r.json(); })
            .then(function (res) { setHash(res.csrf); set(st, res.ok ? 'Uploaded ✓' : 'Save failed', res.ok ? 'text-success' : 'text-danger'); })
            .catch(function () { if (st.textContent === 'Preparing…' || st.textContent === 'Uploading…' || st.textContent === 'Saving…') { set(st, 'Failed', 'text-danger'); } });
    }

    // Sequential — keeps the rotating CSRF token valid across files.
    function handle(files) {
        var arr = Array.prototype.slice.call(files);
        (function next() { if (!arr.length) { return; } upload(arr.shift()).then(next, next); })();
    }

    dz.addEventListener('click', function () { input.click(); });
    input.addEventListener('change', function () { handle(input.files); input.value = ''; });
    ['dragover', 'dragenter'].forEach(function (e) { dz.addEventListener(e, function (ev) { ev.preventDefault(); dz.classList.add('border-primary'); }); });
    ['dragleave', 'drop'].forEach(function (e) { dz.addEventListener(e, function (ev) { ev.preventDefault(); dz.classList.remove('border-primary'); }); });
    dz.addEventListener('drop', function (ev) { if (ev.dataTransfer && ev.dataTransfer.files) { handle(ev.dataTransfer.files); } });
})();
</script>
<?= $this->endSection() ?>
