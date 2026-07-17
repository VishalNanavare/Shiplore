<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Bootstrap Icons (1.11) — loaded locally. A sample of the 2,000+ available glyphs.</p>

<div class="card uk-card"><div class="card-body">
    <h2 class="uk-section-title mb-3">Sample set</h2>
    <div class="row g-2">
        <?php
        $icons = ['house-door','speedometer2','people','shop','geo-alt','bag','truck','credit-card','cash-stack','percent',
            'receipt','bell','gear','clipboard-data','box-seam','cart-check','graph-up-arrow','pie-chart','kanban','person-circle',
            'envelope','chat-dots','calendar-event','clock-history','star','heart','bookmark','flag','shield-check','lock',
            'search','funnel','filter','download','upload','printer','trash','pencil','plus-lg','check-lg'];
        foreach ($icons as $ic): ?>
            <div class="col-4 col-sm-3 col-md-2"><div class="uk-icon-tile"><i class="bi bi-<?= $ic ?>"></i><span><?= $ic ?></span></div></div>
        <?php endforeach; ?>
    </div>
    <p class="text-secondary small mt-3 mb-0">Usage: <code class="uk-code">&lt;i class="bi bi-house-door"&gt;&lt;/i&gt;</code></p>
</div></div>
<?= $this->endSection() ?>
