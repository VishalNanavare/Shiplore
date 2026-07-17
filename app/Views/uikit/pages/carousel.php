<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Slideshows — controls + indicators + captions, crossfade, and a card/product carousel.</p>

<div class="row g-3">
    <div class="col-lg-7"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Controls, indicators & captions</h2>
        <div id="ukCarousel" class="carousel slide" data-bs-ride="carousel">
            <div class="carousel-indicators">
                <button data-bs-target="#ukCarousel" data-bs-slide-to="0" class="active"></button>
                <button data-bs-target="#ukCarousel" data-bs-slide-to="1"></button>
                <button data-bs-target="#ukCarousel" data-bs-slide-to="2"></button>
            </div>
            <div class="carousel-inner rounded">
                <?php foreach ([['#5b6ef5','Welcome','Build your storefront in minutes'],['#28c76f','Grow','Reach customers across channels'],['#ff9f43','Sell','Accept payments everywhere']] as $i=>$s): ?>
                    <div class="carousel-item <?= $i===0?'active':'' ?>"><div style="height:260px;background:<?= $s[0] ?>" class="d-grid text-white"><div class="align-self-center text-center"><div class="display-6"><?= $s[1] ?></div></div></div>
                        <div class="carousel-caption d-none d-md-block"><h5><?= $s[1] ?></h5><p><?= $s[2] ?></p></div></div>
                <?php endforeach; ?>
            </div>
            <button class="carousel-control-prev" data-bs-target="#ukCarousel" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span></button>
            <button class="carousel-control-next" data-bs-target="#ukCarousel" data-bs-slide="next"><span class="carousel-control-next-icon"></span></button>
        </div>
    </div></div></div>

    <div class="col-lg-5"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Crossfade</h2>
        <div id="ukFade" class="carousel slide carousel-fade mb-3" data-bs-ride="carousel">
            <div class="carousel-inner rounded"><?php foreach (['#00cfe8','#ea5455','#5b6ef5'] as $i=>$bg): ?><div class="carousel-item <?= $i===0?'active':'' ?>"><div style="height:120px;background:<?= $bg ?>"></div></div><?php endforeach; ?></div>
            <button class="carousel-control-prev" data-bs-target="#ukFade" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span></button>
            <button class="carousel-control-next" data-bs-target="#ukFade" data-bs-slide="next"><span class="carousel-control-next-icon"></span></button>
        </div>
        <h2 class="uk-section-title mb-2">Dark variant</h2>
        <div id="ukDark" class="carousel slide carousel-dark" data-bs-ride="carousel">
            <div class="carousel-inner rounded border"><?php foreach (['Slide one','Slide two'] as $i=>$t): ?><div class="carousel-item <?= $i===0?'active':'' ?>"><div style="height:100px;background:#f4f6fb" class="d-grid"><span class="align-self-center text-center fw-medium"><?= $t ?></span></div></div><?php endforeach; ?></div>
            <button class="carousel-control-prev" data-bs-target="#ukDark" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span></button>
            <button class="carousel-control-next" data-bs-target="#ukDark" data-bs-slide="next"><span class="carousel-control-next-icon"></span></button>
        </div>
    </div></div></div>
</div>
<?= $this->endSection() ?>
