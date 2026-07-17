<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<div class="card mb-3"><div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
        <div class="text-secondary small">GSTIN</div>
        <div class="h5 mb-0"><?= esc($vendor['gstin'] ?? '—') ?> <span class="badge bg-<?= ($vendor['gstin_status'] ?? '') === 'verified' ? 'success' : 'secondary' ?>-subtle text-<?= ($vendor['gstin_status'] ?? '') === 'verified' ? 'success' : 'secondary' ?>"><?= esc($vendor['gstin_status'] ?? 'n/a') ?></span></div>
    </div>
    <div class="text-end"><div class="text-secondary small">Total tax collected</div><div class="h4 mb-0 text-primary">₹<?= esc(number_format($summary['cgst'] + $summary['sgst'] + $summary['igst'] + $summary['cess'], 2)) ?></div></div>
</div></div>

<div class="row g-3 mb-3">
    <?php foreach (['Taxable Value' => 'taxable', 'CGST' => 'cgst', 'SGST' => 'sgst', 'IGST' => 'igst'] as $label => $key): ?>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="text-secondary small"><?= $label ?></div><div class="h5 mb-0">₹<?= esc(number_format((float) $summary[$key], 2)) ?></div>
        </div></div></div>
    <?php endforeach; ?>
</div>

<div class="card"><div class="card-header fw-semibold">GST by sub-order</div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Sub-order</th><th>Place of supply</th><th>Taxable</th><th>CGST</th><th>SGST</th><th>IGST</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
            <tr>
                <td class="fw-semibold small"><?= esc($l['sub_order_no']) ?></td>
                <td><?= esc($l['place_of_supply']) ?></td>
                <td>₹<?= esc(number_format((float) $l['taxable_value'], 2)) ?></td>
                <td>₹<?= esc(number_format((float) $l['cgst'], 2)) ?></td>
                <td>₹<?= esc(number_format((float) $l['sgst'], 2)) ?></td>
                <td>₹<?= esc(number_format((float) $l['igst'], 2)) ?></td>
                <td>₹<?= esc(number_format((float) $l['grand_total'], 2)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($lines)): ?><tr><td colspan="7" class="text-center text-secondary py-4">No GST records yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
