<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Reorder a list and move items between zones using the native HTML5 Drag &amp; Drop API.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Sortable list</h2>
        <div class="list-group" id="ukSort">
            <?php foreach (['Define requirements','Design mockups','Build API','Write tests','Deploy'] as $t): ?>
                <div class="list-group-item d-flex align-items-center gap-2" draggable="true">
                    <i class="bi bi-grip-vertical text-secondary" style="cursor:grab"></i><?= $t ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div></div>
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Two zones</h2>
        <div class="row g-2">
            <div class="col-6"><div class="text-secondary small mb-1">To do</div><div class="border rounded p-2 uk-zone" style="min-height:160px">
                <?php foreach (['Task A','Task B'] as $t): ?><div class="card uk-card mb-2" draggable="true"><div class="card-body p-2 small"><?= $t ?></div></div><?php endforeach; ?>
            </div></div>
            <div class="col-6"><div class="text-secondary small mb-1">Done</div><div class="border rounded p-2 uk-zone" style="min-height:160px">
                <div class="card uk-card mb-2" draggable="true"><div class="card-body p-2 small">Task C</div></div>
            </div></div>
        </div>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var dragged = null;
    function bind(el) {
        el.addEventListener('dragstart', function () { dragged = el; setTimeout(function () { el.style.opacity = '.4'; }, 0); });
        el.addEventListener('dragend', function () { el.style.opacity = '1'; });
    }
    document.querySelectorAll('[draggable="true"]').forEach(bind);

    // Sortable list — drop before the hovered item
    var list = document.getElementById('ukSort');
    list.addEventListener('dragover', function (e) {
        e.preventDefault();
        var after = [].slice.call(list.querySelectorAll('[draggable="true"]:not([style*="0.4"])'))
            .find(function (c) { return e.clientY < c.getBoundingClientRect().top + c.offsetHeight / 2; });
        if (!dragged) return;
        if (after) list.insertBefore(dragged, after); else list.appendChild(dragged);
    });

    // Zones
    document.querySelectorAll('.uk-zone').forEach(function (z) {
        z.addEventListener('dragover', function (e) { e.preventDefault(); z.style.background = '#eef0fe'; });
        z.addEventListener('dragleave', function () { z.style.background = ''; });
        z.addEventListener('drop', function (e) { e.preventDefault(); z.style.background = ''; if (dragged) z.appendChild(dragged); });
    });
})();
</script>
<?= $this->endSection() ?>
