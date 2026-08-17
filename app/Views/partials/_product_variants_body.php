<?php
/**
 * Shared variant-builder + variant-table (Admin + Vendor). Expects:
 * $product, $attributes (defining attrs+values), $variants (listWithValues),
 * $genUrl, $variantUpdateBase, $variantDeleteBase, $bulkUrl, $barcodeBase,
 * $barcodesByVariant, $backUrl.
 *
 * The builder is step-guided: tick the attributes that vary, then pick their
 * values via a searchable (AJAX) Select2 so an attribute with 1000+ values is
 * typeable, not a wall of checkboxes. Both the grid generator and the
 * "add one manually" form post the same sel[attrId][] to $genUrl; omitting an
 * attribute simply leaves it out of that combination (irregular variants).
 */
$lookupBase = str_contains((string) $genUrl, '/manufacturer/')
    ? site_url('manufacturer/lookup/')
    : (str_contains((string) $genUrl, '/vendor/') ? site_url('vendor/lookup/') : site_url('admin/lookup/'));
// Sibling pages linked top-right. Hardcoding them broke the manufacturer panel: it
// has no /pricing route at all, and its stock page is /stock rather than /inventory,
// so both buttons 404'd and the prose below advertised pages that did not exist.
// Each entry is [label, bootstrap icon, url]. Empty => no button group, no prose.
$siblingLinks = $siblingLinks ?? [];

// The two price columns. Vendors and admins price against MRP; a manufacturer prices
// against its own making (production) cost, and leaves mrp unused at 0. Only the field
// name and the label differ, so they are parameterised rather than the whole grid
// duplicated. Defaults keep vendor/admin output byte-identical.
$priceA = $priceA ?? ['mrp', 'MRP'];
$priceB = $priceB ?? ['base_price', 'Selling price'];
?>
<link rel="stylesheet" href="<?= asset('plugins/select2/select2.min.css') ?>">
<link rel="stylesheet" href="<?= asset('plugins/select2/select2-bootstrap-5-theme.min.css') ?>">

<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h5 mb-0">Variants — <?= esc($product['title']) ?></h1>
    <div class="d-flex gap-2">
        <?php if ($siblingLinks !== []): ?>
            <div class="btn-group btn-group-sm">
                <?php foreach ($siblingLinks as [$sibLabel, $sibIcon, $sibUrl]): ?>
                    <a href="<?= esc($sibUrl, 'attr') ?>" class="btn btn-outline-secondary"><i class="bi <?= esc($sibIcon, 'attr') ?> me-1"></i><?= esc($sibLabel) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <a href="<?= $backUrl ?>" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to products</a>
    </div>
</div>
<div class="alert alert-light border small text-secondary py-2 mb-3"><i class="bi bi-info-circle me-1"></i>Set price &amp; stock per item in the grid below.<?php if ($siblingLinks !== []): ?> The <?= esc(implode(' and ', array_column($siblingLinks, 0))) ?> page<?= count($siblingLinks) > 1 ? 's' : '' ?> (top-right) offer the same fields in a focused view — either works.<?php endif; ?></div>

<?php if (empty($attributes)): ?>
    <div class="card mb-3"><div class="card-body text-secondary">
        This product's category has no variant-defining attributes. Add variant-defining attributes (e.g. Size, Color) under <strong>Masters → Attributes</strong> and map them to the category, then come back here.
    </div></div>
