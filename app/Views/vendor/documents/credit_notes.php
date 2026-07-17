<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<div class="card">
    <div class="card-header fw-semibold">My Credit Notes</div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>CN No.</th><th>Date</th><th>Shop</th><th>Sub-order</th><th>Credit</th><th>Status</th><th class="text-end">PDF</th></tr></thead>
        <tbody>
        <?php foreach ($notes as $n): ?>
            <tr>
                <td class="fw-semibold small"><?= esc($n['credit_note_no']) ?></td>
                <td class="small"><?= esc($n['cn_date']) ?></td>
                <td class="small"><?= esc($n['shop'] ?? '—') ?></td>
                <td class="small"><?= esc($n['sub_order_no'] ?? '—') ?></td>
                <td>₹<?= esc(number_format((float) $n['grand_total'], 2)) ?></td>
                <td><span class="badge text-bg-<?= $n['status'] === 'cancelled' ? 'danger' : 'success' ?>"><?= esc($n['status']) ?></span></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= site_url('vendor/credit-notes/' . $n['id'] . '/pdf') ?>"><i class="bi bi-filetype-pdf me-1"></i>PDF</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($notes)): ?><tr><td colspan="7" class="text-center text-secondary py-4">No credit notes against your orders.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
