<?php
/**
 * _store_order_detail.php — order detail body (status timeline + per-product
 * bill/cancel/return). Shared by the full page (store/account/order_detail) and
 * the account-dashboard modal. When $modal is true the action forms are
 * data-ajax-stay so they act without navigating (the modal refreshes itself).
 *
 * @var array  $order @var string $orderNo @var bool $canCancel @var bool $canReturn
 * @var int    $windowDays @var array $invoices @var bool $modal
 */
$modal  = $modal ?? false;
$stay   = $modal ? ' data-ajax-stay' : '';
$sb     = ['created' => 'secondary', 'confirmed' => 'info', 'partially_fulfilled' => 'warning', 'completed' => 'success', 'cancelled' => 'danger'];
$stages = ['confirmed' => 'Confirmed', 'accepted' => 'Accepted', 'packed' => 'Packed', 'out_for_delivery' => 'Out for delivery', 'delivered' => 'Delivered'];
$rank   = ['pending' => 0, 'created' => 0, 'confirmed' => 1, 'accepted' => 2, 'packed' => 3, 'ready' => 3, 'out_for_delivery' => 4, 'delivered' => 5, 'completed' => 5];
$lock   = ['out_for_delivery', 'delivered', 'completed', 'cancelled', 'returned'];
$now    = time();
$itemsBySub = [];
foreach (($order['items'] ?? []) as $it) {
    $itemsBySub[(int) $it['sub_order_id']][] = $it;
}
$subs = $order['sub_orders'] ?? [];
?>
<?php if (! $modal): ?>
    <?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
    <?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
    <nav class="small mb-2"><a href="<?= site_url('store/account?tab=orders') ?>" class="text-decoration-none">My Orders</a> › <?= esc($orderNo) ?></nav>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3"><div class="card-body d-flex flex-wrap justify-content-between gap-2">
    <div>
        <h2 class="h5 mb-1">Order <?= esc($orderNo) ?>
            <span class="badge text-bg-<?= esc($sb[$order['status']] ?? 'secondary', 'attr') ?> ms-1"><?= esc(str_replace('_', ' ', $order['status'])) ?></span>
        </h2>
        <div class="text-secondary small">Placed <?= esc(substr((string) ($order['placed_at'] ?? $order['created_at'] ?? ''), 0, 16)) ?> · Payment: <?= esc($order['payment_status'] ?? '') ?> · <?= count($subs) ?> product<?= count($subs) === 1 ? '' : 's' ?>, each billed separately</div>
    </div>
    <div class="text-end"><div class="text-secondary small">Order total</div><div class="h5 mb-0">₹<?= esc(number_format((float) $order['grand_total'], 2)) ?></div></div>
</div></div>

<?php foreach ($subs as $so):
    $cur           = $rank[$so['status']] ?? 0;
    $terminal      = in_array($so['status'], ['cancelled', 'returned'], true);
    $canCancelItem = ! in_array($so['status'], $lock, true);
    $delivered     = in_array($so['status'], ['delivered', 'completed'], true);
    $deliveredAt   = $delivered ? strtotime((string) ($so['updated_at'] ?? '')) : 0;
    $withinWindow  = $delivered && $deliveredAt > 0 && ($now - $deliveredAt) <= (int) $windowDays * 86400;
    $items         = $itemsBySub[(int) $so['id']] ?? [];
    $it0           = $items[0] ?? null;
    $inv           = $invoices[(int) $so['id']] ?? null;
