<?= $this->extend('uikit/_layout') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/swiper/swiper-bundle.min.css') ?>">
<style>.swiper{border-radius:.6rem}.swiper-slide{height:200px;display:grid;place-items:center;color:#fff;font-size:1.4rem}.swiper-button-next,.swiper-button-prev{color:#fff}</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Modern touch slider powered by Swiper (loaded locally).</p>

<div class="row g-3">
    <div class="col-lg-7"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Slides with navigation</h2>
        <div class="swiper" id="sw1">
            <div class="swiper-wrapper">
                <?php foreach (['#5b6ef5'=>'Slide 1','#28c76f'=>'Slide 2','#ff9f43'=>'Slide 3','#ea5455'=>'Slide 4'] as $bg=>$t): ?>
                    <div class="swiper-slide" style="background:<?= $bg ?>"><?= $t ?></div>
                <?php endforeach; ?>
            </div>
            <div class="swiper-pagination"></div>
            <div class="swiper-button-next"></div><div class="swiper-button-prev"></div>
        </div>
    </div></div></div>
    <div class="col-lg-5"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Multiple per view</h2>
        <div class="swiper" id="sw2">
            <div class="swiper-wrapper">
                <?php foreach (['box-seam','smartwatch','bag','speaker','cup-hot','backpack'] as $ic): ?>
                    <div class="swiper-slide" style="background:#eef0fe;color:#5b6ef5;height:120px"><i class="bi bi-<?= $ic ?>" style="font-size:2.4rem"></i></div>
                <?php endforeach; ?>
            </div>
        </div>
        <p class="text-secondary small mt-3 mb-0">Autoplay, looped, 2–3 slides per view.</p>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/swiper/swiper-bundle.min.js') ?>"></script>
<script>
new Swiper('#sw1', { loop: true, pagination: { el: '.swiper-pagination', clickable: true }, navigation: { nextEl: '.swiper-button-next', prevEl: '.swiper-button-prev' } });
new Swiper('#sw2', { loop: true, slidesPerView: 2, spaceBetween: 12, autoplay: { delay: 1800 }, breakpoints: { 768: { slidesPerView: 3 } } });
</script>
<?= $this->endSection() ?>
