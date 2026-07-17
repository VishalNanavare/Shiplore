<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">SweetAlert2 — beautiful, responsive, customizable replacement for JS alerts. Loaded locally.</p>

<div class="card uk-card"><div class="card-body">
    <h2 class="uk-section-title mb-3">Examples</h2>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-primary" data-swal="basic">Basic</button>
        <button class="btn btn-success" data-swal="success">Success</button>
        <button class="btn btn-danger" data-swal="error">Error</button>
        <button class="btn btn-warning" data-swal="confirm">Confirm</button>
        <button class="btn btn-info" data-swal="input">Prompt</button>
        <button class="btn btn-secondary" data-swal="toast">Toast</button>
    </div>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.querySelectorAll('[data-swal]').forEach(function (b) {
    b.addEventListener('click', function () {
        switch (b.dataset.swal) {
            case 'basic':   Swal.fire('Hello!', 'This is a SweetAlert2 dialog.', 'question'); break;
            case 'success': Swal.fire({ icon: 'success', title: 'Saved!', text: 'Your changes were saved.' }); break;
            case 'error':   Swal.fire({ icon: 'error', title: 'Oops…', text: 'Something went wrong.' }); break;
            case 'confirm': Swal.fire({
                title: 'Delete item?', text: 'This cannot be undone.', icon: 'warning',
                showCancelButton: true, confirmButtonColor: '#ea5455', confirmButtonText: 'Yes, delete'
            }).then(function (r) { if (r.isConfirmed) Swal.fire('Deleted!', '', 'success'); }); break;
            case 'input':   Swal.fire({ title: 'Your name?', input: 'text', showCancelButton: true })
                .then(function (r) { if (r.value) Swal.fire('Hi ' + r.value + '!'); }); break;
            case 'toast':   Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Signed in', showConfirmButton: false, timer: 2500 }); break;
        }
    });
});
</script>
<?= $this->endSection() ?>
