<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="row g-3">
    <div class="col-lg-3"><div class="card uk-card"><div class="card-body">
        <button class="btn btn-primary w-100 mb-3"><i class="bi bi-plus-lg me-1"></i>Add Task</button>
        <div class="list-group list-group-flush">
            <a href="#" class="list-group-item list-group-item-action border-0 px-2 active"><i class="bi bi-list-check me-2"></i>All <span class="badge bg-light text-dark float-end">6</span></a>
            <a href="#" class="list-group-item list-group-item-action border-0 px-2"><i class="bi bi-star me-2"></i>Important</a>
            <a href="#" class="list-group-item list-group-item-action border-0 px-2"><i class="bi bi-check2-all me-2"></i>Completed</a>
        </div>
        <div class="text-secondary small text-uppercase mt-3 mb-2 px-2">Tags</div>
        <div class="px-2"><span class="badge bg-primary-subtle text-primary me-1">Work</span><span class="badge bg-success-subtle text-success me-1">Personal</span><span class="badge bg-warning-subtle text-warning">Urgent</span></div>
    </div></div></div>
    <div class="col-lg-9"><div class="card uk-card"><div class="card-body">
        <div class="input-group mb-3"><span class="input-group-text"><i class="bi bi-search"></i></span><input class="form-control" placeholder="Search tasks…"></div>
        <?php
        $tasks = [
            ['Finalize GST invoice template',true,'high','Work'],
            ['Call vendor "Fresh Foods"',false,'medium','Work'],
            ['Review settlement disputes',false,'high','Urgent'],
            ['Update profile photo',true,'low','Personal'],
            ['Prepare Q2 board deck',false,'medium','Work'],
            ['Renew SSL certificate',false,'high','Urgent'],
        ];
        $pc = ['low'=>'success','medium'=>'warning','high'=>'danger'];
        foreach ($tasks as $i=>$t): ?>
            <div class="d-flex align-items-center gap-2 py-2 border-bottom">
                <input class="form-check-input mt-0" type="checkbox" <?= $t[1]?'checked':'' ?>>
                <span class="flex-grow-1 small <?= $t[1]?'text-decoration-line-through text-secondary':'' ?>"><?= $t[0] ?></span>
                <span class="badge bg-<?= $pc[$t[2]] ?>-subtle text-<?= $pc[$t[2]] ?>"><?= ucfirst($t[2]) ?></span>
                <span class="badge bg-light text-secondary border"><?= $t[3] ?></span>
                <button class="btn btn-sm btn-light"><i class="bi bi-star<?= $i===2?'-fill text-warning':'' ?>"></i></button>
                <button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
            </div>
        <?php endforeach; ?>
    </div></div></div>
</div>
<?= $this->endSection() ?>
