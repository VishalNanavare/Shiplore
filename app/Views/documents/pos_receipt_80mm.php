<?php
/**
 * documents/pos_receipt_80mm — DMart-style 80mm thermal tax invoice for dompdf.
 * Self-contained (no JS / @page print rules). Receives: $sale (header + items +
 * payments) and $bill (shop identity + settings from ShopBillSettingsRepository).
 */
$cfg = $bill['settings'] ?? \App\Models\ShopBillSettingsRepository::defaults();
$n   = static fn ($v): string => number_format((float) $v, 2);
$qty = static fn ($v): string => rtrim(rtrim((string) $v, '0'), '.');
$e   = static fn ($v): string => esc((string) ($v ?? ''));
$delivery = ($sale['order_type'] ?? 'takeaway') === 'delivery';

// savings = Σ (MRP − sell) × qty when MRP captured, else discount given
$savings = 0.0;
foreach (($sale['items'] ?? []) as $it) {
    $mrp = (float) ($it['mrp_snapshot'] ?? 0);
    if ($mrp > (float) $it['unit_price']) {
        $savings += ($mrp - (float) $it['unit_price']) * (float) $it['qty'];
    }
}
if ($savings <= 0.0) {
    $savings = (float) ($sale['discount_total'] ?? 0);
}

