<?php
/**
 * 80mm POS Credit Note — a refund document with its own bill number. Receives
 * $cn (pos_returns row + items) and $bill (shop identity + settings).
 */
$n   = static fn ($v): string => '₹' . number_format((float) $v, 2);
$cfg = $bill['settings'] ?? \App\Models\ShopBillSettingsRepository::defaults();
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<title>Credit Note <?= esc($cn['credit_note_no'] ?? '') ?></title>
<style>
  @media print { @page { size: 80mm auto; margin: 0; } body { margin: 0; } .noprint { display: none; } }
  body { font-family: 'Courier New', monospace; width: 80mm; margin: 0 auto; padding: 6px 8px; color: #000; font-size: 12px; }
  .c { text-align: center; } .r { text-align: right; } .b { font-weight: bold; } .sm { font-size: 11px; } .xs { font-size: 10px; }
  table { width: 100%; border-collapse: collapse; } td { padding: 1px 0; vertical-align: top; }
  hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
  .tag { border: 1px solid #000; padding: 2px 6px; display: inline-block; font-weight: bold; }
  .btn { padding: 6px 14px; margin: 4px; }
</style>
</head><body onload="window.print()">
  <div class="c b" style="font-size:15px"><?= esc($bill['shop_name'] ?? 'Store') ?></div>
  <?php if (! empty($bill['address'])): ?><div class="c xs"><?= esc($bill['address']) ?><?= ! empty($bill['pincode']) ? ' - ' . esc($bill['pincode']) : '' ?></div><?php endif; ?>
  <?php if (! empty($bill['phone'])): ?><div class="c xs">Ph: <?= esc($bill['phone']) ?></div><?php endif; ?>
  <?php if (! empty($bill['gstin'])): ?><div class="c xs">GSTIN: <?= esc($bill['gstin']) ?></div><?php endif; ?>
  <div class="c" style="margin:3px 0"><span class="tag">CREDIT NOTE</span></div>
  <hr>
  <table class="sm">
    <tr><td>CN No.</td><td class="r b"><?= esc($cn['credit_note_no'] ?? '') ?></td></tr>
    <tr><td>Date</td><td class="r"><?= esc(substr((string) ($cn['created_at'] ?? ''), 0, 16)) ?></td></tr>
    <?php if (! empty($cn['against_invoice'])): ?><tr><td>Against bill</td><td class="r"><?= esc($cn['against_invoice']) ?></td></tr><?php endif; ?>
    <?php if (! empty($cn['customer_name'])): ?><tr><td>Customer</td><td class="r"><?= esc($cn['customer_name']) ?><?= ! empty($cn['customer_phone']) ? ' / ' . esc($cn['customer_phone']) : '' ?></td></tr><?php endif; ?>
    <?php if (! empty($cn['reason'])): ?><tr><td>Reason</td><td class="r"><?= esc($cn['reason']) ?></td></tr><?php endif; ?>
  </table>
  <hr>
  <table class="sm">
    <tr class="b"><td>Returned item</td><td class="r">Qty</td><td class="r">Credit</td></tr>
    <?php foreach (($cn['items'] ?? []) as $it): ?>
      <tr>
        <td><?= esc($it['title']) ?> <span class="xs">(<?= esc($it['sku']) ?>)</span></td>
        <td class="r"><?= esc(rtrim(rtrim((string) $it['qty'], '0'), '.')) ?></td>
        <td class="r"><?= number_format((float) $it['amount'], 2) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <hr>
  <table class="sm">
    <tr><td>Taxable</td><td class="r"><?= $n($cn['taxable_value'] ?? 0) ?></td></tr>
    <?php if ((float) ($cn['cgst'] ?? 0) > 0): ?><tr><td>CGST</td><td class="r"><?= $n($cn['cgst']) ?></td></tr><tr><td>SGST</td><td class="r"><?= $n($cn['sgst']) ?></td></tr><?php endif; ?>
    <?php if ((float) ($cn['igst'] ?? 0) > 0): ?><tr><td>IGST</td><td class="r"><?= $n($cn['igst']) ?></td></tr><?php endif; ?>
  </table>
  <hr>
  <table><tr class="b" style="font-size:16px"><td>REFUND</td><td class="r"><?= $n($cn['refund_amount'] ?? 0) ?></td></tr></table>
  <table class="sm"><tr><td>Mode</td><td class="r text-capitalize" style="text-transform:capitalize"><?= esc(str_replace('_', ' ', (string) ($cn['refund_method'] ?? 'cash'))) ?></td></tr></table>
  <hr>
  <div class="c sm b"><?= esc($cfg['footer_note'] ?? 'Visit again!') ?></div>
  <div class="c noprint" style="margin-top:10px"><button class="btn" onclick="window.print()">Print</button><a class="btn" href="<?= site_url('vendor/pos/credit-note/' . ($cn['id'] ?? 0) . '/pdf') ?>" target="_blank">PDF</a><button class="btn" onclick="window.close()">Close</button></div>
</body></html>
