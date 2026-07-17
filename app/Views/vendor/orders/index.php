<?= $this->extend('layouts/vendor') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$badge    = ['pending' => 'secondary', 'confirmed' => 'primary', 'accepted' => 'primary', 'packed' => 'info', 'ready' => 'info', 'out_for_delivery' => 'warning', 'delivered' => 'success', 'completed' => 'success', 'cancelled' => 'danger', 'returned' => 'danger'];
$escBadge = ['admin' => 'danger', 'vendor' => 'warning', 'shop' => 'light'];
?>

<!-- Ownership tabs (claim/escalation lens) -->
<ul class="nav nav-pills mb-2">
    <?php foreach (['' => 'All', 'mine' => 'My Orders', 'unclaimed' => 'Unclaimed', 'escalated' => 'Escalated', 'urgent' => 'Urgent'] as $o => $label): ?>
        <li class="nav-item"><a class="nav-link py-1 px-3 <?= ($filterKeep['own'] ?? '') === $o ? 'active' : '' ?>" href="<?= site_url('vendor/orders?' . http_build_query(array_merge($filterKeep ?? [], ['own' => $o]))) ?>"><?= esc($label) ?></a></li>
    <?php endforeach; ?>
</ul>

<!-- Filter tabs -->
<ul class="nav nav-pills mb-3">
    <?php foreach (['' => 'All', 'confirmed' => 'Pending', 'accepted' => 'Accepted', 'packed' => 'Packed', 'ready' => 'Ready', 'out_for_delivery' => 'Dispatched', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'] as $s => $label): ?>
        <li class="nav-item"><a class="nav-link py-1 px-3 <?= ($filterKeep['status'] ?? '') === $s ? 'active' : '' ?>" href="<?= site_url('vendor/orders?' . http_build_query(array_merge($filterKeep ?? [], ['status' => $s]))) ?>"><?= esc($label) ?></a></li>
    <?php endforeach; ?>
</ul>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Orders <span class="text-secondary small fw-normal"><?= count($orders) ?> shown</span></span>
        <span class="d-flex align-items-center gap-2">
            <?= view('partials/_shop_filter', ['shopOptions' => $shopOptions ?? [], 'shopId' => $shopId ?? null, 'filterKeep' => $filterKeep ?? []]) ?>
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($orders)): ?>
            <div class="text-center text-secondary py-5"><i class="bi bi-bag display-6 d-block mb-2"></i>No orders found.</div>
        <?php else: ?>
        <div class="table-responsive"><table id="vOrdersTable" class="table table-hover align-middle mb-0 w-100">
            <thead class="table-light"><tr>
                <th>Sub-order</th><th>Order</th><th>Shop</th><th>Customer</th>
                <th class="text-end">Total</th><th>Status</th><th>Handler</th>
                <th class="text-end">Waiting</th><th class="text-end">Claim expiry</th><th class="text-end"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <?php
                $isEscalated = ($o['escalation_level'] ?? 'shop') !== 'shop';
                $claimed     = ! empty($o['claimed_by_role']) && ! empty($o['claim_expires_at']) && strtotime($o['claim_expires_at']) > time();
                ?>
                <tr class="<?= $isEscalated ? 'table-danger' : '' ?>">
                    <td class="fw-semibold small">
                        <?= esc($o['sub_order_no']) ?>
                        <?php if ($isEscalated): ?>
                            <span class="badge text-bg-<?= esc($escBadge[$o['escalation_level']] ?? 'secondary', 'attr') ?> ms-1" title="Escalated to <?= esc(ucfirst($o['escalation_level'])) ?>">
                                <i class="bi bi-arrow-up-circle-fill"></i>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= esc($o['order_no'] ?? '—') ?></td>
                    <td class="small"><?= esc($o['shop'] ?? '—') ?></td>
                    <td><?= esc($o['customer'] ?? '—') ?></td>
                    <td class="text-end">₹<?= esc(number_format((float) $o['grand_total'], 2)) ?></td>
                    <td>
                        <span class="badge text-bg-<?= esc($badge[$o['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $o['status'])) ?></span>
                        <?php if ((int) ($o['priority_level'] ?? 0) >= 2): ?>
                            <span class="badge text-bg-danger ms-1">VIP</span>
                        <?php elseif ((int) ($o['priority_level'] ?? 0) === 1): ?>
                            <span class="badge text-bg-warning ms-1">Express</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($claimed): ?>
                            <span class="badge text-bg-light border text-dark">
                                <?= esc(service('orderClaimService')->roleLabel((string) $o['claimed_by_role'])) ?>
                                <?php if (! empty($o['handler_name'])): ?> · <?= esc($o['handler_name']) ?><?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span class="text-secondary">—</span>
                        <?php endif; ?>
                    </td>
                    <?php $waitMin = isset($o['waiting_min']) ? (int) $o['waiting_min'] : null; ?>
                    <td class="text-end small">
                        <?php if ($waitMin === null): ?>
                            <span class="text-secondary">—</span>
                        <?php else: ?>
                            <span class="<?= $waitMin > 10 ? 'text-danger fw-semibold' : 'text-secondary' ?>"><?= esc($waitMin) ?> min</span>
                        <?php endif; ?>
                    </td>
                    <?php $claimSec = isset($o['claim_expires_in']) ? (int) $o['claim_expires_in'] : 0; ?>
                    <td class="text-end small">
                        <?php if ($claimed && $claimSec > 0): ?>
                            <?= esc((int) ceil($claimSec / 60)) ?> min
                        <?php else: ?>
                            <span class="text-secondary">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><a href="<?= site_url('vendor/orders/' . $o['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/datatables/jquery.dataTables.min.js') ?>"></script>
<script src="<?= asset('plugins/datatables/dataTables.bootstrap5.min.js') ?>"></script>
<script>$(function(){ if($.fn.DataTable && document.getElementById('vOrdersTable')) $('#vOrdersTable').DataTable({ pageLength: 25, order: [], columnDefs:[{orderable:false,targets:9}] }); });</script>
<?= $this->endSection() ?>
