<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Headings, display, inline elements, colors, weights, alignment, lists and blockquotes.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Headings & display</h2>
        <?php for ($i=1;$i<=6;$i++): ?><h<?= $i ?>>Heading <?= $i ?> <small class="text-secondary fs-6">secondary</small></h<?= $i ?>><?php endfor; ?>
        <p class="display-4 mt-2 mb-1">Display 4</p>
        <p class="display-6 mb-2">Display 6</p>
        <p class="lead mb-0">Lead paragraph — stands out from regular body copy for introductions.</p>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Inline elements</h2>
        <p>You can use <strong>bold</strong>, <em>italics</em>, <u>underline</u>, <s>strikethrough</s>, <mark>highlight</mark>, <small>small</small>, <code class="uk-code">code</code>, <kbd>Ctrl</kbd>+<kbd>C</kbd>, <abbr title="HyperText Markup Language">HTML</abbr> and <a href="#">links</a>.</p>
        <p class="mb-1">Subscript H<sub>2</sub>O · Superscript x<sup>2</sup></p>
        <h2 class="uk-section-title mt-3 mb-2">Colors & weights</h2>
        <div class="d-flex flex-wrap gap-3">
            <?php foreach (['primary','success','danger','warning','info','secondary','dark'] as $c): ?><span class="text-<?= $c ?>">.text-<?= $c ?></span><?php endforeach; ?>
        </div>
        <div class="mt-2"><span class="fw-light me-3">Light</span><span class="fw-normal me-3">Normal</span><span class="fw-semibold me-3">Semibold</span><span class="fw-bold">Bold</span></div>
        <div class="mt-2"><span class="text-uppercase me-3">uppercase</span><span class="text-lowercase me-3">LOWERCASE</span><span class="text-capitalize">capitalize me</span></div>
    </div></div></div>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Lists</h2>
        <div class="row">
            <div class="col-6"><ul><li>Unordered</li><li>list item<ul><li>nested</li></ul></li><li>another</li></ul></div>
            <div class="col-6"><ol><li>Ordered</li><li>list item</li><li>another</li></ol></div>
        </div>
        <ul class="list-inline"><li class="list-inline-item"><span class="badge bg-light text-secondary border">Inline</span></li><li class="list-inline-item"><span class="badge bg-light text-secondary border">list</span></li></ul>
        <dl class="row mb-0"><dt class="col-4">Term</dt><dd class="col-8">Definition list item.</dd><dt class="col-4">GST</dt><dd class="col-8 mb-0">Goods &amp; Services Tax.</dd></dl>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Blockquotes & alignment</h2>
        <blockquote class="blockquote border-start border-3 border-primary ps-3"><p class="mb-1">Simplicity is the ultimate sophistication.</p><footer class="blockquote-footer">Leonardo da Vinci</footer></blockquote>
        <p class="text-start border-bottom pb-1">Left aligned</p>
        <p class="text-center border-bottom pb-1">Center aligned</p>
        <p class="text-end mb-0">Right aligned</p>
    </div></div></div>
</div>
<?= $this->endSection() ?>