// per-slab GST summary (grouped by tax rate)
$gst = [];
$totQty = 0.0;
foreach (($sale['items'] ?? []) as $it) {
    $r = (float) $it['tax_rate'];
    $gst[$r] ??= ['taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0];
    $gst[$r]['taxable'] += (float) $it['taxable_value'];
    $gst[$r]['cgst']    += (float) $it['cgst'];
    $gst[$r]['sgst']    += (float) $it['sgst'];
    $gst[$r]['igst']    += (float) $it['igst'];
    $totQty += (float) $it['qty'];
}
ksort($gst);
$igstMode = (float) ($sale['igst'] ?? 0) > 0;
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><style>
  body { font-family: 'DejaVu Sans Mono', monospace; width: 76mm; margin: 0 auto; color: #000; font-size: 10px; }
  .c { text-align: center; } .r { text-align: right; } .b { font-weight: bold; }
  .xs { font-size: 8.5px; } .sm { font-size: 9.5px; } .lg { font-size: 13px; }
  table { width: 100%; border-collapse: collapse; } td, th { padding: 1px 0; vertical-align: top; }
  hr { border: none; border-top: 1px dashed #000; margin: 3px 0; }
  .strike { text-decoration: line-through; color: #555; }
  .save { border: 1px dashed #000; padding: 3px; text-align: center; font-weight: bold; margin-top: 3px; }
  .gst th, .gst td { border-bottom: 1px solid #bbb; font-size: 8.5px; text-align: right; }
  .gst th:first-child, .gst td:first-child { text-align: left; }
</style></head><body>
  <div class="c b lg"><?= $e($bill['shop_name'] ?? 'Store') ?></div>
  <?php if (! empty($bill['address'])): ?><div class="c xs"><?= $e($bill['address']) ?><?= ! empty($bill['pincode']) ? ' - ' . $e($bill['pincode']) : '' ?></div><?php endif; ?>
  <?php if (! empty($bill['phone'])): ?><div class="c xs">Ph: <?= $e($bill['phone']) ?></div><?php endif; ?>
  <?php if (! empty($bill['gstin'])): ?><div class="c xs">GSTIN: <?= $e($bill['gstin']) ?></div><?php endif; ?>
  <?php if (! empty($bill['fssai'])): ?><div class="c xs">FSSAI: <?= $e($bill['fssai']) ?></div><?php endif; ?>
  <div class="c sm b" style="margin-top:2px"><?= $e($cfg['header_note'] ?? 'Tax Invoice') ?></div>
  <hr>
  <table class="sm">
    <tr><td>Bill No</td><td class="r b"><?= $e($sale['server_invoice_no'] ?? ($sale['offline_invoice_no'] ?? '')) ?></td></tr>
    <tr><td>Date</td><td class="r"><?= $e(substr((string) ($sale['sold_at'] ?? ''), 0, 16)) ?></td></tr>
    <tr><td>Type</td><td class="r b"><?= $delivery ? 'DELIVERY' : 'TAKE AWAY' ?></td></tr>
    <?php if (! empty($sale['customer_name'])): ?><tr><td>Customer</td><td class="r"><?= $e($sale['customer_name']) ?></td></tr><?php endif; ?>
  </table>
  <hr>
  <table class="sm">
    <tr class="b"><td>Item</td><td class="r">MRP</td><td class="r">Rate</td><td class="r">Amt</td></tr>
    <?php foreach (($sale['items'] ?? []) as $it): $mrp = (float) ($it['mrp_snapshot'] ?? 0); ?>
      <tr><td colspan="4"><?= $e($it['product_title_snapshot']) ?> <span class="xs">(<?= $e($it['hsn_snapshot']) ?>)</span></td></tr>
      <tr>
        <td class="xs"><?= $qty($it['qty']) ?> x<?= (float) $it['tax_rate'] > 0 ? ' GST' . $qty($it['tax_rate']) . '%' : '' ?></td>
        <td class="r <?= $mrp > (float) $it['unit_price'] ? 'strike' : '' ?>"><?= $mrp > 0 ? $n($mrp) : '-' ?></td>
        <td class="r"><?= $n($it['unit_price']) ?></td>
        <td class="r"><?= $n($it['line_total']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <hr>
  <table class="sm">
    <tr><td>Subtotal</td><td class="r"><?= $n($sale['subtotal']) ?></td></tr>
    <?php if ((float) $sale['discount_total'] > 0): ?><tr><td>Discount</td><td class="r">-<?= $n($sale['discount_total']) ?></td></tr><?php endif; ?>
    <tr><td>Taxable</td><td class="r"><?= $n($sale['taxable_value']) ?></td></tr>
    <?php if ((float) $sale['cgst'] > 0): ?><tr><td>CGST</td><td class="r"><?= $n($sale['cgst']) ?></td></tr><tr><td>SGST</td><td class="r"><?= $n($sale['sgst']) ?></td></tr><?php endif; ?>
    <?php if ((float) $sale['igst'] > 0): ?><tr><td>IGST</td><td class="r"><?= $n($sale['igst']) ?></td></tr><?php endif; ?>
    <?php if ((float) ($sale['delivery_fee'] ?? 0) > 0): ?><tr><td>Delivery</td><td class="r"><?= $n($sale['delivery_fee']) ?></td></tr><?php endif; ?>
    <?php if ((float) $sale['round_off'] != 0.0): ?><tr><td>Round Off</td><td class="r"><?= $n($sale['round_off']) ?></td></tr><?php endif; ?>
  </table>
  <hr>
  <table><tr class="b lg"><td>TOTAL</td><td class="r">&#8377;<?= $n($sale['grand_total']) ?></td></tr></table>
  <table class="sm" style="margin-top:2px">
    <?php foreach (($sale['payments'] ?? []) as $p): ?><tr><td class="b" style="text-transform:uppercase"><?= $e($p['tender_type']) ?></td><td class="r"><?= $n($p['amount']) ?></td></tr><?php endforeach; ?>
    <tr><td>Items / Qty</td><td class="r"><?= count($sale['items'] ?? []) ?> / <?= $qty($totQty) ?></td></tr>
  </table>
  <?php if ($gst !== []): ?>
  <hr>
  <table class="gst">
    <tr><th>GST%</th><th>Taxable</th><?php if ($igstMode): ?><th>IGST</th><?php else: ?><th>CGST</th><th>SGST</th><?php endif; ?></tr>
    <?php foreach ($gst as $rate => $g): ?>
      <tr><td><?= $qty($rate) ?></td><td><?= $n($g['taxable']) ?></td><?php if ($igstMode): ?><td><?= $n($g['igst']) ?></td><?php else: ?><td><?= $n($g['cgst']) ?></td><td><?= $n($g['sgst']) ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  <?php if (! empty($cfg['show_savings']) && $savings > 0): ?>
    <div class="save sm">You Saved &#8377;<?= $n($savings) ?> on this bill</div>
  <?php endif; ?>
  <hr>
  <?php if (! empty($cfg['terms'])): ?><div class="xs" style="white-space:pre-line"><?= $e($cfg['terms']) ?></div><hr><?php endif; ?>
  <div class="c sm b"><?= $e($cfg['footer_note'] ?? 'Visit Again!') ?></div>
  <div class="c xs" style="margin-top:3px">This is a computer-generated invoice.</div>
</body></html>
