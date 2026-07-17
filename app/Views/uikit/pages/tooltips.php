<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Tooltips & popovers — placements, on links/icons, colored, hover/click triggers and dismiss-on-next-click. Initialised in <code class="uk-code">uikit.js</code>.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Tooltip placements</h2>
        <div class="d-flex gap-2 flex-wrap mb-4">
            <?php foreach (['top','bottom','left','right'] as $p): ?><button class="btn btn-light" data-bs-toggle="tooltip" data-bs-placement="<?= $p ?>" title="<?= ucfirst($p) ?> tooltip"><?= ucfirst($p) ?></button><?php endforeach; ?>
        </div>
        <h2 class="uk-section-title mb-3">On links & icons</h2>
        <p class="mb-2">Inline <a href="#" data-bs-toggle="tooltip" title="Helpful hint">tooltip link</a> in text.</p>
        <span data-bs-toggle="tooltip" title="Settings"><i class="bi bi-gear fs-4 me-3"></i></span>
        <span data-bs-toggle="tooltip" title="Notifications"><i class="bi bi-bell fs-4 me-3"></i></span>
        <span data-bs-toggle="tooltip" title="Help"><i class="bi bi-question-circle fs-4"></i></span>
        <h2 class="uk-section-title mt-3 mb-2">HTML tooltip</h2>
        <button class="btn btn-outline-primary" data-bs-toggle="tooltip" data-bs-html="true" title="<b>Bold</b> and <span class='text-warning'>colored</span>">HTML content</button>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Popovers</h2>
        <div class="d-flex gap-2 flex-wrap mb-4">
            <button class="btn btn-primary" data-bs-toggle="popover" data-bs-title="Popover title" data-bs-content="And here's some amazing content.">Click</button>
            <button class="btn btn-outline-secondary" data-bs-toggle="popover" data-bs-trigger="hover" data-bs-title="Hover" data-bs-content="Shown on hover.">Hover</button>
            <?php foreach (['top','bottom','left','right'] as $p): ?><button class="btn btn-light" data-bs-toggle="popover" data-bs-placement="<?= $p ?>" data-bs-content="<?= ucfirst($p) ?> popover"><?= ucfirst($p) ?></button><?php endforeach; ?>
        </div>
        <h2 class="uk-section-title mb-3">Dismiss on next click</h2>
        <a tabindex="0" class="btn btn-outline-primary" role="button" data-bs-toggle="popover" data-bs-trigger="focus" data-bs-title="Dismissible" data-bs-content="Click anywhere to close.">Focusable</a>
    </div></div></div>
</div>
<?= $this->endSection() ?>
