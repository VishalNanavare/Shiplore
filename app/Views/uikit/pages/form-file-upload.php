<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Drag-and-drop file upload with live previews — built with the native File API (no plugin).</p>

<div class="row g-3">
    <div class="col-lg-7"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Dropzone</h2>
        <div id="ukDrop" class="text-center p-5 rounded" style="border:2px dashed var(--uk-border);cursor:pointer">
            <i class="bi bi-cloud-arrow-up display-5 text-primary"></i>
            <div class="fw-medium mt-2">Drag files here or click to browse</div>
            <div class="text-secondary small">Images, PDF, up to 5 MB each</div>
            <input type="file" id="ukFile" multiple hidden>
        </div>
        <div id="ukFiles" class="mt-3"></div>
    </div></div></div>
    <div class="col-lg-5"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Single & standard inputs</h2>
        <div class="mb-3"><label class="form-label">Default</label><input type="file" class="form-control"></div>
        <div class="mb-3"><label class="form-label">Small</label><input type="file" class="form-control form-control-sm"></div>
        <div class="mb-0"><label class="form-label">Disabled</label><input type="file" class="form-control" disabled></div>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var drop = document.getElementById('ukDrop'), input = document.getElementById('ukFile'), list = document.getElementById('ukFiles');
    function human(b) { return b > 1048576 ? (b/1048576).toFixed(1)+' MB' : (b/1024).toFixed(0)+' KB'; }
    function add(files) {
        Array.prototype.forEach.call(files, function (f) {
            var row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2 border rounded p-2 mb-2';
            var icon = f.type.indexOf('image') === 0 ? 'file-earmark-image text-info' : 'file-earmark text-secondary';
            row.innerHTML = '<i class="bi bi-' + icon + ' fs-5"></i>'
                + '<div class="flex-grow-1 min-w-0"><div class="small fw-medium text-truncate">' + f.name + '</div>'
                + '<div class="text-secondary small">' + human(f.size) + '</div></div>'
                + '<button type="button" class="btn btn-sm btn-light text-danger"><i class="bi bi-x-lg"></i></button>';
            row.querySelector('button').addEventListener('click', function () { row.remove(); });
            list.appendChild(row);
        });
    }
    drop.addEventListener('click', function () { input.click(); });
    input.addEventListener('change', function () { add(this.files); });
    ['dragover','dragenter'].forEach(function (e) { drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.style.background = '#eef0fe'; }); });
    ['dragleave','drop'].forEach(function (e) { drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.style.background = ''; }); });
    drop.addEventListener('drop', function (ev) { add(ev.dataTransfer.files); });
})();
</script>
<?= $this->endSection() ?>
