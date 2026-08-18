<?= $this->extend('layouts/manufacturer') ?>
<?= $this->section('content') ?>

<?php
$qty = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 3), '0'), '.') ?: '0';
$st  = (string) ($t['status'] ?? 'draft');
?>

<?php if (session('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session('error')) ?></div>
<?php endif; ?>
<?php if (session('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session('success')) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0"><?= esc((string) ($t['transfer_no'] ?? '')) ?></h5>
        <div class="text-secondary small">
            <?= esc((string) ($t['from_name'] ?? '')) ?> → <?= esc((string) ($t['to_name'] ?? '')) ?>
        </div>
    </div>
    <a class="btn btn-sm btn-light" href="<?= site_url('manufacturer/transfers') ?>">
        <i class="bi bi-arrow-left me-1"></i>All transfers
    </a>
</div>

<?php if ($st === 'dispatched'): ?>
    <div class="alert alert-warning py-2">
        <strong>In transit.</strong> These units have left
        <?= esc((string) ($t['from_name'] ?? 'the source')) ?> and are not yet counted at
        <?= esc((string) ($t['to_name'] ?? 'the destination')) ?>.
    </div>
<?php endif; ?>

<form method="post" action="<?= site_url('manufacturer/transfers/' . (int) ($t['id'] ?? 0) . '/receive') ?>">
    <?= csrf_field() ?>
    <div class="card mb-3">
        <div class="card-header py-2"><strong>Items</strong></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Item</th><th>SKU</th>
                        <th class="text-end">Sent</th>
                        <th class="text-end"><?= $st === 'received' ? 'Received' : 'Receiving' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($t['items'] ?? []) as $i): ?>
                        <?php $short = $i['qty_received'] !== null && (float) $i['qty_received'] < (float) $i['qty']; ?>
                        <tr>
                            <td><?= esc((string) ($i['title'] ?? '—')) ?></td>
                            <td class="text-secondary small"><?= esc((string) ($i['sku'] ?? '')) ?></td>
                            <td class="text-end"><?= esc($qty($i['qty'] ?? 0)) ?></td>
                            <td class="text-end" style="width:150px">
                                <?php if ($st === 'dispatched'): ?>
                                    <?php // Blank means "all of it", so correcting one short line
                                          // does not zero every other one. ?>
                                    <input class="form-control form-control-sm text-end"
                                           name="received[<?= (int) $i['variant_id'] ?>]"
                                           type="number" step="0.001" min="0"
                                           max="<?= esc((string) $i['qty'], 'attr') ?>"
                                           placeholder="<?= esc($qty($i['qty'] ?? 0), 'attr') ?>">
                                <?php elseif ($i['qty_received'] !== null): ?>
                                    <span class="<?= $short ? 'text-danger fw-semibold' : '' ?>">
                                        <?= esc($qty($i['qty_received'])) ?><?= $short ? ' ⚠' : '' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-secondary">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($st === 'dispatched'): ?>
            <div class="card-footer py-2 d-flex justify-content-between align-items-center">
                <span class="small text-secondary">Leave a box empty to receive the full quantity.</span>
                <button class="btn btn-sm btn-success" type="submit">Confirm receipt</button>
            </div>
        <?php endif; ?>
    </div>
</form>

<?php if ($st === 'draft'): ?>
    <form method="post" action="<?= site_url('manufacturer/transfers/' . (int) ($t['id'] ?? 0) . '/dispatch') ?>">
        <?= csrf_field() ?>
        <button class="btn btn-primary" type="submit">
            <i class="bi bi-box-arrow-right me-1"></i>Dispatch from <?= esc((string) ($t['from_name'] ?? '')) ?>
        </button>
        <span class="small text-secondary ms-2">This decrements the source unit's stock.</span>
    </form>
<?php endif; ?>

<?= $this->endSection() ?>
