<?php
/**
 * 80mm credit note. Standalone — deliberately does not extend the panel layout, exactly
 * as the sale receipt does not.
 *
 * Its own number series, printed prominently, and the original invoice named beneath it.
 * GST treats a credit note as a document in its own right; anyone reconciling needs to
 * see both numbers on the same slip.
 */
$money = static fn ($v): string => number_format((float) $v, 2);
$qty   = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 3), '0'), '.') ?: '0';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Credit note <?= esc((string) ($cn['credit_note_no'] ?? '')) ?></title>
<style>
  * { box-sizing: border-box; }
  body { width: 80mm; margin: 0 auto; padding: 6mm 4mm; font: 12px/1.45 "Courier New", monospace; color: #000; }
  h1 { font-size: 14px; margin: 0 0 2px; text-align: center; }
  .doc { text-align: center; font-weight: bold; letter-spacing: .08em; margin: 4px 0; }
  .muted { color: #444; }
  .c { text-align: center; }
  .r { text-align: right; }
  hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 1px 0; vertical-align: top; }
  .tot td { font-weight: bold; }
  @media print { .noprint { display: none; } body { width: auto; } }
</style>
</head>
<body onload="window.print()">

<h1><?= esc((string) ($cn['unit_name'] ?? 'Factory Outlet')) ?></h1>
<?php if (! empty($cn['unit_gstin'])): ?>
    <div class="c muted">GSTIN <?= esc($cn['unit_gstin']) ?></div>
<?php endif; ?>

<div class="doc">CREDIT NOTE</div>
<hr>

<div>
    No. <strong><?= esc((string) ($cn['credit_note_no'] ?? '')) ?></strong><br>
    <span class="muted"><?= esc(substr((string) ($cn['created_at'] ?? ''), 0, 16)) ?></span><br>
    <span class="muted">Against invoice <?= esc((string) ($cn['invoice_no'] ?? '—')) ?></span>
    <?php if (! empty($cn['reason'])): ?><br><span class="muted">Reason: <?= esc($cn['reason']) ?></span><?php endif; ?>
</div>
<hr>

<table>
    <?php foreach (($cn['items'] ?? []) as $it): ?>
        <tr><td colspan="2"><?= esc((string) ($it['product_title_snapshot'] ?? '')) ?></td></tr>
        <tr>
            <td class="muted">
                <?= esc($qty($it['qty'] ?? 0)) ?> × <?= esc($money($it['unit_price'] ?? 0)) ?>
                <?php if (! empty($it['sku_snapshot'])): ?> · <?= esc($it['sku_snapshot']) ?><?php endif; ?>
            </td>
            <td class="r"><?= esc($money($it['line_total'] ?? 0)) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<hr>
<table>
    <tr><td>Taxable</td><td class="r"><?= esc($money($cn['taxable_value'] ?? 0)) ?></td></tr>
    <?php if ((float) ($cn['cgst'] ?? 0) > 0 || (float) ($cn['sgst'] ?? 0) > 0): ?>
        <tr class="muted">
            <td>GST</td>
            <td class="r"><?= esc($money((float) ($cn['cgst'] ?? 0) + (float) ($cn['sgst'] ?? 0))) ?></td>
        </tr>
    <?php endif; ?>
    <tr class="tot"><td>REFUNDED</td><td class="r">₹<?= esc($money($cn['total'] ?? 0)) ?></td></tr>
    <tr class="muted"><td>By</td><td class="r"><?= esc(ucfirst((string) ($cn['refund_method'] ?? 'cash'))) ?></td></tr>
</table>

<hr>
<div class="c muted">Goods received back into stock</div>

<div class="c noprint" style="margin-top:10px">
    <button type="button" onclick="window.print()">Print</button>
</div>

</body>
</html>
