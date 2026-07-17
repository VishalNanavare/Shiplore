<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Invoice Register</span>
        <form method="get" class="d-flex gap-2">
            <select name="doc_type" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All types</option>
                <?php foreach (['tax_invoice', 'bill_of_supply'] as $t): ?>
                    <option value="<?= $t ?>" <?= $docType === $t ? 'selected' : '' ?>><?= esc(str_replace('_', ' ', $t)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Invoice No.</th><th>Date</th><th>Type</th><th>Vendor</th><th>Shop</th><th>Sub-order</th><th>Total</th><th>Status</th><th class="text-end">PDF</th></tr></thead>
        <tbody>
        <?php foreach ($invoices as $i): ?>
            <tr>
                <td class="fw-semibold small"><?= esc($i['invoice_no']) ?></td>
                <td class="small"><?= esc($i['invoice_date']) ?></td>
                <td class="small text-capitalize"><?= esc(str_replace('_', ' ', $i['doc_type'])) ?></td>
                <td class="small"><?= esc($i['vendor'] ?? '—') ?></td>
                <td class="small"><?= esc($i['shop'] ?? '—') ?></td>
                <td class="small"><?= esc($i['sub_order_no'] ?? '—') ?></td>
                <td>₹<?= esc(number_format((float) $i['grand_total'], 2)) ?></td>
                <td><span class="badge text-bg-<?= $i['status'] === 'cancelled' ? 'danger' : 'success' ?>"><?= esc($i['status']) ?></span></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= site_url('admin/invoices/' . $i['id'] . '/pdf') ?>"><i class="bi bi-filetype-pdf me-1"></i>PDF</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($invoices)): ?><tr><td colspan="9" class="text-center text-secondary py-4">No invoices yet — they generate at dispatch.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
