<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?php
$badge    = ['pending' => 'secondary', 'confirmed' => 'primary', 'accepted' => 'primary', 'packed' => 'info', 'ready' => 'info', 'out_for_delivery' => 'warning', 'delivered' => 'success', 'completed' => 'success', 'cancelled' => 'danger', 'returned' => 'danger'];
$btnMap   = ['accepted' => ['label' => 'Accept Order', 'color' => 'success'], 'packed' => ['label' => 'Mark Packed', 'color' => 'info'], 'ready' => ['label' => 'Mark Ready', 'color' => 'primary'], 'out_for_delivery' => ['label' => 'Out for Delivery', 'color' => 'warning'], 'delivered' => ['label' => 'Mark Delivered', 'color' => 'success'], 'cancelled' => ['label' => 'Cancel Order', 'color' => 'danger']];
$terminal = in_array($sub['status'], ['delivered', 'completed', 'cancelled', 'returned'], true);
$claimedByOther = $claim !== null && (int) ($claim['claimed_by_user_id'] ?? 0) !== (int) session()->get('user_id');
$otpNeeded = ($sub['status'] === 'out_for_delivery') && empty($sub['otp_verified_at']);
?>
<div class="mb-3 d-flex align-items-center gap-2">
    <a href="<?= site_url('vendor/orders') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back to orders</a>
    <?php if ((int) ($sub['priority_level'] ?? 0) >= 3): ?>
        <span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>URGENT — Escalated to <?= esc(ucfirst($sub['escalation_level'] ?? '')) ?></span>
    <?php elseif (($sub['escalation_level'] ?? 'shop') === 'vendor'): ?>
        <span class="badge text-bg-warning">Escalated to Vendor</span>
    <?php endif; ?>
</div>

<?php if ($claimedByOther): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-lock-fill fs-5"></i>
    <div>
        <strong>Being handled by <?= esc(service('orderClaimService')->roleLabel((string) ($claim['claimed_by_role'] ?? ''))) ?> — <?= esc($claim['handler_name'] ?? 'Unknown') ?></strong><br>
        <small class="text-muted">
            Claimed <?= esc($claim['claimed_at'] ?? '') ?>
            <?php $expiresIn = (int) ($claim['expires_in_seconds'] ?? 0); ?>
            <?php if ($expiresIn > 0): ?>
                · Expires in <?= esc((int) ceil($expiresIn / 60)) ?> min
            <?php endif; ?>
        </small>
    </div>
</div>
<?php endif; ?>

<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>

