<?php
/**
 * 80mm thermal receipt. Standalone — deliberately does NOT extend the panel layout,
 * exactly as the vendor receipt does not.
 *
 * NO "you saved" line. The vendor receipt computes it from mrp_snapshot, and mrp is
 * 0/unused for manufacturer products, so it would print the entire sale value as a
 * discount. Bill settings likewise come from static defaults rather than
 * ShopBillSettingsRepository::forShop(), which is keyed on shops.id and would return
 * another tenant's header for an mshop id.
 */
$addr  = (array) json_decode((string) ($sale['address_json'] ?? '{}'), true);
$money = static fn ($v): string => number_format((float) $v, 2);
$qty   = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 3), '0'), '.') ?: '0';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt <?= esc((string) ($sale['invoice_no'] ?? '')) ?></title>
<style>
  * { box-sizing: border-box; }
  body { width: 80mm; margin: 0 auto; padding: 6mm 4mm; font: 12px/1.45 "Courier New", monospace; color: #000; }
  h1 { font-size: 14px; margin: 0 0 2px; text-align: center; }
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

<h1><?= esc((string) ($sale['unit_name'] ?? 'Factory Outlet')) ?></h1>
<div class="c muted">
    <?php $line = trim(implode(', ', array_filter([$addr['line1'] ?? '', $addr['city'] ?? '']))); ?>
    <?php if ($line !== ''): ?><?= esc($line) ?><br><?php endif; ?>
    <?php if (! empty($sale['unit_gstin'])): ?>GSTIN <?= esc($sale['unit_gstin']) ?><?php endif; ?>
</div>

<hr>
<div>
    Invoice <strong><?= esc((string) ($sale['invoice_no'] ?? '')) ?></strong><br>
    <span class="muted"><?= esc(substr((string) ($sale['sold_at'] ?? ''), 0, 16)) ?></span>
    <?php if (! empty($sale['cashier_name'])): ?><br><span class="muted">Cashier: <?= esc($sale['cashier_name']) ?></span><?php endif; ?>
    <?php if (! empty($sale['customer_name'])): ?><br>Customer: <?= esc($sale['customer_name']) ?><?php endif; ?>
</div>
<hr>

<table>
    <?php foreach (($sale['items'] ?? []) as $it): ?>
        <tr>
            <td colspan="2"><?= esc((string) ($it['product_title_snapshot'] ?? '')) ?></td>
        </tr>
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
    <tr><td>Taxable</td><td class="r"><?= esc($money($sale['taxable_value'] ?? 0)) ?></td></tr>
    <?php foreach (($sale['tax_buckets'] ?? []) as $b): ?>
        <?php if ((float) $b['cgst'] <= 0 && (float) $b['sgst'] <= 0) { continue; } ?>
        <tr class="muted">
            <td>GST <?= esc(number_format((float) $b['rate'], 2)) ?>%</td>
            <td class="r"><?= esc($money((float) $b['cgst'] + (float) $b['sgst'])) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (abs((float) ($sale['discount_total'] ?? 0)) > 0.001): ?>
        <tr><td>Discount</td><td class="r">-<?= esc($money($sale['discount_total'])) ?></td></tr>
    <?php endif; ?>
    <?php if (abs((float) ($sale['round_off'] ?? 0)) > 0.001): ?>
        <tr class="muted"><td>Rounding</td><td class="r"><?= esc($money($sale['round_off'])) ?></td></tr>
    <?php endif; ?>
    <tr class="tot"><td>TOTAL</td><td class="r">₹<?= esc($money($sale['grand_total'] ?? 0)) ?></td></tr>
</table>

<?php if (! empty($sale['payments'])): ?>
    <hr>
    <table>
        <?php foreach ($sale['payments'] as $p): ?>
            <tr class="muted">
                <td><?= esc(ucfirst((string) ($p['tender_type'] ?? ''))) ?></td>
                <td class="r"><?= esc($money($p['amount'] ?? 0)) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<hr>
<div class="c muted">Thank you</div>

<div class="c noprint" style="margin-top:10px">
    <button type="button" onclick="window.print()">Print</button>
</div>

</body>
</html>
