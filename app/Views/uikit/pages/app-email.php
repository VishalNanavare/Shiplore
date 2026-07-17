<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="card uk-card overflow-hidden"><div class="d-flex" style="min-height:560px">
    <!-- Folders -->
    <div class="border-end p-3" style="width:210px">
        <button class="btn btn-primary w-100 mb-3"><i class="bi bi-pencil-square me-1"></i>Compose</button>
        <div class="list-group list-group-flush">
            <?php foreach ([['Inbox','inbox',true,'24'],['Starred','star',false,''],['Sent','send',false,''],['Drafts','file-earmark',false,'3'],['Spam','exclamation-octagon',false,''],['Trash','trash',false,'']] as $f): ?>
                <a href="#" class="list-group-item list-group-item-action border-0 px-2 d-flex justify-content-between <?= $f[2]?'active':'' ?>"><span><i class="bi bi-<?= $f[1] ?> me-2"></i><?= $f[0] ?></span><?php if($f[3]):?><span class="badge bg-primary rounded-pill"><?= $f[3] ?></span><?php endif;?></a>
            <?php endforeach; ?>
        </div>
        <div class="text-secondary small text-uppercase mt-3 mb-2 px-2">Labels</div>
        <div class="px-2 small">
            <div><i class="bi bi-circle-fill text-primary me-2" style="font-size:.6rem"></i>Work</div>
            <div><i class="bi bi-circle-fill text-success me-2" style="font-size:.6rem"></i>Personal</div>
            <div><i class="bi bi-circle-fill text-warning me-2" style="font-size:.6rem"></i>Billing</div>
        </div>
    </div>
    <!-- List -->
    <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-2 p-2 border-bottom">
            <input type="checkbox" class="form-check-input ms-2">
            <button class="btn btn-sm btn-light"><i class="bi bi-arrow-clockwise"></i></button>
            <div class="input-group input-group-sm ms-auto" style="max-width:280px"><span class="input-group-text"><i class="bi bi-search"></i></span><input class="form-control" placeholder="Search mail"></div>
        </div>
        <?php
        $mails = [
            ['RI','Riya Iyer','Q2 settlement report ready','The settlement report for Q2 is attached…','9:24 AM','primary',true,true],
            ['SN','Sahil Nair','Re: Vendor onboarding','Thanks, I have updated the GST details…','8:01 AM','success',false,true],
            ['AK','Aman Khan','Design review tomorrow','Can we lock the dashboard layout by…','Yesterday','warning',true,false],
            ['MD','Meera Das','Invoice #INV-2026-06','Please find the invoice for last month…','Yesterday','info',false,false],
            ['VN','Vivaan N.','Weekly summary','Orders up 12%, refunds down 3%…','Mon','secondary',false,false],
        ];
        foreach ($mails as $m): ?>
            <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom <?= $m[7]?'':'bg-light' ?>" style="cursor:pointer">
                <input type="checkbox" class="form-check-input">
                <i class="bi bi-star<?= $m[6]?'-fill text-warning':'' ?>"></i>
                <span class="rounded-circle bg-<?= $m[5] ?>-subtle text-<?= $m[5] ?> d-grid" style="width:34px;height:34px;place-items:center;font-weight:600;font-size:.75rem"><?= $m[0] ?></span>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex justify-content-between"><span class="<?= $m[7]?'fw-medium':'fw-bold' ?> small"><?= $m[1] ?></span><small class="text-secondary"><?= $m[4] ?></small></div>
                    <div class="small text-truncate"><span class="<?= $m[7]?'':'fw-semibold' ?>"><?= $m[2] ?></span> <span class="text-secondary">— <?= $m[3] ?></span></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div></div>
<?= $this->endSection() ?>