<?php else: ?>
<div class="card mb-3" data-attrvalues-base="<?= esc($lookupBase, 'attr') ?>"><div class="card-body">
    <h2 class="h6 mb-3">Build variants</h2>
    <form method="post" action="<?= $genUrl ?>" id="genForm">
        <?= csrf_field() ?>

        <div class="mb-3">
            <div class="fw-semibold mb-2"><span class="badge text-bg-primary me-1">1</span>Which attributes make this product vary?</div>
            <div class="d-flex gap-3 flex-wrap">
                <?php foreach ($attributes as $a): ?>
                    <div class="form-check">
                        <input class="form-check-input js-attr-toggle" type="checkbox" id="at<?= (int) $a['id'] ?>" data-attr="<?= (int) $a['id'] ?>">
                        <label class="form-check-label fw-medium" for="at<?= (int) $a['id'] ?>"><?= esc($a['name']) ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mb-3">
            <div class="fw-semibold mb-2"><span class="badge text-bg-primary me-1">2</span>Pick the values for each</div>
            <?php foreach ($attributes as $a): ?>
                <div class="row g-2 align-items-center mb-2 js-attr-row d-none" data-attr="<?= (int) $a['id'] ?>">
                    <div class="col-md-3"><label class="form-label mb-0"><?= esc($a['name']) ?></label></div>
                    <div class="col-md-9"><select class="form-select js-attr-values" name="sel[<?= (int) $a['id'] ?>][]" data-attr="<?= (int) $a['id'] ?>" multiple></select></div>
                </div>
            <?php endforeach; ?>
            <div class="text-secondary small js-no-attr">Tick an attribute above to choose its values.</div>
        </div>

        <div class="row g-3 align-items-end">
            <div class="col-md-3"><label class="form-label"><span class="badge text-bg-primary me-1">3</span>SKU prefix</label><input name="sku_prefix" class="form-control" value="<?= esc(($variants[0]['sku'] ?? 'SKU'), 'attr') ?>" placeholder="e.g. SHOE"></div>
            <div class="col-md-3"><label class="form-label"><?= esc($priceA[1]) ?> (₹)</label><input name="<?= esc($priceA[0], 'attr') ?>" type="number" step="0.01" class="form-control" value="<?= esc(($product[$priceA[0]] ?? ''), 'attr') ?>"></div>
            <div class="col-md-3"><label class="form-label"><?= esc($priceB[1]) ?> (₹)</label><input name="<?= esc($priceB[0], 'attr') ?>" type="number" step="0.01" class="form-control" value="<?= esc(($product[$priceB[0]] ?? ''), 'attr') ?>"></div>
            <div class="col-md-3"><button class="btn btn-primary w-100" id="genBtn" disabled><i class="bi bi-grid-3x3-gap me-1"></i>Generate <span id="comboCount"></span></button></div>
        </div>
        <div class="form-text mt-1">Existing combinations are skipped; nothing is overwritten.</div>
    </form>
</div></div>

<div class="card mb-3"><div class="card-body">
    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#manualAdd"><i class="bi bi-plus-lg me-1"></i>Add one variant manually</button>
    <div class="collapse mt-3" id="manualAdd">
        <p class="text-secondary small mb-2">For an odd combination that doesn't fit the grid (e.g. only some sizes come in a fabric). Leave an attribute blank to omit it from this one variant.</p>
        <form method="post" action="<?= $genUrl ?>" class="row g-2 align-items-end" id="manualForm">
            <?= csrf_field() ?>
            <?php foreach ($attributes as $a): ?>
                <div class="col-md-3"><label class="form-label"><?= esc($a['name']) ?></label>
                    <select class="form-select js-attr-values-single" name="sel[<?= (int) $a['id'] ?>][]" data-attr="<?= (int) $a['id'] ?>"><option value="">—</option></select>
                </div>
            <?php endforeach; ?>
            <div class="col-md-2"><label class="form-label"><?= esc($priceA[1]) ?></label><input name="<?= esc($priceA[0], 'attr') ?>" type="number" step="0.01" class="form-control" value="<?= esc(($product[$priceA[0]] ?? ''), 'attr') ?>"></div>
            <div class="col-md-2"><label class="form-label"><?= esc($priceB[1]) ?></label><input name="<?= esc($priceB[0], 'attr') ?>" type="number" step="0.01" class="form-control" value="<?= esc(($product[$priceB[0]] ?? ''), 'attr') ?>"></div>
            <input type="hidden" name="sku_prefix" value="<?= esc(($variants[0]['sku'] ?? 'SKU'), 'attr') ?>">
            <div class="col-md-2"><button class="btn btn-primary w-100">Add variant</button></div>
        </form>
    </div>
</div></div>
<?php endif; ?>

<?php if (! empty($variants)): ?>
<form id="bulkForm" method="post" action="<?= $bulkUrl ?>" class="d-flex gap-2 align-items-center flex-wrap mb-2">
    <?= csrf_field() ?>
    <span class="text-secondary small fw-semibold"><i class="bi bi-lightning-charge me-1"></i>Bulk edit selected:</span>
    <select name="field" class="form-select form-select-sm w-auto"><option value="<?= esc($priceB[0], 'attr') ?>"><?= esc($priceB[1]) ?></option><option value="<?= esc($priceA[0], 'attr') ?>"><?= esc($priceA[1]) ?></option><option value="cost_price">Cost price</option><option value="status">Status</option></select>
    <input name="value" class="form-control form-control-sm w-auto" placeholder="value" style="max-width:140px">
    <button class="btn btn-sm btn-primary" id="bulkApply" disabled>Apply to <span id="bulkCount">0</span></button>
