<?php
/**
 * documents/pos_credit_note_80mm — DMart-style 80mm POS credit note for dompdf.
 * Self-contained (no JS). Receives: $cn (return header + items) and $bill (shop
 * identity from ShopBillSettingsRepository::forShop()).
 */
$cfg = $bill['settings'] ?? \App\Models\ShopBillSettingsRepository::defaults();
$n   = static fn ($v): string => number_format((float) $v, 2);
$qty = static fn ($v): string => rtrim(rtrim((string) $v, '0'), '.');
$e   = static fn ($v): string => esc((string) ($v ?? ''));
$hasGst = (float) ($cn['cgst'] ?? 0) > 0 || (float) ($cn['sgst'] ?? 0) > 0 || (float) ($cn['igst'] ?? 0) > 0;
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><style>
  body { font-family: 'DejaVu Sans Mono', monospace; width: 76mm; margin: 0 auto; color: #000; font-size: 10px; }
  .c { text-align: center; } .r { text-align: right; } .b { font-weight: bold; }
  .xs { font-size: 8.5px; } .sm { font-size: 9.5px; } .lg { font-size: 13px; }
  table { width: 100%; border-collapse: collapse; } td { padding: 1px 0; vertical-align: top; }
  hr { border: none; border-top: 1px dashed #000; margin: 3px 0; }
</style></head><body>
  <div class="c b lg"><?= $e($bill['shop_name'] ?? ($cn['shop_name'] ?? 'Store')) ?></div>
  <?php if (! empty($bill['address'])): ?><div class="c xs"><?= $e($bill['address']) ?><?= ! empty($bill['pincode']) ? ' - ' . $e($bill['pincode']) : '' ?></div><?php endif; ?>
  <?php if (! empty($bill['phone'])): ?><div class="c xs">Ph: <?= $e($bill['phone']) ?></div><?php endif; ?>
  <?php if (! empty($bill['gstin'])): ?><div class="c xs">GSTIN: <?= $e($bill['gstin']) ?></div><?php endif; ?>
  <div class="c sm b" style="margin-top:2px">CREDIT NOTE</div>
  <hr>
  <table class="sm">
    <tr><td>CN No</td><td class="r b"><?= $e($cn['credit_note_no'] ?? '') ?></td></tr>
    <tr><td>Date</td><td class="r"><?= $e(substr((string) ($cn['created_at'] ?? ''), 0, 16)) ?></td></tr>
    <?php if (! empty($cn['against_invoice'])): ?><tr><td>Against Bill</td><td class="r b"><?= $e($cn['against_invoice']) ?></td></tr><?php endif; ?>
    <?php if (! empty($cn['customer_name'])): ?><tr><td>Customer</td><td class="r"><?= $e($cn['customer_name']) ?><?= ! empty($cn['customer_phone']) ? ' / ' . $e($cn['customer_phone']) : '' ?></td></tr><?php endif; ?>
    <?php if (! empty($cn['reason'])): ?><tr><td>Reason</td><td class="r"><?= $e($cn['reason']) ?></td></tr><?php endif; ?>
  </table>
  <hr>
  <table class="sm">
    <tr class="b"><td>Returned Item</td><td class="r">Qty</td><td class="r">Amount</td></tr>
    <?php foreach (($cn['items'] ?? []) as $it): ?>
      <tr>
        <td><?= $e($it['title']) ?> <span class="xs">(<?= $e($it['sku']) ?>)</span></td>
        <td class="r"><?= $qty($it['qty']) ?></td>
        <td class="r"><?= $n($it['amount']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($cn['items'])): ?><tr><td colspan="3" class="c xs">— walk-in / no itemised lines —</td></tr><?php endif; ?>
  </table>
  <hr>
  <table class="sm">
    <?php if ($hasGst): ?>
      <tr><td>Taxable</td><td class="r"><?= $n($cn['taxable_value'] ?? 0) ?></td></tr>
      <?php if ((float) ($cn['cgst'] ?? 0) > 0): ?><tr><td>CGST</td><td class="r"><?= $n($cn['cgst']) ?></td></tr><tr><td>SGST</td><td class="r"><?= $n($cn['sgst']) ?></td></tr><?php endif; ?>
      <?php if ((float) ($cn['igst'] ?? 0) > 0): ?><tr><td>IGST</td><td class="r"><?= $n($cn['igst']) ?></td></tr><?php endif; ?>
    <?php endif; ?>
  </table>
  <table><tr class="b lg"><td>REFUND</td><td class="r">&#8377;<?= $n($cn['refund_amount'] ?? 0) ?></td></tr></table>
  <table class="sm"><tr><td>Refund Mode</td><td class="r b" style="text-transform:uppercase"><?= $e($cn['refund_method'] ?? 'cash') ?></td></tr></table>
  <hr>
  <div class="c sm b"><?= $e($cfg['footer_note'] ?? 'Thank you') ?></div>
  <div class="c xs" style="margin-top:3px">This is a computer-generated credit note.</div>
</body></html>
