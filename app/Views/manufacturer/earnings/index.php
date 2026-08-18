<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php
$money = static fn ($v): string => '₹' . number_format((float) $v, 2);
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Earnings</h5>
    <a class="btn btn-sm btn-light" href="<?= site_url('manufacturer/purchase-orders') ?>">
        <i class="bi bi-receipt me-1"></i>Purchase orders
    </a>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-secondary small text-uppercase" style="letter-spacing:.06em">Earned</div>
            <div class="fs-3 fw-semibold"><?= esc($money($summary['earned'] ?? 0)) ?></div>
            <div class="small text-secondary">
                <?= (int) ($summary['earned_count'] ?? 0) ?> order<?= (int) ($summary['earned_count'] ?? 0) === 1 ? '' : 's' ?> received by the buyer
            </div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-secondary small text-uppercase" style="letter-spacing:.06em">In transit</div>
            <div class="fs-3 fw-semibold text-secondary"><?= esc($money($summary['in_transit'] ?? 0)) ?></div>
            <?php
            // Deliberately not added to Earned. Stock in transit can still be refused at
            // the door, and showing it as earned would promise money that may not arrive.
            ?>
            <div class="small text-secondary">Accepted or shipped, not yet confirmed received</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 border-0" style="background:transparent"><div class="card-body small text-secondary">
            Earnings are counted when the buyer <strong>confirms receipt</strong>, not when
            an order ships. Figures are gross — platform commission for B2B orders is not
            configured yet, so nothing is deducted here.
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header py-2"><strong>Received orders</strong></div>
    <?php if (empty($orders)): ?>
        <div class="card-body text-secondary">
            Nothing earned yet. An order counts here once the buyer confirms they have received it.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Placed</th>
                        <th>Status</th>
                        <th class="text-end">Value</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td class="fw-semibold"><?= esc((string) ($o['po_no'] ?? '')) ?></td>
                            <td class="text-secondary small"><?= esc(substr((string) ($o['created_at'] ?? ''), 0, 10)) ?></td>
                            <td>
                                <span class="badge bg-success-subtle text-success-emphasis">
                                    <?= esc(str_replace('_', ' ', (string) ($o['status'] ?? ''))) ?>
                                </span>
                            </td>
                            <td class="text-end"><?= esc($money($o['grand_total'] ?? 0)) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= site_url('manufacturer/purchase-orders/' . (int) ($o['id'] ?? 0)) ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
