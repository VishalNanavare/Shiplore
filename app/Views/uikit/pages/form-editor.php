<?= $this->extend('uikit/_layout') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/quill/quill.snow.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Rich-text WYSIWYG editor powered by Quill (loaded locally). Ideal for product descriptions and blog content.</p>

<div class="card uk-card"><div class="card-body">
    <h2 class="uk-section-title mb-3">Editor</h2>
    <div id="ukEditor" style="min-height:240px">
        <h3>Product description</h3>
        <p>Premium sound with <strong>active noise cancellation</strong>, 32-hour battery and <em>IPX5</em> water resistance.</p>
        <ul><li>Bluetooth 5.3</li><li>Touch controls</li><li>Multipoint pairing</li></ul>
    </div>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <span class="text-secondary small">Content is stored as HTML.</span>
        <button class="btn btn-primary btn-sm" id="ukEditorSave"><i class="bi bi-save me-1"></i>Save</button>
    </div>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/quill/quill.min.js') ?>"></script>
<script>
var quill = new Quill('#ukEditor', {
    theme: 'snow',
    modules: { toolbar: [
        [{ header: [1, 2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ color: [] }, { background: [] }],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['blockquote', 'code-block', 'link'],
        [{ align: [] }], ['clean']
    ] }
});
document.getElementById('ukEditorSave').addEventListener('click', function () {
    if (window.toastr) toastr.success('Saved ' + quill.root.innerHTML.length + ' chars of HTML');
});
</script>
<?= $this->endSection() ?>
