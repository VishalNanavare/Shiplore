<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>
<h1 class="h4 mb-3">Support &amp; Returns</h1>
<div class="row g-3">
    <div class="col-lg-7"><div class="card"><div class="card-body">
        <h2 class="h6 mb-3">Request a return / refund</h2>
        <form method="post" action="<?= site_url('store/account/return') ?>">
            <?= csrf_field() ?>
            <div class="mb-2"><label class="form-label">Order</label>
                <select name="order_no" class="form-select" required>
                    <option value="">Select an order…</option>
                    <?php foreach ($orders as $o): ?><option value="<?= esc($o['order_no'], 'attr') ?>"><?= esc($o['order_no']) ?> · ₹<?= esc(number_format((float) $o['grand_total'], 0)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3"><label class="form-label">Reason</label><textarea name="reason" class="form-control" rows="3" placeholder="Tell us what went wrong…" required></textarea></div>
            <button class="btn btn-primary"><i class="bi bi-arrow-counterclockwise me-1"></i>Submit request</button>
        </form>
    </div></div></div>
    <div class="col-lg-5"><div class="card"><div class="card-body">
        <h2 class="h6 mb-2">Need help?</h2>
        <p class="text-secondary small mb-2">Our support team typically responds within 24 hours. Returns are eligible within 7 days of delivery.</p>
        <ul class="list-unstyled small mb-0">
            <li class="mb-1"><i class="bi bi-envelope me-2 text-secondary"></i>support@commercehub.io</li>
            <li><i class="bi bi-telephone me-2 text-secondary"></i>1800-123-4567</li>
        </ul>
    </div></div></div>
</div>
<?= $this->endSection() ?>
