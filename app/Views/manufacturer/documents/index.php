<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>
<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header py-2"><strong>Upload a document</strong></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="doc-type">Document type</label>
                    <select class="form-select" id="doc-type">
                        <?php foreach ($types as $t): ?>
                            <option value="<?= esc($t, 'attr') ?>"><?= esc(ucwords(str_replace('_', ' ', $t))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="doc-file">File</label>
                    <input class="form-control" id="doc-file" type="file" accept="application/pdf,image/jpeg,image/png,image/webp">
                    <div class="form-text">PDF or image, up to 10 MB.</div>
                </div>
                <button class="btn btn-primary btn-sm" type="button" id="docUpload">Upload</button>
                <div class="small mt-2" id="docStatus" role="status" aria-live="polite"></div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <strong>Uploaded documents</strong>
                <span class="text-secondary small"><?= count($docs) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle" id="documentsTable">
                    <thead><tr><th>Type</th><th>Status</th><th>Uploaded</th><th></th></tr></thead>
                    <tbody>
                        <?php if (empty($docs)): ?>
                            <tr><td colspan="4" class="text-center text-secondary py-4">
                                No documents yet. Upload your GST certificate, PAN and factory licence.
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($docs as $d): ?>
                                <tr>
                                    <td class="small"><?= esc(ucwords(str_replace('_', ' ', (string) ($d['doc_type'] ?? '')))) ?></td>
                                    <td>
                                        <span class="badge bg-<?= ($d['status'] ?? '') === 'verified' ? 'success' : (($d['status'] ?? '') === 'rejected' ? 'danger' : 'secondary') ?>">
                                            <?= esc((string) ($d['status'] ?? '')) ?>
                                        </span>
                                    </td>
                                    <td class="small text-secondary"><?= esc(substr((string) ($d['created_at'] ?? ''), 0, 16)) ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                                           href="<?= site_url('manufacturer/documents/' . (int) $d['id'] . '/view') ?>">Open</a>
                                        <form method="post" class="d-inline"
                                              action="<?= site_url('manufacturer/documents/' . (int) $d['id'] . '/delete') ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
// Presign -> PUT the bytes straight to storage -> confirm. The CSRF hash rotates on
// every JSON response, so each step re-reads it from the previous one.
(function () {
    var btn = document.getElementById('docUpload');
    if (!btn) { return; }
    var statusEl = document.getElementById('docStatus');
    var csrfName = '<?= csrf_token() ?>';
    var csrf = '<?= csrf_hash() ?>';

    function post(url, data) {
        data.append(csrfName, csrf);
        return fetch(url, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j && j.csrf) { csrf = j.csrf; } return j; });
    }

    btn.addEventListener('click', function () {
        var file = document.getElementById('doc-file').files[0];
        if (!file) { statusEl.textContent = 'Choose a file first.'; return; }
        statusEl.textContent = 'Uploading…';

        var d = new FormData();
        d.append('filename', file.name);
        d.append('content_type', file.type);
        d.append('size', file.size);

        post('<?= site_url('manufacturer/documents/presign') ?>', d).then(function (res) {
            if (!res || !res.ok) { statusEl.textContent = (res && res.message) || 'Upload rejected.'; return; }
            return fetch(res.url, { method: 'PUT', body: file, credentials: 'same-origin' }).then(function () {
                var c = new FormData();
                c.append('key', res.key);
                c.append('content_type', file.type);
                c.append('doc_type', document.getElementById('doc-type').value);
                return post('<?= site_url('manufacturer/documents/confirm') ?>', c);
            }).then(function (done) {
                if (done && done.ok) { location.reload(); }
                else { statusEl.textContent = (done && done.message) || 'Could not save the document.'; }
            });
        }).catch(function () { statusEl.textContent = 'Upload failed.'; });
    });
})();
</script>
<?= $this->endSection() ?>
