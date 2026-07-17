<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0"><i class="bi bi-clock-history me-1"></i>Purchase History</h1>
  <a href="<?= site_url('vendor/purchase/add') ?>" class="btn btn-sm btn-success">+ Add Inventory</a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Our Invoice No</th><th>Supplier</th><th>Supplier Inv</th><th>Challan</th><th>Date</th><th class="text-end">Qty</th><th class="text-end">Grand Total</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No purchases yet.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td class="fw-semibold"><?= esc($r['our_invoice_no']) ?></td>
            <td><?= esc($r['supplier'] ?? '-') ?></td>
            <td><?= esc($r['supplier_invoice_id'] ?? '-') ?></td>
            <td><?= esc($r['challan_no'] ?? '-') ?></td>
            <td><?= esc($r['invoice_date'] ?? '-') ?></td>
            <td class="text-end"><?= esc(rtrim(rtrim((string) $r['total_qty'], '0'), '.')) ?></td>
            <td class="text-end fw-bold">₹<?= esc(number_format((float) $r['grand_total'], 2)) ?></td>
            <td><span class="badge bg-success"><?= esc($r['status']) ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?= $this->endSection() ?>
