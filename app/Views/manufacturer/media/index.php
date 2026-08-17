<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>
<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Media Library <span class="text-secondary small">(<?= (int) $fileCount ?>)</span></h5>
</div>

<?php if (! empty($canUpload)): ?>
    <div class="card mb-3">
        <div class="card-body d-flex align-items-end gap-2 flex-wrap">
            <div class="flex-grow-1" style="min-width:240px">
                <label class="form-label" for="media-file">Upload a file</label>
                <input class="form-control form-control-sm" id="media-file" type="file"
                       accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,video/mp4">
            </div>
            <button class="btn btn-primary btn-sm" type="button" id="mediaUpload">Upload</button>
            <div class="small text-secondary" id="mediaStatus" role="status" aria-live="polite"></div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle" id="mediaTable">
            <thead><tr><th>File</th><th>Type</th><th>Size</th><th>Added</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($files)): ?>
                    <tr><td colspan="5" class="text-center text-secondary py-4">
                        Nothing here yet. Upload product photos, spec sheets or certificates.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($files as $f): ?>
                        <tr>
                            <td class="small"><?= esc((string) ($f['original_name'] ?? basename((string) ($f['object_key'] ?? '')))) ?></td>
                            <td class="small text-secondary"><?= esc((string) ($f['mime'] ?? '')) ?></td>
                            <td class="small text-secondary">
                                <?= ! empty($f['size_bytes']) ? esc((string) round(((int) $f['size_bytes']) / 1024)) . ' KB' : '—' ?>
                            </td>
                            <td class="small text-secondary"><?= esc(substr((string) ($f['created_at'] ?? ''), 0, 16)) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                                   href="<?= site_url('manufacturer/media/' . (int) $f['id'] . '/view') ?>">Open</a>
                                <?php if (! empty($canUpload)): ?>
                                    <form method="post" class="d-inline"
                                          action="<?= site_url('manufacturer/media/' . (int) $f['id'] . '/delete') ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
// Same presign -> PUT -> confirm handshake as the documents screen.
(function () {
    var btn = document.getElementById('mediaUpload');
    if (!btn) { return; }
    var statusEl = document.getElementById('mediaStatus');
    var csrfName = '<?= csrf_token() ?>';
    var csrf = '<?= csrf_hash() ?>';

    function post(url, data) {
        data.append(csrfName, csrf);
        return fetch(url, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j && j.csrf) { csrf = j.csrf; } return j; });
    }

    btn.addEventListener('click', function () {
        var file = document.getElementById('media-file').files[0];
        if (!file) { statusEl.textContent = 'Choose a file first.'; return; }
        statusEl.textContent = 'Uploading…';

        var d = new FormData();
        d.append('filename', file.name);
        d.append('content_type', file.type);
        d.append('size', file.size);

        post('<?= site_url('manufacturer/media/presign') ?>', d).then(function (res) {
            if (!res || !res.ok) { statusEl.textContent = (res && res.message) || 'Upload rejected.'; return; }
            return fetch(res.url, { method: 'PUT', body: file, credentials: 'same-origin' }).then(function () {
                var c = new FormData();
                c.append('key', res.key);
                c.append('content_type', file.type);
                c.append('filename', file.name);
                c.append('size', file.size);
                return post('<?= site_url('manufacturer/media/confirm') ?>', c);
            }).then(function (done) {
                if (done && done.ok) { location.reload(); }
                else { statusEl.textContent = (done && done.message) || 'Could not save the file.'; }
            });
        }).catch(function () { statusEl.textContent = 'Upload failed.'; });
    });
})();
</script>
<?= $this->endSection() ?>
