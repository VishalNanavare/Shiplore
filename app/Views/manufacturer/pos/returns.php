<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php $q = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 3), '0'), '.') ?: '0'; ?>

<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>
<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Returns</h5>
    <a class="btn btn-sm btn-light" href="<?= site_url('manufacturer/pos') ?>">
        <i class="bi bi-arrow-left me-1"></i>Counter
    </a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label small" for="ref">Bill number</label>
                <input class="form-control" id="ref" name="ref" value="<?= esc($ref ?? '', 'attr') ?>"
                       placeholder="e.g. PA/2026-27/000123" autocomplete="off">
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Find bill</button></div>
        </form>
    </div>
</div>

<?php if (($ref ?? '') !== '' && empty($sale)): ?>
    <div class="alert alert-warning py-2">No completed bill with that number at your units.</div>
<?php endif; ?>

<?php if (! empty($sale)): ?>
    <form method="post" action="<?= site_url('manufacturer/pos/returns') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">

        <div class="card mb-3">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <strong><?= esc((string) $sale['invoice_no']) ?></strong>
                <span class="small text-secondary"><?= esc(substr((string) ($sale['sold_at'] ?? ''), 0, 16)) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                        <tr><th>Item</th><th class="text-end">Sold</th><th class="text-end">Already returned</th>
                            <th class="text-end">Can return</th><th class="text-end" style="width:140px">Return now</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach (($sale['items'] ?? []) as $it): ?>
                            <?php $left = (float) ($it['returnable_qty'] ?? 0); ?>
                            <tr class="<?= $left <= 0 ? 'text-secondary' : '' ?>">
                                <td>
                                    <?= esc((string) ($it['product_title_snapshot'] ?? '')) ?>
                                    <span class="small text-secondary ms-1"><?= esc((string) ($it['sku_snapshot'] ?? '')) ?></span>
                                </td>
                                <td class="text-end"><?= esc($q($it['qty'] ?? 0)) ?></td>
                                <td class="text-end"><?= esc($q($it['returned_qty'] ?? 0)) ?></td>
                                <td class="text-end fw-semibold"><?= esc($q($left)) ?></td>
                                <td class="text-end">
                                    <?php // max is the REMAINING quantity, so the form cannot offer
                                          // more than the cumulative rule would then refuse. ?>
                                    <input class="form-control form-control-sm text-end"
                                           name="qty[<?= (int) $it['id'] ?>]" type="number"
                                           step="0.001" min="0" max="<?= esc((string) $left, 'attr') ?>"
                                           <?= $left <= 0 ? 'disabled' : '' ?> placeholder="0">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer py-2">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small" for="rReason">Reason</label>
                        <input class="form-control form-control-sm" id="rReason" name="reason" maxlength="255" placeholder="damaged, wrong item, …">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="rMethod">Refund by</label>
                        <select class="form-select form-select-sm" id="rMethod" name="refund_method">
                            <option value="cash">Cash</option>
                            <option value="upi">UPI</option>
                            <option value="card">Card</option>
                            <option value="adjust">Adjustment</option>
                        </select>
                    </div>
                    <div class="col-md-5 text-end">
                        <button class="btn btn-danger" type="submit">Refund &amp; issue credit note</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
<?php endif; ?>

<?php if (! empty($recent)): ?>
    <div class="card">
        <div class="card-header py-2"><strong>Recent credit notes</strong></div>
        <ul class="list-group list-group-flush">
            <?php foreach ($recent as $r): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                    <a class="small" target="_blank" rel="noopener"
                       href="<?= site_url('manufacturer/pos/credit-note/' . (int) $r['id']) ?>">
                        <?= esc((string) $r['credit_note_no']) ?>
                    </a>
                    <span class="small text-secondary">against <?= esc((string) ($r['invoice_no'] ?? '')) ?></span>
                    <span class="small">₹<?= esc(number_format((float) $r['total'], 2)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?= $this->endSection() ?>