</form>
<?php endif; ?>

<?php $showStock = (($inventoryMode ?? 'managed') === 'managed'); ?>
<style>
.vgrid{display:grid;grid-template-columns:<?= $showStock ? '26px minmax(150px,1.7fr) minmax(140px,1.5fr) 82px 82px 82px 86px 108px 40px' : '26px minmax(150px,1.7fr) minmax(150px,1.7fr) 92px 92px 92px 120px 40px' ?>;gap:.5rem;align-items:center;padding:.55rem .75rem;border-top:1px solid #eef0f4}
.vgrid.vhead{font-size:.7rem;text-transform:uppercase;letter-spacing:.03em;font-weight:700;color:#7a8190;background:#f8f9fb;border-top:0}
.vgrid form.vform{display:contents}
.vgrid .vattr{font-size:.85rem;line-height:1.2}
.vscroll{overflow-x:auto}.vwrap{min-width:<?= $showStock ? '820px' : '760px' ?>}
</style>
<div class="card">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span>Variants (<?= count($variants) ?>)</span>
        <?php if ($showStock): ?><span class="badge text-bg-light fw-normal"><i class="bi bi-box-seam me-1"></i>Stock = on-hand in this product's shop</span><?php endif; ?>
    </div>
    <div class="vscroll"><div class="vwrap">
        <div class="vgrid vhead">
            <div><input type="checkbox" id="bcAll" class="form-check-input"></div>
            <div>Variant</div><div>SKU</div><div><?= esc($priceA[1]) ?></div><div><?= esc($priceB[1]) ?></div><div>Cost</div>
            <?php if ($showStock): ?><div>Stock</div><?php endif; ?>
            <div>Status</div><div></div>
        </div>
        <?php foreach ($variants as $v): $vid = (int) $v['id']; $vbc = $barcodesByVariant[$vid] ?? []; ?>
        <div class="vgrid">
            <div><input type="checkbox" name="ids[]" value="<?= $vid ?>" form="bulkForm" class="form-check-input js-vsel"></div>
            <div class="vattr">
                <div class="fw-medium"><?= $v['attributes'] !== '' ? esc($v['attributes']) : '<span class="text-secondary">Base item</span>' ?><?php if ($v['is_default']): ?> <span class="badge text-bg-secondary">default</span><?php endif; ?></div>
                <div class="d-flex gap-2 mt-1">
                    <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" data-bs-toggle="collapse" data-bs-target="#bc-<?= $vid ?>" title="Barcodes"><i class="bi bi-upc-scan me-1"></i><?= count($vbc) ?></button>
                    <?php if (! empty($variantDeleteBase) && empty($v['is_default'])): ?>
                        <form method="post" action="<?= $variantDeleteBase . $vid ?>/delete" class="d-inline" onsubmit="return confirm('Delete this variant? This cannot be undone.')"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0" title="Delete variant"><i class="bi bi-trash"></i></button></form>
                    <?php endif; ?>
                </div>
            </div>
            <form method="post" action="<?= $variantUpdateBase . $vid ?>/update" class="vform">
                <?= csrf_field() ?>
                <input name="sku" class="form-control form-control-sm" value="<?= esc($v['sku'], 'attr') ?>" title="SKU">
                <input name="<?= esc($priceA[0], 'attr') ?>" type="number" step="0.01" min="0" class="form-control form-control-sm" value="<?= esc($v[$priceA[0]] ?? '', 'attr') ?>" title="<?= esc($priceA[1], 'attr') ?>">
                <input name="<?= esc($priceB[0], 'attr') ?>" type="number" step="0.01" min="0" class="form-control form-control-sm" value="<?= esc($v[$priceB[0]] ?? '', 'attr') ?>" title="<?= esc($priceB[1], 'attr') ?>">
                <input name="cost_price" type="number" step="0.01" min="0" class="form-control form-control-sm" value="<?= esc($v['cost_price'] ?? '', 'attr') ?>" placeholder="—" title="Cost price">
                <?php if ($showStock): ?><input name="stock" type="number" step="1" min="0" class="form-control form-control-sm" value="<?= esc(rtrim(rtrim(number_format((float) ($stockLevels[$vid] ?? 0), 3), '0'), '.') ?: '0', 'attr') ?>" title="On-hand stock in this shop"><?php endif; ?>
                <select name="status" class="form-select form-select-sm"><?php foreach (['active', 'inactive', 'discontinued'] as $s): ?><option value="<?= $s ?>" <?= $v['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select>
                <button class="btn btn-sm btn-primary" title="Save row"><i class="bi bi-check2"></i></button>
            </form>
            <div class="collapse" id="bc-<?= $vid ?>" style="grid-column:1/-1">
                <div class="bg-light rounded p-2 mt-1">
                    <form method="post" action="<?= $barcodeBase . $vid ?>/barcodes" class="js-bcform">
                        <?= csrf_field() ?>
                        <div class="small fw-semibold mb-1">Barcodes for <?= esc($v['sku']) ?> <span class="text-secondary fw-normal">— a variant can carry unit, retail-pack and master-pack codes; pick the primary.</span></div>
                        <div class="row g-1 small text-secondary mb-1" style="max-width:640px"><div class="col-auto" style="width:36px">Primary</div><div class="col-3">Type</div><div class="col">Value</div><div class="col-3">Pack</div><div class="col-auto" style="width:34px"></div></div>
                        <div class="js-bcrows">
                            <?php foreach (($vbc ?: [['barcode_type' => 'EAN13', 'barcode_value' => '', 'pack_level' => 'unit', 'is_primary' => 1]]) as $i => $b): ?>
                                <div class="input-group input-group-sm mb-1 js-bc-row" style="max-width:640px">
                                    <span class="input-group-text"><input type="radio" name="barcode_primary" value="<?= $i ?>" <?= ! empty($b['is_primary']) ? 'checked' : '' ?>></span>
                                    <select name="barcode_type[]" class="form-select" style="max-width:110px"><?php foreach (['EAN13', 'UPC', 'CODE128', 'CODE39', 'QR', 'ISBN', 'CUSTOM'] as $bt): ?><option <?= ($b['barcode_type'] ?? '') === $bt ? 'selected' : '' ?>><?= $bt ?></option><?php endforeach; ?></select>
                                    <input name="barcode_value[]" class="form-control" value="<?= esc($b['barcode_value'] ?? '', 'attr') ?>" placeholder="value">
                                    <select name="barcode_pack[]" class="form-select" style="max-width:100px"><?php foreach (['unit' => 'Unit', 'retail' => 'Retail', 'master' => 'Master'] as $pk => $pl): ?><option value="<?= $pk ?>" <?= ($b['pack_level'] ?? '') === $pk ? 'selected' : '' ?>><?= $pl ?></option><?php endforeach; ?></select>
                                    <button type="button" class="btn btn-outline-danger js-bcrm">&times;</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary js-bcadd">+ Add</button>
                        <button class="btn btn-sm btn-primary">Save barcodes</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($variants)): ?><div class="text-center text-secondary py-3">No variants yet — build them above.</div><?php endif; ?>
    </div></div>
</div>

<template id="tpl-vbc"><div class="input-group input-group-sm mb-1 js-bc-row" style="max-width:640px"><span class="input-group-text"><input type="radio" name="barcode_primary" value="0"></span><select name="barcode_type[]" class="form-select" style="max-width:110px"><option>EAN13</option><option>UPC</option><option>CODE128</option><option>CODE39</option><option>QR</option><option>ISBN</option><option>CUSTOM</option></select><input name="barcode_value[]" class="form-control" placeholder="value"><select name="barcode_pack[]" class="form-select" style="max-width:100px"><option value="unit">Unit</option><option value="retail">Retail</option><option value="master">Master</option></select><button type="button" class="btn btn-outline-danger js-bcrm">&times;</button></div></template>

<script>
(function () {
  function ready(fn){ document.readyState!=='loading'?fn():document.addEventListener('DOMContentLoaded',fn); }
  ready(function () {
    var all = document.getElementById('bcAll');
    var sels = function(){ return Array.prototype.slice.call(document.querySelectorAll('.js-vsel')); };
    function refresh(){ var n=sels().filter(function(c){return c.checked;}).length; var b=document.getElementById('bulkApply'); var c=document.getElementById('bulkCount'); if(c)c.textContent=n; if(b)b.disabled=n===0; }
    if(all){ all.addEventListener('change',function(){ sels().forEach(function(c){c.checked=all.checked;}); refresh(); }); }
    document.addEventListener('change',function(e){ if(e.target.classList&&e.target.classList.contains('js-vsel')) refresh(); });
    // per-variant barcode rows: add / remove / renumber primary on submit
    document.addEventListener('click',function(e){
      var add=e.target.closest('.js-bcadd');
      if(add){ var rows=add.parentElement.querySelector('.js-bcrows'); var tpl=document.getElementById('tpl-vbc'); if(rows&&tpl) rows.appendChild(tpl.content.cloneNode(true)); return; }
      var rm=e.target.closest('.js-bcrm'); if(rm){ var r=rm.closest('.js-bc-row'); if(r) r.remove(); }
    });
    document.querySelectorAll('.js-bcform').forEach(function(f){
      f.addEventListener('submit',function(){ f.querySelectorAll('.js-bc-row').forEach(function(row,idx){ var radio=row.querySelector('input[name="barcode_primary"]'); if(radio) radio.value=idx; }); });
    });
    refresh();

    // ---- step-guided builder: searchable (AJAX) value pickers + combo counter ----
    var $ = window.jQuery;
    var card = document.querySelector('[data-attrvalues-base]');
    var base = card ? card.getAttribute('data-attrvalues-base') : '';
    function initAjax(sel, multiple){
      if(!$ || !$.fn.select2) return;
      var attrId = sel.getAttribute('data-attr');
      $(sel).select2({
        theme:'bootstrap-5', width:'100%', allowClear:!multiple,
        placeholder: multiple ? 'Type to search values…' : '—', minimumInputLength:0,
        ajax:{ url: base + 'attributes/' + attrId + '/values', delay:200,
          data:function(p){ return { q: p.term || '' }; },
          processResults:function(d){ return { results:(d&&d.results)||[] }; } }
      });
    }
    document.querySelectorAll('.js-attr-values-single').forEach(function(s){ initAjax(s,false); });
    document.querySelectorAll('.js-attr-values').forEach(function(s){ initAjax(s,true); if($) $(s).prop('disabled',true); });

    function selFor(id){ return document.querySelector('.js-attr-values[data-attr="'+id+'"]'); }
    function rowFor(id){ return document.querySelector('.js-attr-row[data-attr="'+id+'"]'); }
    function recount(){
      var anyAttr=false, product=1, chosen=0;
      document.querySelectorAll('.js-attr-toggle').forEach(function(t){
        if(!t.checked) return; anyAttr=true;
        var sel=selFor(t.getAttribute('data-attr'));
        var n=sel?sel.selectedOptions.length:0;
        if(n>0){ product*=n; chosen++; }
      });
      var count = chosen>0 ? product : 0;
      var badge=document.getElementById('comboCount'); if(badge) badge.textContent = count>0 ? '· '+count+' combo'+(count>1?'s':'') : '';
      var btn=document.getElementById('genBtn'); if(btn) btn.disabled = count<=0;
      var note=document.querySelector('.js-no-attr'); if(note) note.classList.toggle('d-none', anyAttr);
    }
    document.querySelectorAll('.js-attr-toggle').forEach(function(t){
      t.addEventListener('change',function(){
        var id=t.getAttribute('data-attr'), row=rowFor(id), sel=selFor(id);
        if(row) row.classList.toggle('d-none', !t.checked);
        if(sel && $){ $(sel).prop('disabled', !t.checked); if(!t.checked) $(sel).val(null).trigger('change'); }
        recount();
      });
    });
    if($) $('.js-attr-values').on('change', recount);
    recount();

    // manual add: drop empty attribute selects so that attribute is omitted from the variant
    var manual=document.getElementById('manualForm');
    if(manual){ manual.addEventListener('submit',function(){ manual.querySelectorAll('.js-attr-values-single').forEach(function(s){ if(!s.value) s.disabled=true; }); }); }
  });
})();
</script>