?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <?php foreach ($items as $it): ?>
                    <div class="fw-semibold"><?= esc($it['title']) ?> <span class="text-secondary">× <?= (int) $it['qty'] ?></span></div>
                    <div class="text-secondary small"><?= esc($it['sku']) ?> · ₹<?= esc(number_format((float) $it['line_total'], 2)) ?></div>
                <?php endforeach; ?>
                <div class="text-secondary small mt-1"><i class="bi bi-shop me-1"></i><?= esc($so['vendor'] ?? 'Seller') ?> · Bill <?= esc($so['sub_order_no']) ?></div>
            </div>
            <span class="badge bg-light text-secondary border"><?= esc(str_replace('_', ' ', $so['status'])) ?></span>
        </div>

        <?php if ($terminal): ?>
            <div class="alert alert-<?= $so['status'] === 'returned' ? 'warning' : 'danger' ?> py-2 mb-3"><i class="bi bi-<?= $so['status'] === 'returned' ? 'arrow-counterclockwise' : 'x-circle' ?> me-1"></i><?= ucfirst($so['status']) ?></div>
        <?php else: ?>
            <div class="d-flex align-items-center mb-3" style="overflow-x:auto">
                <?php $i = 0; foreach ($stages as $k => $label): $done = $cur >= $rank[$k]; $i++; ?>
                    <?php if ($i > 1): ?><div class="flex-grow-1" style="height:2px;background:<?= $done ? '#198754' : '#dee2e6' ?>;min-width:18px"></div><?php endif; ?>
                    <div class="text-center" style="flex:0 0 auto">
                        <div class="rounded-circle d-inline-flex align-items-center justify-content-center" style="width:26px;height:26px;background:<?= $done ? '#198754' : '#e9ecef' ?>;color:<?= $done ? '#fff' : '#adb5bd' ?>"><i class="bi bi-<?= $done ? 'check' : 'circle' ?>"></i></div>
                        <div class="small mt-1" style="font-size:.68rem;color:<?= $done ? '#198754' : '#adb5bd' ?>"><?= esc($label) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if ($inv): ?>
                <a href="<?= site_url('store/account/invoices/' . (int) $inv['id'] . '/pdf') ?>" class="btn btn-sm btn-outline-secondary" title="<?= esc($inv['invoice_no'], 'attr') ?>"><i class="bi bi-filetype-pdf me-1"></i>Bill</a>
            <?php endif; ?>
            <?php if ($canCancelItem): ?>
                <form method="post" action="<?= site_url('store/account/orders/' . $orderNo . '/cancel-item/' . (int) $so['id']) ?>" data-confirm="Cancel this product? Any payment for it will be refunded."<?= $stay ?>>
                    <?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Cancel product</button>
                </form>
            <?php endif; ?>
            <?php if ($withinWindow && $it0 && ($it0['status'] ?? 'active') === 'active'): ?>
                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#ret<?= (int) $so['id'] ?>"><i class="bi bi-arrow-counterclockwise me-1"></i>Return</button>
            <?php elseif ($delivered && ! $withinWindow): ?>
                <span class="text-secondary small"><i class="bi bi-info-circle me-1"></i>Return window closed</span>
            <?php endif; ?>
        </div>

        <?php if ($withinWindow && $it0 && ($it0['status'] ?? 'active') === 'active'): ?>
            <div class="collapse mt-3" id="ret<?= (int) $so['id'] ?>">
                <form method="post" action="<?= site_url('store/account/orders/' . $orderNo . '/return') ?>" class="border rounded p-3 bg-light-subtle"<?= $stay ?>>
                    <?= csrf_field() ?>
                    <div class="small text-secondary mb-2">Return within <?= (int) $windowDays ?> day<?= (int) $windowDays === 1 ? '' : 's' ?> of delivery — a refund is initiated once approved.</div>
                    <div class="input-group input-group-sm mb-2" style="max-width:240px">
                        <span class="input-group-text">Return qty</span>
                        <input type="number" name="qty[<?= (int) $it0['id'] ?>]" min="1" max="<?= (int) $it0['qty'] ?>" value="<?= (int) $it0['qty'] ?>" class="form-control">
                    </div>
                    <textarea name="reason" rows="2" class="form-control form-control-sm mb-2" placeholder="Reason for return (optional)"></textarea>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Request return &amp; refund</button>
                </form>
            </div>
        <?php endif; ?>
    </div></div>
<?php endforeach; ?>

<?php if ($canCancel): ?>
    <div class="d-flex flex-wrap gap-2 mb-2">
        <form method="post" action="<?= site_url('store/account/orders/' . $orderNo . '/cancel') ?>" data-confirm="Cancel the entire order? Any payment will be refunded."<?= $stay ?>>
            <?= csrf_field() ?><button type="submit" class="btn btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Cancel entire order</button>
        </form>
    </div>
<?php endif; ?>
