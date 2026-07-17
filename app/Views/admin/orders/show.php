<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$statusBadge  = ['created' => 'info', 'confirmed' => 'primary', 'partially_fulfilled' => 'warning', 'completed' => 'success', 'cancelled' => 'danger'];
$payBadge     = ['pending' => 'secondary', 'paid' => 'success', 'partially_paid' => 'warning', 'failed' => 'danger', 'refunded' => 'info'];
$soBadge      = ['pending' => 'secondary', 'confirmed' => 'primary', 'accepted' => 'primary', 'packed' => 'info', 'ready' => 'info', 'out_for_delivery' => 'warning', 'delivered' => 'success', 'completed' => 'success', 'cancelled' => 'danger', 'returned' => 'danger'];
$cancellable  = ! in_array($order['status'], ['cancelled', 'completed'], true);
// group items by sub_order
$itemsBySub = [];
foreach ($items as $it) { $itemsBySub[$it['sub_order_id']][] = $it; }
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<!-- Ownership + SLA card -->
<?php
$ageMinutes = isset($order['created_at']) ? round((time() - strtotime($order['created_at'])) / 60) : 0;
$slaCls = $ageMinutes < 5 ? 'success' : ($ageMinutes < 10 ? 'warning' : 'danger');
?>
<?php
$canManage      = service('policyEngine')->can(service('scopeContext')->all(), 'order.manage');
$priorityLabels = [0 => 'Normal', 1 => 'Express', 2 => 'VIP', 3 => 'Admin Urgent'];
$deliveryStages = ['pending', 'assigned', 'picked_up', 'arrived', 'out_for_delivery', 'delivered', 'failed', 'returned'];
$claimLogs      = $claimLogs ?? [];
$relTime        = static function (?string $ts): string {
    if (empty($ts)) { return ''; }
    $diff = time() - strtotime($ts);
    if ($diff < 60)    { return $diff . 's ago'; }
    if ($diff < 3600)  { return round($diff / 60) . 'm ago'; }
    if ($diff < 86400) { return round($diff / 3600) . 'h ago'; }
    return round($diff / 86400) . 'd ago';
};
$eventBadge = ['claimed' => 'primary', 'force_claimed' => 'dark', 'released' => 'secondary', 'force_released' => 'warning', 'escalated' => 'danger', 'expired' => 'secondary', 'delivery_override' => 'info', 'rider_assigned' => 'info', 'rider_reassigned' => 'info'];
?>
<?php if (! empty($subClaims)): ?>
<div class="row g-2 mb-3">
    <?php foreach ($subClaims as $sc): ?>
    <?php $subId = (int) ($sc['id'] ?? 0); ?>
    <div class="col-sm-6 col-lg-4">
        <div class="card border-<?= esc($slaCls, 'attr') ?>">
            <div class="card-body py-2 px-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="small fw-semibold"><?= esc($sc['sub_order_no']) ?></div>
                        <?php if (! empty($sc['claimed_by_role'])): ?>
                            <span class="badge text-bg-info me-1"><?= esc(service('orderClaimService')->roleLabel((string) $sc['claimed_by_role'])) ?></span>
                            <span class="small"><?= esc($sc['handler_name'] ?? '') ?></span>
                        <?php else: ?>
                            <span class="text-secondary small">Unclaimed</span>
                        <?php endif; ?>
                        <?php if (($sc['escalation_level'] ?? 'shop') !== 'shop'): ?>
                            <div class="small text-danger mt-1"><i class="bi bi-arrow-up-circle me-1"></i>Escalated to <?= esc(ucfirst($sc['escalation_level'])) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($canManage): ?>
                    <div class="d-flex gap-1">
                        <form method="post" action="<?= site_url('admin/orders/' . $subId . '/force-claim') ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-dark py-0" title="Force-claim as admin" onclick="return confirm('Take ownership of this order as admin?')">
                                <i class="bi bi-hand-index"></i>
                            </button>
                        </form>
                        <?php if (! empty($sc['claimed_by_role'])): ?>
                        <form method="post" action="<?= site_url('admin/orders/' . $subId . '/release-claim') ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-warning py-0" title="Force-release claim" onclick="return confirm('Release this order claim?')">
                                <i class="bi bi-unlock"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if (($sc['escalation_level'] ?? 'shop') !== 'shop'): ?>
                        <form method="post" action="<?= site_url('admin/orders/' . $subId . '/return-to-shop') ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-success py-0" title="Return to shop (de-escalate)" onclick="return confirm('Hand this order back to the shop? They will be able to accept and self-deliver it.')">
                                <i class="bi bi-arrow-down-circle"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="mt-1">
                    <span class="badge text-bg-<?= esc($slaCls, 'attr') ?> small"><i class="bi bi-clock me-1"></i><?= esc($ageMinutes) ?> min waiting</span>
                    <?php if (! empty($sc['priority_level']) && (int) $sc['priority_level'] > 0): ?>
                        <span class="badge text-bg-<?= (int) $sc['priority_level'] >= 3 ? 'danger' : 'warning' ?> small">
                            <?= (int) $sc['priority_level'] >= 3 ? 'URGENT' : ((int) $sc['priority_level'] === 2 ? 'VIP' : 'Express') ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($canManage): ?>
                <div class="mt-2 d-flex flex-column gap-1">
                    <form method="post" action="<?= site_url('admin/orders/' . $subId . '/priority') ?>" class="input-group input-group-sm">
                        <?= csrf_field() ?>
                        <select name="priority_level" class="form-select form-select-sm">
                            <?php foreach ($priorityLabels as $lvl => $lbl): ?>
                                <option value="<?= $lvl ?>" <?= (int) ($sc['priority_level'] ?? 0) === $lvl ? 'selected' : '' ?>><?= esc($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-outline-secondary" title="Set priority"><i class="bi bi-flag"></i></button>
                    </form>
                    <form method="post" action="<?= site_url('admin/orders/' . $subId . '/override-delivery') ?>" class="input-group input-group-sm">
                        <?= csrf_field() ?>
                        <select name="status" class="form-select form-select-sm">
                            <?php foreach ($deliveryStages as $stage): ?>
                                <option value="<?= esc($stage, 'attr') ?>"><?= esc(str_replace('_', ' ', $stage)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-outline-primary" title="Override delivery status" onclick="return confirm('Override the delivery status?')">Override</button>
                    </form>
                </div>
                <?php endif; ?>

                <?php $logs = $claimLogs[$subId] ?? []; ?>
                <?php if (! empty($logs)): ?>
                <div class="mt-2 border-top pt-2">
                    <div class="small text-secondary mb-1"><i class="bi bi-clock-history me-1"></i>Ownership history</div>
                    <ul class="list-unstyled mb-0 small">
                        <?php foreach (array_slice($logs, 0, 8) as $lg): ?>
                            <li class="mb-1 d-flex justify-content-between align-items-start gap-2">
                                <span>
                                    <span class="badge text-bg-<?= esc($eventBadge[$lg['event']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $lg['event'])) ?></span>
                                    <?php $who = $lg['to_role'] ?? $lg['from_role'] ?? null; ?>
                                    <?php if (! empty($who)): ?><span class="text-secondary"><?= esc(service('orderClaimService')->roleLabel((string) $who)) ?></span><?php endif; ?>
                                    <?php if (! empty($lg['actor_name'])): ?><span><?= esc($lg['actor_name']) ?></span><?php endif; ?>
                                    <?php if (! empty($lg['reason'])): ?><span class="text-secondary">— <?= esc($lg['reason']) ?></span><?php endif; ?>
                                </span>
                                <span class="text-secondary text-nowrap"><?= esc($relTime($lg['created_at'] ?? null)) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="mb-3 d-flex justify-content-between align-items-center">
    <a href="<?= site_url('admin/orders') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to orders</a>
    <?php if ($cancellable): ?>
        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelOrder"><i class="bi bi-x-circle me-1"></i>Cancel order</button>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <?php foreach ($subOrders as $so): ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><span class="fw-semibold"><?= esc($so['vendor'] ?? '—') ?></span> <span class="text-secondary small">· <?= esc($so['shop'] ?? '—') ?> · <?= esc($so['sub_order_no']) ?></span></span>
                    <span class="d-flex align-items-center gap-2">
                        <?php if (! empty($invoiceBySub[$so['id']])): ?>
                            <a href="<?= site_url('admin/invoices/' . (int) $invoiceBySub[$so['id']] . '/pdf') ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0"><i class="bi bi-filetype-pdf me-1"></i>Invoice</a>
                        <?php endif; ?>
                        <span class="badge text-bg-<?= esc($soBadge[$so['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $so['status'])) ?></span>
                    </span>
                </div>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Item</th><th>SKU</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Taxable</th></tr></thead>
                    <tbody>
                    <?php foreach ($itemsBySub[$so['id']] ?? [] as $it): ?>
                        <tr><td><?= esc($it['product_title_snapshot']) ?></td><td class="text-secondary small"><?= esc($it['sku_snapshot']) ?></td>
                            <td class="text-end"><?= esc(rtrim(rtrim((string) $it['qty'], '0'), '.')) ?></td>
                            <td class="text-end">₹<?= esc(number_format((float) $it['unit_price'], 2)) ?></td>
                            <td class="text-end">₹<?= esc(number_format((float) $it['taxable_value'], 2)) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($itemsBySub[$so['id']])): ?><tr><td colspan="5" class="text-secondary small text-center">No line items.</td></tr><?php endif; ?>
                    </tbody>
                    <tfoot><tr class="fw-semibold"><td colspan="4" class="text-end">Sub-order total</td><td class="text-end">₹<?= esc(number_format((float) $so['grand_total'], 2)) ?></td></tr>
                        <tr><td colspan="4" class="text-end text-secondary small">GST (C/S/I)</td><td class="text-end text-secondary small">₹<?= esc(number_format((float) $so['cgst'] + (float) $so['sgst'] + (float) $so['igst'], 2)) ?></td></tr>
                        <tr><td colspan="4" class="text-end text-secondary small">Commission</td><td class="text-end text-secondary small">₹<?= esc(number_format((float) $so['commission_amount'], 2)) ?></td></tr></tfoot>
                </table></div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($subOrders)): ?><div class="card"><div class="card-body text-secondary text-center py-4">No sub-orders.</div></div><?php endif; ?>

        <?php if (! empty($returns) || ! empty($refunds)): ?>
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="bi bi-arrow-counterclockwise me-1"></i>Returns &amp; refunds</div>
            <div class="card-body">
                <?php if (! empty($returns)): ?>
                    <div class="small text-secondary mb-1">Returns</div>
                    <table class="table table-sm align-middle mb-3">
                        <thead class="table-light"><tr><th>#</th><th>Reason</th><th>Status</th><th>Refund to</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($returns as $r): ?>
                            <tr><td>#<?= (int) $r['id'] ?></td><td class="small"><?= esc($r['reason'] ?? '—') ?></td>
                                <td><span class="badge text-bg-secondary"><?= esc(str_replace('_', ' ', $r['status'])) ?></span></td>
                                <td class="small"><?= esc($r['refund_to'] ?? '—') ?></td>
                                <td class="text-end"><a href="<?= site_url('admin/returns/' . $r['id']) ?>" class="btn btn-sm btn-outline-secondary py-0">View</a></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <?php if (! empty($refunds)): ?>
                    <div class="small text-secondary mb-1">Refunds</div>
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>#</th><th class="text-end">Amount</th><th>Method</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($refunds as $rf): ?>
                            <tr><td>#<?= (int) $rf['id'] ?></td><td class="text-end">₹<?= esc(number_format((float) $rf['amount'], 2)) ?></td>
                                <td class="small text-uppercase"><?= esc($rf['method'] ?? $rf['destination'] ?? '—') ?></td>
                                <td><span class="badge text-bg-secondary"><?= esc($rf['status']) ?></span></td>
                                <td class="text-end"><a href="<?= site_url('admin/refunds/' . $rf['id']) ?>" class="btn btn-sm btn-outline-secondary py-0">View</a></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-header fw-semibold">Summary</div><div class="card-body">
            <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Order</span><span class="fw-semibold"><?= esc($order['order_no']) ?></span></div>
            <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Status</span><span class="badge text-bg-<?= esc($statusBadge[$order['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $order['status'])) ?></span></div>
            <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Payment</span><span class="badge text-bg-<?= esc($payBadge[$order['payment_status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $order['payment_status'])) ?></span></div>
            <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Channel</span><span class="text-uppercase"><?= esc($order['channel']) ?></span></div>
            <hr class="my-2">
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">Subtotal</span><span>₹<?= esc(number_format((float) $order['subtotal'], 2)) ?></span></div>
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">Discount</span><span>−₹<?= esc(number_format((float) $order['discount_total'], 2)) ?></span></div>
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">Tax</span><span>₹<?= esc(number_format((float) $order['tax_total'], 2)) ?></span></div>
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">Delivery</span><span>₹<?= esc(number_format((float) $order['delivery_total'], 2)) ?></span></div>
            <hr class="my-2">
            <div class="d-flex justify-content-between fw-semibold"><span>Grand total</span><span class="text-primary">₹<?= esc(number_format((float) $order['grand_total'], 2)) ?></span></div>
        </div></div>
        <div class="card"><div class="card-header fw-semibold">Customer</div><div class="card-body">
            <div class="fw-medium"><?= esc($order['customer'] ?? '—') ?></div>
            <div class="text-secondary small"><?= esc($order['customer_email'] ?? '') ?></div>
        </div></div>
    </div>
</div>

<?php if ($cancellable): ?>
<div class="modal fade" id="cancelOrder" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="post" action="<?= site_url('admin/orders/' . $order['id'] . '/cancel') ?>">
        <?= csrf_field() ?>
        <div class="modal-header"><h5 class="modal-title">Cancel order <?= esc($order['order_no']) ?>?</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><p class="small text-secondary">This cancels the order and all its non-delivered sub-orders.</p><label class="form-label">Reason (optional)</label><textarea name="reason" class="form-control" rows="2"></textarea></div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep order</button><button class="btn btn-danger">Cancel order</button></div>
    </form>
</div></div></div>
<?php endif; ?>
<?= $this->endSection() ?>
