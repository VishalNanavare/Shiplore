<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="card uk-card overflow-hidden"><div class="uk-chat">
    <!-- Contacts -->
    <div class="uk-chat-list">
        <div class="p-3 border-bottom"><div class="input-group input-group-sm"><span class="input-group-text"><i class="bi bi-search"></i></span><input class="form-control" placeholder="Search…"></div></div>
        <?php
        $contacts = [
            ['RI','Riya Iyer','Sounds good 👍','2m','success',true],
            ['SN','Sahil Nair','Sent the invoice','9m','success',false],
            ['AK','Aman Khan','Can we meet tomorrow?','1h','warning',false],
            ['MD','Meera Das','Thanks!','3h','secondary',false],
            ['VN','Vivaan N.','Typing…','—','secondary',false],
        ];
        foreach ($contacts as $i => $c): ?>
            <div class="uk-chat-contact <?= $i===0?'active':'' ?>">
                <div class="position-relative">
                    <span class="rounded-circle bg-<?= $c[4] ?>-subtle text-<?= $c[4] ?> d-grid" style="width:42px;height:42px;place-items:center;font-weight:600"><?= $c[0] ?></span>
                    <?php if ($c[5]): ?><span class="position-absolute bottom-0 end-0 bg-success rounded-circle border border-2 border-white" style="width:11px;height:11px"></span><?php endif; ?>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex justify-content-between"><span class="fw-medium small text-truncate"><?= $c[1] ?></span><small class="text-secondary"><?= $c[3] ?></small></div>
                    <div class="text-secondary small text-truncate"><?= $c[2] ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <!-- Thread -->
    <div class="uk-chat-main">
        <div class="d-flex align-items-center gap-2 p-3 border-bottom">
            <span class="rounded-circle bg-success-subtle text-success d-grid" style="width:38px;height:38px;place-items:center;font-weight:600">RI</span>
            <div><div class="fw-medium small">Riya Iyer</div><div class="text-success small">online</div></div>
            <div class="ms-auto d-flex gap-1"><button class="btn btn-sm btn-light"><i class="bi bi-telephone"></i></button><button class="btn btn-sm btn-light"><i class="bi bi-camera-video"></i></button><button class="btn btn-sm btn-light"><i class="bi bi-three-dots-vertical"></i></button></div>
        </div>
        <div class="uk-chat-body">
            <div class="uk-bubble in">Hi! Did you get a chance to review the proposal?</div>
            <div class="uk-bubble out">Yes, going through it now. Looks solid.</div>
            <div class="uk-bubble in">Great — any changes needed on pricing?</div>
            <div class="uk-bubble out">Just the GST line. I'll send an update shortly.</div>
            <div class="uk-bubble in">Sounds good 👍</div>
        </div>
        <div class="p-3 border-top">
            <div class="input-group">
                <button class="btn btn-light"><i class="bi bi-paperclip"></i></button>
                <input class="form-control" placeholder="Type a message…">
                <button class="btn btn-primary"><i class="bi bi-send"></i></button>
            </div>
        </div>
    </div>
</div></div>
<?= $this->endSection() ?>
