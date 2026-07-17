<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?php
$stepLabels = ['pending' => 'Order placed', 'assigned' => 'Rider assigned', 'picked_up' => 'Picked up', 'out_for_delivery' => 'Out for delivery', 'delivered' => 'Delivered'];
$anyActive  = false;
foreach ($tracking['deliveries'] as $dd) {
    if (! $dd['is_terminal']) {
        $anyActive = true;
    }
}
$ago = static function (?string $ts): string {
    if (! $ts) {
        return '';
    }
    $secs = time() - strtotime($ts);
    if ($secs < 60) {
        return 'just now';
    }
    if ($secs < 3600) {
        return floor($secs / 60) . ' min ago';
    }
    if ($secs < 86400) {
        return floor($secs / 3600) . ' hr ago';
    }

    return floor($secs / 86400) . ' d ago';
};
?>
<?php if ($anyActive): ?><meta http-equiv="refresh" content="25"><?php endif; ?>

<div class="row justify-content-center"><div class="col-lg-8">
    <?php if ($m = session('success')): ?><div class="alert alert-success py-2"><?= esc($m) ?></div><?php endif; ?>
    <?php if ($m = session('error')): ?><div class="alert alert-danger py-2"><?= esc($m) ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <div class="text-secondary small">Tracking order</div>
            <h1 class="h5 mb-0"><?= esc($tracking['order_no']) ?></h1>
        </div>
        <div class="text-end">
            <div class="text-secondary small">Total</div>
            <div class="h6 mb-0 text-primary">₹<?= esc(number_format((float) $tracking['grand_total'], 2)) ?></div>
        </div>
    </div>

    <?php if (empty($tracking['deliveries'])): ?>
        <div class="st-empty"><i class="bi bi-truck"></i>No shipment has been created for this order yet. Check back shortly.</div>
    <?php endif; ?>

    <?php foreach ($tracking['deliveries'] as $d): ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><?= esc($d['vendor'] ?? $d['shop'] ?? 'Shipment') ?></span>
            <?php if ($d['status'] === 'failed'): ?><span class="badge text-bg-danger">Delivery failed</span>
            <?php elseif ($d['status'] === 'returned'): ?><span class="badge text-bg-secondary">Returned</span>
            <?php elseif ($d['status'] === 'delivered'): ?><span class="badge text-bg-success">Delivered</span>
            <?php else: ?><span class="badge text-bg-primary"><?= esc(str_replace('_', ' ', $d['status'])) ?></span><?php endif; ?>
        </div>
        <div class="card-body">

            <!-- Progress stepper -->
            <?php if ($d['step'] !== null): ?>
            <div class="d-flex justify-content-between text-center mb-3" style="gap:.25rem">
                <?php foreach ($d['steps'] as $i => $sk): $done = $i <= $d['step']; ?>
                    <div class="flex-fill">
                        <div class="rounded-circle mx-auto d-flex align-items-center justify-content-center <?= $done ? 'bg-success text-white' : 'bg-light text-secondary' ?>" style="width:30px;height:30px">
                            <i class="bi bi-<?= $done ? 'check-lg' : 'circle' ?>"></i>
                        </div>
                        <div class="small mt-1 <?= $done ? 'text-success fw-semibold' : 'text-secondary' ?>" style="font-size:.7rem"><?= esc($stepLabels[$sk] ?? $sk) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between small text-secondary mb-2">
                <?php if ($d['status'] === 'delivered' && $d['delivered_at']): ?>
                    <span><i class="bi bi-check-circle me-1 text-success"></i>Delivered <?= esc(date('d M, g:i a', strtotime((string) $d['delivered_at']))) ?></span>
                <?php elseif ($d['eta_at']): ?>
                    <span><i class="bi bi-clock me-1"></i>Est. arrival <?= esc(date('d M, g:i a', strtotime((string) $d['eta_at']))) ?></span>
                <?php endif; ?>
                <span>Sub-order <?= esc($d['sub_order_no'] ?? '—') ?></span>
            </div>

            <!-- Rider card -->
            <?php if ($d['rider'] && ! in_array($d['status'], ['delivered', 'failed', 'returned'], true)): ?>
            <div class="border rounded p-2 mb-2 bg-light-subtle">
                <div class="d-flex align-items-center">
                    <i class="bi bi-person-badge fs-4 me-2 text-primary"></i>
                    <div class="flex-grow-1">
                        <div class="fw-semibold"><?= esc($d['rider']['name'] ?? 'Your rider') ?></div>
                        <div class="small text-secondary"><?= esc($d['rider']['vehicle'] ?: 'Delivery partner') ?> · <?= esc($d['rider']['phone_masked']) ?></div>
                    </div>
                    <?php if ($d['rider']['lat'] && $d['rider']['lng']): ?>
                        <a class="btn btn-sm btn-outline-primary" target="_blank" href="https://www.google.com/maps/search/?api=1&query=<?= esc($d['rider']['lat'], 'attr') ?>,<?= esc($d['rider']['lng'], 'attr') ?>"><i class="bi bi-geo-alt"></i> Live</a>
                    <?php endif; ?>
                </div>
                <?php if ($d['rider']['last_seen']): ?>
                    <div class="small text-secondary mt-1"><i class="bi bi-broadcast me-1"></i>Location updated <?= esc($ago($d['rider']['last_seen'])) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Timeline -->
            <?php if (! empty($d['history'])): ?>
            <details class="mb-2"><summary class="small text-secondary">Activity timeline</summary>
                <ul class="list-unstyled small mt-2 mb-0 ps-1">
                <?php foreach (array_reverse($d['history']) as $h): ?>
                    <li class="mb-1"><span class="text-secondary"><?= esc(date('d M g:i a', strtotime((string) $h['created_at']))) ?></span> — <?= esc(str_replace('_', ' ', (string) $h['to_status'])) ?><?php if (! empty($h['note'])): ?> <span class="text-secondary">(<?= esc($h['note']) ?>)</span><?php endif; ?></li>
                <?php endforeach; ?>
                </ul>
            </details>
            <?php endif; ?>

            <!-- Existing review -->
            <?php if ($d['review']): ?>
                <div class="small"><span class="text-warning">
                    <?php for ($i = 1; $i <= 5; $i++): ?><i class="bi bi-star<?= $i <= (int) $d['review']['rating'] ? '-fill' : '' ?>"></i><?php endfor; ?>
                </span> <span class="text-secondary">You rated this delivery<?php if (! empty($d['review']['comment'])): ?> — "<?= esc($d['review']['comment']) ?>"<?php endif; ?></span></div>
            <?php endif; ?>

            <!-- Rate form -->
            <?php if ($d['can_rate']): ?>
            <form method="post" action="<?= site_url('store/deliveries/' . $d['id'] . '/rate') ?>" class="border-top pt-2 mt-2">
                <?= csrf_field() ?>
                <div class="small fw-semibold mb-1">Rate your delivery</div>
                <div class="rate-stars mb-2" data-input="rate-<?= $d['id'] ?>">
                    <?php for ($i = 1; $i <= 5; $i++): ?><i class="bi bi-star fs-5 text-warning me-1" role="button" data-v="<?= $i ?>"></i><?php endfor; ?>
                </div>
                <input type="hidden" name="rating" id="rate-<?= $d['id'] ?>" value="5">
                <div class="input-group input-group-sm">
                    <input name="comment" class="form-control" maxlength="500" placeholder="Add a comment (optional)">
                    <button class="btn btn-primary">Submit</button>
                </div>
            </form>
            <?php endif; ?>

            <!-- Dispute -->
            <?php if ($d['dispute']): ?>
                <div class="alert alert-warning py-2 small mt-2 mb-0"><i class="bi bi-flag me-1"></i>Reported: <?= esc($d['dispute']['reason']) ?> — <strong><?= esc(str_replace('_', ' ', (string) $d['dispute']['status'])) ?></strong><?php if (! empty($d['dispute']['resolution'])): ?><div class="mt-1"><?= esc($d['dispute']['resolution']) ?></div><?php endif; ?></div>
            <?php elseif ($d['can_dispute']): ?>
                <details class="mt-2"><summary class="small text-danger">Report a problem</summary>
                    <form method="post" action="<?= site_url('store/deliveries/' . $d['id'] . '/dispute') ?>" class="mt-2">
                        <?= csrf_field() ?>
                        <select name="reason" class="form-select form-select-sm mb-2">
                            <option value="not_delivered">Marked delivered but not received</option>
                            <option value="item_missing">Item missing / wrong item</option>
                            <option value="damaged">Item damaged</option>
                            <option value="rider_behaviour">Rider behaviour</option>
                            <option value="late">Excessively late</option>
                            <option value="other">Other</option>
                        </select>
                        <textarea name="detail" class="form-control form-control-sm mb-2" rows="2" maxlength="2000" placeholder="Tell us what happened"></textarea>
                        <button class="btn btn-sm btn-outline-danger w-100">Submit report</button>
                    </form>
                </details>
            <?php endif; ?>

        </div>
    </div>
    <?php endforeach; ?>

    <a href="<?= site_url('store/track') ?>" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Track another order</a>
</div></div>

<script>
document.querySelectorAll('.rate-stars').forEach(function (box) {
    var input = document.getElementById(box.dataset.input);
    box.querySelectorAll('[data-v]').forEach(function (star) {
        star.addEventListener('click', function () {
            var v = parseInt(star.dataset.v, 10);
            if (input) { input.value = v; }
            box.querySelectorAll('[data-v]').forEach(function (s) {
                s.classList.toggle('bi-star-fill', parseInt(s.dataset.v, 10) <= v);
                s.classList.toggle('bi-star', parseInt(s.dataset.v, 10) > v);
            });
        });
    });
});
</script>
<?= $this->endSection() ?>
