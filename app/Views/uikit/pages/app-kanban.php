<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Drag cards between columns (native HTML5 drag &amp; drop).</p>

<div class="row g-3" id="ukKanban">
    <?php
    $cols = [
        ['Backlog','secondary',[['Research competitors','low','RI'],['Define MVP scope','medium','SN']]],
        ['In Progress','primary',[['POS offline sync','high','AK'],['Vendor KYC flow','medium','MD']]],
        ['Review','warning',[['Dashboard redesign','medium','VN']]],
        ['Done','success',[['Auth + sessions','high','RI'],['GST calculator','medium','SN'],['Seed cleanup','low','AK']]],
    ];
    $pri = ['low'=>'success','medium'=>'warning','high'=>'danger'];
    foreach ($cols as $c): ?>
        <div class="col-md-6 col-xl-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold small"><i class="bi bi-circle-fill text-<?= $c[1] ?> me-1" style="font-size:.6rem"></i><?= $c[0] ?> <span class="text-secondary">(<?= count($c[2]) ?>)</span></span>
                <button class="btn btn-sm btn-light"><i class="bi bi-plus-lg"></i></button>
            </div>
            <div class="uk-kanban-col" data-col="<?= $c[0] ?>" style="min-height:120px">
                <?php foreach ($c[2] as $card): ?>
                    <div class="card uk-card mb-2" draggable="true">
                        <div class="card-body p-2">
                            <span class="badge bg-<?= $pri[$card[1]] ?>-subtle text-<?= $pri[$card[1]] ?> mb-1"><?= ucfirst($card[1]) ?></span>
                            <div class="small fw-medium"><?= $card[0] ?></div>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="rounded-circle bg-primary-subtle text-primary d-grid" style="width:24px;height:24px;place-items:center;font-size:.62rem;font-weight:600"><?= $card[2] ?></span>
                                <span class="text-secondary small"><i class="bi bi-chat me-1"></i>2</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var dragged = null;
    document.querySelectorAll('#ukKanban [draggable="true"]').forEach(function (c) {
        c.addEventListener('dragstart', function () { dragged = c; setTimeout(function () { c.style.opacity = '.4'; }, 0); });
        c.addEventListener('dragend', function () { c.style.opacity = '1'; });
    });
    document.querySelectorAll('.uk-kanban-col').forEach(function (col) {
        col.addEventListener('dragover', function (e) { e.preventDefault(); col.style.background = '#eef0fe'; });
        col.addEventListener('dragleave', function () { col.style.background = ''; });
        col.addEventListener('drop', function (e) { e.preventDefault(); col.style.background = ''; if (dragged) col.appendChild(dragged); });
    });
})();
</script>
<?= $this->endSection() ?>
