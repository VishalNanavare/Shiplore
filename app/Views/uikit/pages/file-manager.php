<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="row g-3">
    <div class="col-lg-3">
        <div class="card uk-card mb-3"><div class="card-body">
            <button class="btn btn-primary w-100 mb-3"><i class="bi bi-cloud-arrow-up me-1"></i>Upload</button>
            <div class="list-group list-group-flush">
                <?php foreach (['Folder'=>['My Files','folder2',true],'a'=>['Shared with me','people',false],'b'=>['Recent','clock',false],'c'=>['Starred','star',false],'d'=>['Trash','trash',false]] as $f): ?>
                    <a href="#" class="list-group-item list-group-item-action border-0 px-2 <?= $f[2]?'active':'' ?>"><i class="bi bi-<?= $f[1] ?> me-2"></i><?= $f[0] ?></a>
                <?php endforeach; ?>
            </div>
        </div></div>
        <div class="card uk-card"><div class="card-body">
            <div class="d-flex justify-content-between small mb-1"><span>Storage</span><span class="text-secondary">68.4 / 100 GB</span></div>
            <div class="progress" style="height:8px"><div class="progress-bar" style="width:68%"></div></div>
        </div></div>
    </div>
    <div class="col-lg-9">
        <div class="card uk-card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <nav style="--bs-breadcrumb-divider:'/'"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="#">My Files</a></li><li class="breadcrumb-item active">Documents</li></ol></nav>
                <div class="btn-group btn-group-sm"><button class="btn btn-primary"><i class="bi bi-grid"></i></button><button class="btn btn-outline-secondary"><i class="bi bi-list"></i></button></div>
            </div>
            <div class="row g-3">
                <?php
                $files = [
                    ['Reports','folder','warning','12 items'],['Invoices','folder','warning','48 items'],
                    ['Q2-summary.pdf','file-earmark-pdf','danger','2.4 MB'],['logo.svg','filetype-svg','primary','18 KB'],
                    ['payouts.xlsx','file-earmark-excel','success','640 KB'],['banner.png','file-earmark-image','info','1.1 MB'],
                    ['contract.docx','file-earmark-word','primary','320 KB'],['archive.zip','file-earmark-zip','secondary','22 MB'],
                ];
                foreach ($files as $f): ?>
                    <div class="col-6 col-md-4 col-xl-3">
                        <div class="uk-file">
                            <i class="bi bi-<?= $f[1] ?> text-<?= $f[2] ?>"></i>
                            <div class="small fw-medium text-truncate mt-2"><?= $f[0] ?></div>
                            <div class="text-secondary" style="font-size:.72rem"><?= $f[3] ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
