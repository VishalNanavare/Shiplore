<?php /** @var array $t */ $offer = $offer ?? false; ?>
<div class="rd-card p-3 mb-2">
    <a href="<?= site_url('rider/deliveries/' . $t['delivery_id']) ?>" class="rd-trip">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="fw-semibold"><?= esc($t['order_no'] ?? ('#' . $t['delivery_id'])) ?></div>
            <div class="text-end">
                <span class="rd-badge st-<?= esc($t['status'], 'attr') ?>"><?= esc(str_replace('_', ' ', $t['status'])) ?></span>
                <?php if (($t['cod_amount'] ?? '0.00') !== '0.00'): ?><span class="rd-badge rd-cod ms-1">COD ₹<?= esc(number_format((float) $t['cod_amount'], 0)) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="rd-leg mb-1"><span class="rd-pin"><i class="bi bi-shop"></i></span><div class="small"><span class="text-secondary">Pickup</span><br><?= esc($t['pickup']['shop'] ?? '—') ?></div></div>
        <div class="rd-leg"><span class="rd-pin"><i class="bi bi-geo-alt-fill"></i></span><div class="small"><span class="text-secondary">Drop</span><br><?= esc($t['drop']['name'] ?? '—') ?> · <?= esc($t['drop']['address'] ?? '') ?></div></div>
    </a>
    <?php if ($offer): ?>
        <form method="post" action="<?= site_url('rider/deliveries/' . $t['delivery_id'] . '/accept') ?>" class="mt-2">
            <?= csrf_field() ?>
            <button class="btn btn-rd btn-sm w-100"><i class="bi bi-check2 me-1"></i>Accept trip</button>
        </form>
    <?php endif; ?>
</div>
