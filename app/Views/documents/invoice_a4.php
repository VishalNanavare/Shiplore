<?php
/**
 * documents/invoice_a4 — professional GST tax invoice / bill of supply (X2c).
 * Self-contained for dompdf: tables + inline CSS only, no remote assets.
 * Receives: invoice (header incl. shop/vendor), lines[], buyer, billing, shipping.
 */
$inv   = $invoice;
$ship  = $shipping ?? $buyer ?? [];
$billA = $billing ?? $buyer ?? [];
$addr  = json_decode((string) ($inv['shop_address_json'] ?? '{}'), true) ?: [];
$title = ($inv['doc_type'] ?? '') === 'bill_of_supply' ? 'Bill of Supply' : 'Tax Invoice';
$n     = static fn ($v): string => number_format((float) $v, 2);
$e     = static fn ($v): string => esc((string) ($v ?? ''));
$qty   = static fn ($v): string => rtrim(rtrim((string) $v, '0'), '.');
$igst  = (float) ($inv['igst_total'] ?? 0) > 0;

$inWords = static function (float $amount): string {
    $amount = round($amount, 2);
    $rupees = (int) floor($amount);
    $paise  = (int) round(($amount - $rupees) * 100);
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two  = static fn (int $x): string => $x < 20 ? $ones[$x] : trim($tens[intdiv($x, 10)] . ' ' . $ones[$x % 10]);
    $three = static function (int $x) use ($ones, $two): string {
        $h = intdiv($x, 100);
        $r = $x % 100;
        return trim(($h ? $ones[$h] . ' Hundred' : '') . ($r ? ' ' . $two($r) : ''));
    };
    $w = '';
    $cr = intdiv($rupees, 10000000); $rupees %= 10000000;
    $la = intdiv($rupees, 100000);   $rupees %= 100000;
    $th = intdiv($rupees, 1000);     $rupees %= 1000;
    if ($cr) { $w .= $two($cr) . ' Crore '; }
    if ($la) { $w .= $two($la) . ' Lakh '; }
    if ($th) { $w .= $two($th) . ' Thousand '; }
    if ($rupees) { $w .= $three($rupees); }
    $out = 'Rupees ' . (trim($w) ?: 'Zero');
    if ($paise) { $out .= ' and ' . $two($paise) . ' Paise'; }

    return $out . ' Only';
};
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  * { box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2733; margin: 22px; }
  table { width: 100%; border-collapse: collapse; }
  .brandbar td { padding: 0 0 10px; vertical-align: middle; }
  .brand { font-size: 19px; font-weight: bold; color: #0d4f8b; letter-spacing: .3px; }
  .brand span { color: #1f2733; }
  .doctitle { font-size: 17px; font-weight: bold; text-transform: uppercase; color: #1f2733; }
  .doctag { font-size: 8.5px; color: #6b7785; }
  .rule { height: 3px; background: #0d4f8b; }
  .party td { vertical-align: top; padding: 8px 0; }
  .lbl { font-size: 8px; letter-spacing: .6px; text-transform: uppercase; color: #6b7785; margin-bottom: 2px; }
  .box { border: 1px solid #d6dbe2; border-radius: 4px; padding: 8px 10px; }
  .meta td { padding: 1px 0; }
  .meta .k { color: #6b7785; padding-right: 10px; }
  .lines th { background: #0d4f8b; color: #fff; font-size: 8.5px; text-transform: uppercase; padding: 6px 6px; text-align: left; }
  .lines td { border-bottom: 1px solid #e4e8ee; padding: 6px 6px; }
  .lines tr:nth-child(even) td { background: #f7f9fc; }
  .r { text-align: right; } .c { text-align: center; }
  .tot td { padding: 3px 8px; }
  .tot .k { color: #5b6573; }
  .grand td { font-weight: bold; font-size: 12px; color: #0d4f8b; border-top: 2px solid #0d4f8b; padding-top: 6px; }
  .muted { color: #6b7785; font-size: 8.5px; }
  .words { border: 1px solid #d6dbe2; border-radius: 4px; padding: 7px 9px; background: #f7f9fc; }
  .foot { margin-top: 16px; border-top: 1px solid #e4e8ee; padding-top: 8px; }
</style>
</head>
<body>

<table class="brandbar">
  <tr>
    <td style="width:55%"><div class="brand"><?= $e($brandName ?? 'Shiplore') ?></div></td>
    <td style="width:45%" class="r"><div class="doctitle"><?= $title ?></div><div class="doctag">Original for Recipient</div></td>
  </tr>
</table>
<div class="rule"></div>

<table class="party">
  <tr>
    <td style="width:50%; padding-right:12px">
      <div class="lbl">Sold By</div>
      <div class="box">
        <strong><?= $e($inv['shop_name'] ?: $inv['vendor_name']) ?></strong><br>
        <?= $e(trim(($addr['line1'] ?? '') . ' ' . ($addr['line2'] ?? ''))) ?><br>
        <?= $e($addr['city'] ?? '') ?> <?= $e($inv['shop_state'] ?? '') ?> – <?= $e($inv['shop_pincode'] ?? '') ?><br>
        <?php if (! empty($inv['supplier_gstin'])): ?><strong>GSTIN:</strong> <?= $e($inv['supplier_gstin']) ?><?php endif ?>
      </div>
    </td>
    <td style="width:50%">
      <table class="meta">
        <tr><td class="k">Invoice No.</td><td><strong><?= $e($inv['invoice_no']) ?></strong></td></tr>
        <tr><td class="k">Invoice Date</td><td><?= $e($inv['invoice_date']) ?></td></tr>
        <tr><td class="k">Order No.</td><td><?= $e($inv['order_no']) ?> · <?= $e($inv['sub_order_no']) ?></td></tr>
        <tr><td class="k">Place of Supply</td><td><?= $e($inv['place_of_supply']) ?></td></tr>
        <?php if (! empty($inv['irn'])): ?><tr><td class="k">IRN</td><td><?= $e($inv['irn']) ?></td></tr><?php endif ?>
      </table>
    </td>
  </tr>
</table>

<table class="party">
  <tr>
    <td style="width:50%; padding-right:12px">
      <div class="lbl">Billing Address</div>
      <div class="box">
        <strong><?= $e($billA['name'] ?? 'Customer') ?></strong><?php if (! empty($billA['phone'])): ?> · <?= $e($billA['phone']) ?><?php endif ?><br>
        <?= $e(trim(($billA['line1'] ?? '') . ' ' . ($billA['line2'] ?? ''))) ?><br>
        <?= $e($billA['city'] ?? '') ?> <?= $e($billA['state_code'] ?? '') ?> – <?= $e($billA['pincode'] ?? '') ?>
      </div>
    </td>
    <td style="width:50%">
      <div class="lbl">Shipping Address</div>
      <div class="box">
        <strong><?= $e($ship['name'] ?? ($billA['name'] ?? 'Customer')) ?></strong><br>
        <?= $e(trim(($ship['line1'] ?? '') . ' ' . ($ship['line2'] ?? ''))) ?><br>
        <?= $e($ship['city'] ?? '') ?> <?= $e($ship['state_code'] ?? '') ?> – <?= $e($ship['pincode'] ?? '') ?>
      </div>
    </td>
  </tr>
</table>

<br>
<table class="lines">
  <thead>
    <tr>
      <th class="c" style="width:4%">#</th>
      <th>Description</th>
      <th class="c" style="width:8%">HSN</th>
      <th class="r" style="width:6%">Qty</th>
      <th class="r" style="width:10%">Unit Price</th>
      <th class="r" style="width:11%">Taxable</th>
      <th class="c" style="width:6%">GST%</th>
      <?php if ($igst): ?>
        <th class="r" style="width:11%">IGST</th>
      <?php else: ?>
        <th class="r" style="width:9%">CGST</th>
        <th class="r" style="width:9%">SGST</th>
      <?php endif ?>
      <th class="r" style="width:12%">Total</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($lines as $i => $l):
        $lt = (float) ($l['line_total'] ?? 0);
        if ($lt <= 0) { $lt = (float) $l['taxable_value'] + (float) $l['cgst'] + (float) $l['sgst'] + (float) $l['igst'] + (float) ($l['cess'] ?? 0); }
    ?>
    <tr>
      <td class="c"><?= $i + 1 ?></td>
      <td><?= $e($l['description']) ?></td>
      <td class="c"><?= $e($l['hsn']) ?></td>
      <td class="r"><?= $qty($l['qty']) ?></td>
      <td class="r"><?= $n($l['unit_price']) ?></td>
      <td class="r"><?= $n($l['taxable_value']) ?></td>
      <td class="c"><?= $n($l['tax_rate']) ?></td>
      <?php if ($igst): ?>
        <td class="r"><?= $n($l['igst']) ?></td>
      <?php else: ?>
        <td class="r"><?= $n($l['cgst']) ?></td>
        <td class="r"><?= $n($l['sgst']) ?></td>
      <?php endif ?>
      <td class="r"><?= $n($lt) ?></td>
    </tr>
    <?php endforeach ?>
  </tbody>
</table>

<br>
<table>
  <tr>
    <td style="width:56%; vertical-align:top; padding-right:12px">
      <div class="words"><span class="lbl">Amount in Words</span><br><strong><?= $e($inWords((float) $inv['grand_total'])) ?></strong></div>
      <p class="muted" style="margin-top:8px">Whether tax is payable under reverse charge: <strong>No</strong>.<br>
      Declaration: we declare that this invoice shows the actual price of the goods described and that all particulars are true and correct.</p>
    </td>
    <td style="width:44%; vertical-align:top">
      <table class="tot">
        <tr><td class="k">Taxable Value</td><td class="r"><?= $n($inv['taxable_total']) ?></td></tr>
        <?php if ($igst): ?>
          <tr><td class="k">IGST</td><td class="r"><?= $n($inv['igst_total']) ?></td></tr>
        <?php else: ?>
          <tr><td class="k">CGST</td><td class="r"><?= $n($inv['cgst_total']) ?></td></tr>
          <tr><td class="k">SGST</td><td class="r"><?= $n($inv['sgst_total']) ?></td></tr>
        <?php endif ?>
        <?php if ((float) ($inv['cess_total'] ?? 0) > 0): ?><tr><td class="k">Cess</td><td class="r"><?= $n($inv['cess_total']) ?></td></tr><?php endif ?>
        <?php if ((float) ($inv['round_off'] ?? 0) !== 0.0): ?><tr><td class="k">Round Off</td><td class="r"><?= $n($inv['round_off']) ?></td></tr><?php endif ?>
        <tr class="grand"><td>Grand Total</td><td class="r">₹<?= $n($inv['grand_total']) ?></td></tr>
      </table>
    </td>
  </tr>
</table>

<table class="foot">
  <tr>
    <td class="muted" style="vertical-align:bottom">This is a computer-generated invoice and does not require a physical signature.</td>
    <td class="r" style="width:40%">For <strong><?= $e($inv['shop_name'] ?: $inv['vendor_name']) ?></strong><br><br><br><span class="muted">Authorised Signatory</span></td>
  </tr>
</table>

</body>
</html>
