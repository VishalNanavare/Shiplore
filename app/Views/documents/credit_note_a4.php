<?php
/**
 * documents/credit_note_a4 — GST credit note (X2c). dompdf-safe.
 * Receives: credit_note (header incl. shop + against-invoice ref), lines[], buyer.
 */
$cn   = $credit_note;
$addr = json_decode((string) ($cn['shop_address_json'] ?? '{}'), true) ?: [];
$n    = static fn ($v): string => number_format((float) $v, 2);
$e    = static fn ($v): string => esc((string) ($v ?? ''));
$igst = (float) $cn['igst_total'] > 0;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; margin: 24px; }
  h1 { font-size: 15px; margin: 0 0 2px; } h2 { font-size: 11px; margin: 0; font-weight: normal; color: #444; }
  table { width: 100%; border-collapse: collapse; }
  .meta td { padding: 2px 6px; }
  .box { border: 1px solid #999; padding: 6px 8px; }
  .lines th, .lines td { border: 1px solid #999; padding: 4px 6px; }
  .lines th { background: #f0f0f0; font-size: 9px; text-transform: uppercase; }
  .r { text-align: right; } .c { text-align: center; }
  .grand { font-weight: bold; font-size: 12px; border-top: 2px solid #111; }
  .muted { color: #666; font-size: 9px; }
</style>
</head>
<body>

<table>
  <tr>
    <td style="width:60%">
      <h1><?= $e($cn['shop_name'] ?: $cn['vendor_name']) ?></h1>
      <h2>
        <?= $e($addr['line1'] ?? '') ?> <?= $e($addr['line2'] ?? '') ?>
        <?= $e($addr['city'] ?? '') ?> <?= $e($cn['shop_state']) ?> – <?= $e($cn['shop_pincode']) ?>
      </h2>
      <?php if (! empty($cn['shop_gstin'])): ?><strong>GSTIN: <?= $e($cn['shop_gstin']) ?></strong><?php endif ?>
    </td>
    <td style="width:40%" class="r">
      <h1>CREDIT NOTE</h1>
      <table class="meta" style="width:auto; margin-left:auto;">
        <tr><td class="muted">CN No.</td><td><strong><?= $e($cn['credit_note_no']) ?></strong></td></tr>
        <tr><td class="muted">Date</td><td><?= $e($cn['cn_date']) ?></td></tr>
        <?php if (! empty($cn['against_invoice_no'])): ?>
          <tr><td class="muted">Against Invoice</td><td><?= $e($cn['against_invoice_no']) ?> (<?= $e($cn['against_invoice_date']) ?>)</td></tr>
        <?php endif ?>
        <?php if (! empty($cn['order_no'])): ?>
          <tr><td class="muted">Order</td><td><?= $e($cn['order_no']) ?></td></tr>
        <?php endif ?>
      </table>
    </td>
  </tr>
</table>

<?php if ($buyer !== null): ?>
<br>
<div class="box">
  <span class="muted">ISSUED TO</span><br>
  <strong><?= $e($buyer['name'] ?? 'Customer') ?></strong><br>
  <?= $e($buyer['line1'] ?? '') ?>, <?= $e($buyer['city'] ?? '') ?> <?= $e($buyer['state_code'] ?? '') ?> – <?= $e($buyer['pincode'] ?? '') ?>
</div>
<?php endif ?>

<br>
<table class="lines">
  <thead>
    <tr>
      <th class="c" style="width:4%">#</th>
      <th>Description</th>
      <th class="r" style="width:7%">Qty</th>
      <th class="r" style="width:12%">Taxable</th>
      <th class="c" style="width:7%">GST %</th>
      <?php if ($igst): ?>
        <th class="r" style="width:11%">IGST</th>
      <?php else: ?>
        <th class="r" style="width:10%">CGST</th>
        <th class="r" style="width:10%">SGST</th>
      <?php endif ?>
      <th class="r" style="width:13%">Total</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($lines as $i => $l): ?>
    <tr>
      <td class="c"><?= $i + 1 ?></td>
      <td><?= $e($l['description']) ?></td>
      <td class="r"><?= rtrim(rtrim((string) $l['qty'], '0'), '.') ?></td>
      <td class="r"><?= $n($l['taxable_value']) ?></td>
      <td class="c"><?= $n($l['tax_rate']) ?></td>
      <?php if ($igst): ?>
        <td class="r"><?= $n($l['igst']) ?></td>
      <?php else: ?>
        <td class="r"><?= $n($l['cgst']) ?></td>
        <td class="r"><?= $n($l['sgst']) ?></td>
      <?php endif ?>
      <td class="r"><?= $n($l['line_total']) ?></td>
    </tr>
    <?php endforeach ?>
  </tbody>
</table>

<br>
<table>
  <tr>
    <td style="width:55%" class="muted">
      Credit issued against returned goods / price adjustment as per the
      referenced invoice. GST adjusted in the corresponding GSTR-1 period.
    </td>
    <td style="width:45%">
      <table class="meta">
        <tr><td class="muted">Taxable Total</td><td class="r"><?= $n($cn['taxable_total']) ?></td></tr>
        <?php if ($igst): ?>
          <tr><td class="muted">IGST</td><td class="r"><?= $n($cn['igst_total']) ?></td></tr>
        <?php else: ?>
          <tr><td class="muted">CGST</td><td class="r"><?= $n($cn['cgst_total']) ?></td></tr>
          <tr><td class="muted">SGST</td><td class="r"><?= $n($cn['sgst_total']) ?></td></tr>
        <?php endif ?>
        <tr class="grand"><td>Credit Total ₹</td><td class="r"><?= $n($cn['grand_total']) ?></td></tr>
      </table>
    </td>
  </tr>
</table>

<br><br>
<table>
  <tr>
    <td class="muted">This is a computer-generated document.</td>
    <td class="r">For <strong><?= $e($cn['shop_name'] ?: $cn['vendor_name']) ?></strong><br><br><br>Authorised Signatory</td>
  </tr>
</table>

</body>
</html>
