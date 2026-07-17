<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Page navigation — default, active/disabled, icons, sizes, alignment, rounded and with summary.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Default</h2>
        <nav><ul class="pagination">
            <li class="page-item disabled"><a class="page-link" href="#">Previous</a></li>
            <li class="page-item active"><a class="page-link" href="#">1</a></li>
            <li class="page-item"><a class="page-link" href="#">2</a></li>
            <li class="page-item"><a class="page-link" href="#">3</a></li>
            <li class="page-item"><a class="page-link" href="#">Next</a></li>
        </ul></nav>
        <h2 class="uk-section-title mb-3">With icons</h2>
        <nav><ul class="pagination">
            <li class="page-item"><a class="page-link" href="#"><i class="bi bi-chevron-double-left"></i></a></li>
            <li class="page-item"><a class="page-link" href="#"><i class="bi bi-chevron-left"></i></a></li>
            <li class="page-item"><a class="page-link" href="#">1</a></li>
            <li class="page-item active"><a class="page-link" href="#">2</a></li>
            <li class="page-item"><a class="page-link" href="#">3</a></li>
            <li class="page-item"><a class="page-link" href="#"><i class="bi bi-chevron-right"></i></a></li>
            <li class="page-item"><a class="page-link" href="#"><i class="bi bi-chevron-double-right"></i></a></li>
        </ul></nav>
        <h2 class="uk-section-title mb-3">With ellipsis</h2>
        <nav><ul class="pagination">
            <li class="page-item active"><a class="page-link" href="#">1</a></li>
            <li class="page-item"><a class="page-link" href="#">2</a></li>
            <li class="page-item disabled"><span class="page-link">…</span></li>
            <li class="page-item"><a class="page-link" href="#">9</a></li>
            <li class="page-item"><a class="page-link" href="#">10</a></li>
        </ul></nav>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Sizes</h2>
        <nav><ul class="pagination pagination-lg"><li class="page-item active"><a class="page-link" href="#">1</a></li><li class="page-item"><a class="page-link" href="#">2</a></li><li class="page-item"><a class="page-link" href="#">3</a></li></ul></nav>
        <nav><ul class="pagination"><li class="page-item active"><a class="page-link" href="#">1</a></li><li class="page-item"><a class="page-link" href="#">2</a></li><li class="page-item"><a class="page-link" href="#">3</a></li></ul></nav>
        <nav><ul class="pagination pagination-sm"><li class="page-item active"><a class="page-link" href="#">1</a></li><li class="page-item"><a class="page-link" href="#">2</a></li><li class="page-item"><a class="page-link" href="#">3</a></li></ul></nav>
        <h2 class="uk-section-title mb-3">Alignment</h2>
        <nav><ul class="pagination justify-content-center"><li class="page-item"><a class="page-link" href="#">1</a></li><li class="page-item active"><a class="page-link" href="#">2</a></li><li class="page-item"><a class="page-link" href="#">3</a></li></ul></nav>
        <nav><ul class="pagination justify-content-end mb-0"><li class="page-item"><a class="page-link" href="#">1</a></li><li class="page-item active"><a class="page-link" href="#">2</a></li></ul></nav>
        <h2 class="uk-section-title mt-3 mb-2">With summary</h2>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="text-secondary small">Showing 1–10 of 96</span>
            <nav><ul class="pagination pagination-sm mb-0"><li class="page-item"><a class="page-link" href="#"><i class="bi bi-chevron-left"></i></a></li><li class="page-item active"><a class="page-link" href="#">1</a></li><li class="page-item"><a class="page-link" href="#">2</a></li><li class="page-item"><a class="page-link" href="#"><i class="bi bi-chevron-right"></i></a></li></ul></nav>
        </div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
