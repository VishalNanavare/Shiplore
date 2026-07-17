<?php
/**
 * 80mm POS receipt — shop identity + GST line-item detail + savings + terms.
 * Receives: $sale (header + items + payments) and $bill (shop identity + settings
 * from ShopBillSettingsRepository::forShop()).
 */
$n   = static fn ($v): string => '₹' . number_format((float) $v, 2);
$cfg = $bill['settings'] ?? \App\Models\ShopBillSettingsRepository::defaults();

// savings = Σ (MRP − sell) × qty when MRP was captured, else the discount given
$savings = 0.0;
foreach (($sale['items'] ?? []) as $it) {
    $mrp = (float) ($it['mrp_snapshot'] ?? 0);
    if ($mrp > (float) $it['unit_price']) {
        $savings += ($mrp - (float) $it['unit_price']) * (float) $it['qty'];
    }
}
if ($savings <= 0) {
    $savings = (float) ($sale['discount_total'] ?? 0);
}
$delivery = ($sale['order_type'] ?? 'takeaway') === 'delivery';
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<title>Receipt <?= esc($sale['server_invoice_no'] ?? '') ?></title>
<style>
  @media print { @page { size: 80mm auto; margin: 0; } body { margin: 0; } .noprint { display: none; } }
  body { font-family: 'Courier New', monospace; width: 80mm; margin: 0 auto; padding: 6px 8px; color: #000; font-size: 12px; }
  .c { text-align: center; } .r { text-align: right; } .b { font-weight: bold; } .sm { font-size: 11px; } .xs { font-size: 10px; }
  table { width: 100%; border-collapse: collapse; } td { padding: 1px 0; vertical-align: top; }
  hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
  .strike { text-decoration: line-through; color: #555; }
  .btn { padding: 6px 14px; margin: 4px; }
  .save { border: 1px dashed #000; padding: 3px; text-align: center; font-weight: bold; }
</style>
</head><body onload="window.print()">
  <div class="c b" style="font-size:15px"><?= esc($bill['shop_name'] ?? 'Store') ?></div>
  <?php if (! empty($bill['address'])): ?><div class="c xs"><?= esc($bill['address']) ?><?= ! empty($bill['pincode']) ? ' - ' . esc($bill['pincode']) : '' ?></div><?php endif; ?>
  <?php if (! empty($bill['phone'])): ?><div class="c xs">Ph: <?= esc($bill['phone']) ?></div><?php endif; ?>
  <?php if (! empty($bill['gstin'])): ?><div class="c xs">GSTIN: <?= esc($bill['gstin']) ?></div><?php endif; ?>
  <div class="c sm b" style="margin-top:2px"><?= esc($cfg['header_note'] ?? 'Tax Invoice') ?></div>
  <hr>
  <table class="sm">
    <tr><td>Invoice</td><td class="r b"><?= esc($sale['server_invoice_no'] ?? '') ?></td></tr>
    <tr><td>Date</td><td class="r"><?= esc(substr((string) ($sale['sold_at'] ?? ''), 0, 16)) ?></td></tr>
    <tr><td>Type</td><td class="r b"><?= $delivery ? 'DELIVERY' : 'TAKE AWAY' ?></td></tr>
    <?php if (! empty($sale['customer_name'])): ?><tr><td>Customer</td><td class="r"><?= esc($sale['customer_name']) ?></td></tr><?php endif; ?>
  </table>
  <hr>
  <table class="sm">
    <tr class="b"><td>Item</td><td class="r">MRP</td><td class="r">Rate</td><td class="r">Amt</td></tr>
    <?php foreach (($sale['items'] ?? []) as $it): $mrp = (float) ($it['mrp_snapshot'] ?? 0); ?>
      <tr><td colspan="4"><?= esc($it['product_title_snapshot']) ?> <span class="xs">(<?= esc($it['sku_snapshot']) ?>)</span></td></tr>
      <tr>
        <td class="xs"><?= esc(rtrim(rtrim((string) $it['qty'], '0'), '.')) ?> × <?= esc((float) $it['tax_rate'] > 0 ? 'GST ' . rtrim(rtrim((string) $it['tax_rate'], '0'), '.') . '%' : '') ?></td>
        <td class="r <?= $mrp > (float) $it['unit_price'] ? 'strike' : '' ?>"><?= $mrp > 0 ? number_format($mrp, 2) : '—' ?></td>
        <td class="r"><?= number_format((float) $it['unit_price'], 2) ?></td>
        <td class="r"><?= number_format((float) $it['line_total'], 2) ?></td>
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
    <?php if ((float) $sale['round_off'] != 0): ?><tr><td>Round off</td><td class="r"><?= $n($sale['round_off']) ?></td></tr><?php endif; ?>
  </table>
  <hr>
  <table><tr class="b" style="font-size:16px"><td>TOTAL</td><td class="r"><?= $n($sale['grand_total']) ?></td></tr></table>
  <hr>
  <table class="sm">
    <?php foreach (($sale['payments'] ?? []) as $p): ?><tr><td class="b" style="text-transform:capitalize"><?= esc($p['tender_type']) ?></td><td class="r"><?= $n($p['amount']) ?></td></tr><?php endforeach; ?>
  </table>
  <?php if (! empty($cfg['show_savings']) && $savings > 0): ?>
    <div class="save sm">You saved <?= $n($savings) ?> on this bill</div>
  <?php endif; ?>
  <hr>
  <?php if (! empty($cfg['terms'])): ?><div class="xs" style="white-space:pre-line"><?= esc($cfg['terms']) ?></div><hr><?php endif; ?>
  <div class="c sm b"><?= esc($cfg['footer_note'] ?? 'Visit again!') ?></div>
  <div class="c noprint" style="margin-top:10px"><button class="btn" onclick="window.print()">Print</button><a class="btn" href="<?= site_url('vendor/pos/receipt/' . ($sale['id'] ?? 0) . '/pdf') ?>" target="_blank">PDF</a><button class="btn" onclick="window.close()">Close</button></div>
</body></html>
