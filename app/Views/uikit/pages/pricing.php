<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="text-center mb-4">
    <h2 class="h4">Plans that scale with you</h2>
    <p class="text-secondary">Switch or cancel anytime. All prices exclude GST.</p>
    <div class="btn-group" role="group">
        <input type="radio" class="btn-check" name="billing" id="b-mo" checked><label class="btn btn-outline-primary btn-sm" for="b-mo">Monthly</label>
        <input type="radio" class="btn-check" name="billing" id="b-yr"><label class="btn btn-outline-primary btn-sm" for="b-yr">Yearly <span class="badge bg-success ms-1">-20%</span></label>
    </div>
</div>

<div class="row g-3 justify-content-center">
    <?php
    $plans = [
        ['Starter','₹999','For new vendors',['1 shop','Up to 100 products','Email support','Basic analytics'],false,'outline-primary'],
        ['Growth','₹2,499','Most popular',['5 shops','Unlimited products','Priority support','Advanced analytics','GST automation'],true,'primary'],
        ['Enterprise','₹6,999','For large chains',['Unlimited shops','Dedicated manager','SLA 99.9%','Custom integrations','POS + warehouse'],false,'outline-primary'],
    ];
    foreach ($plans as $p): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card uk-card h-100 <?= $p[4]?'border-primary':'' ?>">
                <?php if ($p[4]): ?><div class="text-center"><span class="badge bg-primary" style="margin-top:-11px">★ Popular</span></div><?php endif; ?>
                <div class="card-body text-center p-4">
                    <h3 class="h6 text-secondary"><?= $p[0] ?></h3>
                    <div class="h2 my-2"><?= $p[1] ?><span class="fs-6 text-secondary fw-normal">/mo</span></div>
                    <p class="text-secondary small"><?= $p[2] ?></p>
                    <ul class="list-unstyled text-start small my-3">
                        <?php foreach ($p[3] as $f): ?><li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i><?= $f ?></li><?php endforeach; ?>
                    </ul>
                    <button class="btn btn-<?= $p[5] ?> w-100">Choose <?= $p[0] ?></button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?= $this->endSection() ?>