<div class="row g-3">
    <!-- Order items -->
    <div class="col-lg-8">
        <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><?= esc($sub['sub_order_no']) ?> <span class="text-secondary small">· <?= esc($sub['order_no'] ?? '') ?></span></span>
            <span>
                <?php if (! empty($invoiceId)): ?>
                    <a class="btn btn-sm btn-outline-primary me-1" target="_blank" href="<?= site_url('vendor/invoices/' . (int) $invoiceId . '/pdf') ?>"><i class="bi bi-filetype-pdf me-1"></i>A4 Invoice</a>
                <?php endif; ?>
                <span class="badge text-bg-<?= esc($badge[$sub['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $sub['status'])) ?></span>
            </span>
        </div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Item</th><th>SKU</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Taxable</th></tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr><td><?= esc($it['product_title_snapshot']) ?></td><td class="text-secondary small"><?= esc($it['sku_snapshot']) ?></td>
                        <td class="text-end"><?= esc(rtrim(rtrim((string) $it['qty'], '0'), '.')) ?></td>
                        <td class="text-end">₹<?= esc(number_format((float) $it['unit_price'], 2)) ?></td>
                        <td class="text-end">₹<?= esc(number_format((float) $it['taxable_value'], 2)) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?><tr><td colspan="5" class="text-center text-secondary py-3">No items.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>

        <!-- Action buttons (hidden if locked by another user or terminal) -->
        <?php if (! $terminal && ! $claimedByOther): ?>
        <div class="card mb-3"><div class="card-header fw-semibold">Actions</div><div class="card-body">

            <!-- OTP Verification (required before marking delivered) -->
            <?php if ($otpNeeded): ?>
            <div class="alert alert-info mb-3">
                <i class="bi bi-shield-lock me-1"></i>
                <strong>OTP Verification required</strong> before marking delivered.<br>
                <small>Ask the customer for their 6-digit delivery OTP. Remaining attempts: <?= esc(5 - (int) $sub['otp_attempts']) ?></small>
            </div>
            <form method="post" action="<?= site_url('vendor/orders/' . (int) $sub['id'] . '/verify-otp') ?>" class="d-flex gap-2 mb-2">
                <?= csrf_field() ?>
                <input type="text" name="otp" maxlength="6" pattern="\d{6}" placeholder="6-digit OTP" class="form-control" style="max-width:180px;" required>
                <button class="btn btn-primary" type="submit"><i class="bi bi-check-circle me-1"></i>Verify OTP</button>
            </form>
            <?php if (($actorRole ?? '') === 'vendor'): ?>
            <!-- Regenerate OTP (owner-only): resets the code + clears the attempt lock. -->
            <form method="post" action="<?= site_url('vendor/orders/' . (int) $sub['id'] . '/regenerate-otp') ?>" class="mb-3">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-secondary" type="submit"
                        onclick="return confirm('Generate a fresh OTP and send it to the customer? This clears the attempt lock.')">
                    <i class="bi bi-arrow-repeat me-1"></i>Regenerate OTP
                </button>
            </form>
            <?php endif; ?>
            <?php elseif ($sub['status'] === 'out_for_delivery' && ! empty($sub['otp_verified_at'])): ?>
            <div class="alert alert-success mb-3"><i class="bi bi-check-circle me-1"></i>OTP verified at <?= esc($sub['otp_verified_at']) ?></div>
            <?php endif; ?>

            <!-- Status buttons -->
            <div class="d-flex flex-wrap gap-2">
            <?php foreach ($nextStatuses as $nextStatus): ?>
                <?php if ($nextStatus === 'delivered' && $otpNeeded) continue; // OTP must be verified first ?>
                <?php $btn = $btnMap[$nextStatus] ?? ['label' => ucfirst(str_replace('_', ' ', $nextStatus)), 'color' => 'secondary']; ?>
                <?php if ($nextStatus === 'cancelled'): ?>
                    <!-- Cancel: show a modal with reason -->
                    <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
                        <i class="bi bi-x-circle me-1"></i><?= esc($btn['label']) ?>
                    </button>
                <?php else: ?>
                    <form method="post" action="<?= site_url('vendor/orders/' . (int) $sub['id'] . '/status') ?>" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="status" value="<?= esc($nextStatus, 'attr') ?>">
                        <button class="btn btn-<?= esc($btn['color'], 'attr') ?>" type="submit"
                                onclick="return confirm('Move order to: <?= esc(str_replace('_', ' ', $nextStatus)) ?>?')">
                            <i class="bi bi-arrow-right-circle me-1"></i><?= esc($btn['label']) ?>
                        </button>
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>
            </div>
        </div></div>

        <!-- Rider assignment: show the (re)assign form while a delivery exists and no
             rider has ACCEPTED yet. Once accepted, the trip is locked to the rider. -->
        <?php if (! empty($delivery) && ! $riderAccepted): ?>
        <?php $isReassign = ! empty($delivery['rider_user_id']); ?>
        <div class="card mb-3"><div class="card-header fw-semibold"><?= $isReassign ? 'Reassign Rider' : 'Assign Rider' ?></div><div class="card-body">
            <?php if ($isReassign): ?>
            <div class="alert alert-warning py-2 mb-2 small">
                <i class="bi bi-info-circle me-1"></i>Offered to <strong><?= esc($delivery['rider_name'] ?? 'a rider') ?></strong> — not yet accepted. You may reassign.
            </div>
            <?php endif; ?>
            <form method="post" action="<?= site_url('vendor/orders/' . (int) $sub['id'] . '/assign-delivery') ?>">
                <?= csrf_field() ?>
                <div class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label small fw-semibold">Mode</label>
                        <select name="mode" class="form-select form-select-sm" id="deliveryMode" onchange="document.getElementById('riderRow').classList.toggle('d-none',this.value==='pool')">
                            <option value="pool">Auto-assign (Pool rider)</option>
                            <option value="self">Self-delivery (this shop)</option>
                        </select>
                    </div>
                    <div class="col-auto d-none" id="riderRow">
                        <label class="form-label small fw-semibold">Deliverer</label>
                        <select name="rider_user_id" class="form-select form-select-sm">
                            <option value="self" selected>Self — the shop delivers (no rider)</option>
                            <?php foreach ($availableRiders as $r): ?>
                                <option value="<?= esc($r['user_id'], 'attr') ?>"><?= esc($r['name']) ?> · <?= esc($r['phone']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-bicycle me-1"></i><?= $isReassign ? 'Reassign' : 'Assign' ?></button>
                    </div>
                </div>
            </form>
        </div></div>
        <?php elseif ($riderAccepted): ?>
        <div class="card mb-3"><div class="card-header fw-semibold">Rider</div><div class="card-body d-flex align-items-center gap-3">
            <i class="bi bi-person-circle fs-3 text-secondary"></i>
            <div>
                <div class="fw-semibold"><?= esc($delivery['rider_name'] ?? '—') ?></div>
                <div class="small text-secondary"><?= esc($delivery['rider_phone'] ?? '') ?> · Delivery <?= esc(str_replace('_', ' ', $delivery['delivery_status'] ?? '')) ?></div>
                <div class="small text-warning"><i class="bi bi-lock-fill me-1"></i>Rider en route — delivery locked to the rider</div>
            </div>
        </div></div>
        <?php endif; ?>

        <!-- Cancel modal -->
        <div class="modal fade" id="cancelModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
            <form method="post" action="<?= site_url('vendor/orders/' . (int) $sub['id'] . '/status') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="status" value="cancelled">
                <div class="modal-header"><h5 class="modal-title">Cancel Order</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <label class="form-label">Reason <span class="text-danger">*</span></label>
                    <textarea name="cancel_reason" class="form-control" rows="3" placeholder="Reason for cancellation…" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Back</button>
                    <button type="submit" class="btn btn-danger">Confirm Cancel</button>
                </div>
            </form>
        </div></div></div>
        <?php endif; ?>
    </div>

    <!-- Summary sidebar -->
    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-header fw-semibold">Summary</div><div class="card-body">
            <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Shop</span><span><?= esc($sub['shop'] ?? '—') ?></span></div>
            <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Customer</span><span><?= esc($sub['customer'] ?? '—') ?></span></div>
            <hr class="my-2">
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">Taxable</span><span>₹<?= esc(number_format((float) $sub['taxable_value'], 2)) ?></span></div>
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">GST (C+S+I)</span><span>₹<?= esc(number_format((float) $sub['cgst'] + (float) $sub['sgst'] + (float) $sub['igst'], 2)) ?></span></div>
            <div class="d-flex justify-content-between small mb-1"><span class="text-secondary">Commission</span><span>−₹<?= esc(number_format((float) $sub['commission_amount'], 2)) ?></span></div>
            <hr class="my-2">
            <div class="d-flex justify-content-between fw-semibold"><span>Grand total</span><span class="text-primary">₹<?= esc(number_format((float) $sub['grand_total'], 2)) ?></span></div>
        </div></div>

        <!-- Delivery OTP: the code is the CUSTOMER's proof of handoff. It is never
             shown to the vendor — the vendor only enters what the customer reads out
             (see the Verify OTP form above). Rendering it here would defeat the check. -->
    </div>
</div>

<!-- Heartbeat: keep claim alive while page is open -->
<?php if (! $claimedByOther && ! $terminal): ?>
<script>
(function() {
    const csrf = { name: '<?= csrf_token() ?>', value: '<?= csrf_hash() ?>' };
    function ping() {
        const fd = new FormData();
        fd.append(csrf.name, csrf.value);
        fetch('<?= site_url('vendor/orders/' . (int) $sub['id'] . '/heartbeat') ?>', { method: 'POST', body: fd })
            .then(r => r.json()).catch(() => {});
    }
    setInterval(ping, 60000);
})();
</script>
<?php endif; ?>
<?= $this->endSection() ?>
