<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<?= view('partials/_shop_filter', ['shopOptions' => $shopOptions ?? [], 'shopId' => $shopId ?? null]) ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">My Invoices</span><span class="text-secondary small"><?= count($invoices) ?> shown</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Invoice No.</th><th>Date</th><th>Type</th><th>Shop</th><th>Sub-order</th><th>Total</th><th>Status</th><th class="text-end">PDF</th></tr></thead>
        <tbody>
        <?php foreach ($invoices as $i): ?>
            <tr>
                <td class="fw-semibold small"><?= esc($i['invoice_no']) ?></td>
                <td class="small"><?= esc($i['invoice_date']) ?></td>
                <td class="small text-capitalize"><?= esc(str_replace('_', ' ', $i['doc_type'])) ?></td>
                <td class="small"><?= esc($i['shop'] ?? '—') ?></td>
                <td class="small"><?= esc($i['sub_order_no'] ?? '—') ?></td>
                <td>₹<?= esc(number_format((float) $i['grand_total'], 2)) ?></td>
                <td><span class="badge text-bg-<?= $i['status'] === 'cancelled' ? 'danger' : 'success' ?>"><?= esc($i['status']) ?></span></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= site_url('vendor/invoices/' . $i['id'] . '/pdf') ?>"><i class="bi bi-filetype-pdf me-1"></i>PDF</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($invoices)): ?><tr><td colspan="8" class="text-center text-secondary py-4">No invoices yet — they generate when you dispatch an order.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
