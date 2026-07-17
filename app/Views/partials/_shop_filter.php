<?php
/**
 * Shop filter dropdown — include with $shopOptions (id=>name), $shopId (selected,
 * nullable) and optionally $filterKeep (other query params to preserve). Hidden
 * when the user has 0-1 shops (nothing to switch between).
 */
$shopOptions = $shopOptions ?? [];
if (count($shopOptions) < 2) {
    return;
}
?>
<form method="get" class="d-inline-block">
    <?php foreach (($filterKeep ?? []) as $k => $v): ?>
        <?php if ($v !== '' && $v !== null): ?><input type="hidden" name="<?= esc($k, 'attr') ?>" value="<?= esc($v, 'attr') ?>"><?php endif; ?>
    <?php endforeach; ?>
    <select name="shop_id" class="form-select form-select-sm w-auto d-inline-block" onchange="this.form.submit()" title="Filter by store">
        <option value="">All stores</option>
        <?php foreach ($shopOptions as $id => $name): ?>
            <option value="<?= esc($id, 'attr') ?>" <?= (int) ($shopId ?? 0) === (int) $id ? 'selected' : '' ?>><?= esc($name) ?></option>
        <?php endforeach; ?>
    </select>
</form>
