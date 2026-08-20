<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $isEdit = $vendor !== null; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<div class="row"><div class="col-lg-9">
    <div class="card"><div class="card-body p-4">
        <form method="post" action="<?= $isEdit ? site_url('admin/vendors/' . $vendor['id'] . '/update') : site_url('admin/vendors/store') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <h2 class="h6 text-secondary text-uppercase mb-3">Business</h2>
            <div class="row g-3 mb-4">
                <div class="col-md-6"><label class="form-label">Business Type <span class="text-danger">*</span></label>
                    <select name="business_type_id" class="form-select" required>
                        <option value="">Choose…</option>
                        <?php foreach ($businessTypes as $bt): ?>
                            <option value="<?= esc($bt['id'], 'attr') ?>" <?= ($isEdit ? (int) $vendor['business_type_id'] : (int) old('business_type_id')) === (int) $bt['id'] ? 'selected' : '' ?>><?= esc($bt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6"><label class="form-label">GSTIN</label><input name="gstin" class="form-control text-uppercase" maxlength="15" value="<?= esc($isEdit ? ($vendor['gstin'] ?? '') : old('gstin'), 'attr') ?>" <?= $isEdit ? 'readonly' : '' ?>></div>
                <div class="col-md-6"><label class="form-label">Legal Name <span class="text-danger">*</span></label><input name="legal_name" class="form-control" value="<?= esc($isEdit ? $vendor['legal_name'] : old('legal_name'), 'attr') ?>" required></div>
                <div class="col-md-6"><label class="form-label">Display Name <span class="text-danger">*</span></label><input name="display_name" class="form-control" value="<?= esc($isEdit ? $vendor['display_name'] : old('display_name'), 'attr') ?>" required></div>
                <?php if ($isEdit): ?>
                    <div class="col-md-4"><label class="form-label">PAN</label><input name="pan" class="form-control text-uppercase" maxlength="10" value="<?= esc($vendor['pan'] ?? '', 'attr') ?>"></div>
                    <div class="col-md-4"><label class="form-label">State</label>
                        <select name="state_code" class="form-select">
                            <option value="">Choose…</option>
                            <?php foreach ($states as $code => $name): ?>
                                <option value="<?= esc($code, 'attr') ?>" <?= (string) ($vendor['state_code'] ?? '') === (string) $code ? 'selected' : '' ?>><?= esc($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Commission Plan</label>
                        <select name="commission_plan_id" class="form-select">
                            <option value="">None</option>
                            <?php foreach ($commissionPlans as $cp): ?>
                                <option value="<?= esc($cp['id'], 'attr') ?>" <?= (int) ($vendor['commission_plan_id'] ?? 0) === (int) $cp['id'] ? 'selected' : '' ?>><?= esc($cp['name']) ?><?= ($cp['status'] ?? 'active') !== 'active' ? ' (inactive)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Payout Cycle</label>
                        <select name="payout_cycle" class="form-select">
                            <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'biweekly' => 'Biweekly', 'monthly' => 'Monthly'] as $val => $label): ?>
                                <option value="<?= $val ?>" <?= ($vendor['payout_cycle'] ?? 'weekly') === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8 d-flex align-items-end">
                        <div class="form-check">
                            <input type="checkbox" name="is_composition" value="1" class="form-check-input" id="isComposition" <?= ! empty($vendor['is_composition']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="isComposition">Composition scheme dealer</label>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Business Logo</label>
                        <div class="d-flex align-items-center gap-3">
                            <div class="border rounded d-flex align-items-center justify-content-center" style="width:64px;height:64px;overflow:hidden;background:#f8f9fb;flex:0 0 auto">
                                <?php if (! empty($logoUuid)): ?>
                                    <img src="<?= site_url('media/' . $logoUuid) ?>" alt="Logo" style="max-width:100%;max-height:100%">
                                <?php else: ?>
                                    <i class="bi bi-shop text-secondary fs-4"></i>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <input type="file" name="logo" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif,image/*">
                                <div class="form-text">JPG, PNG, WEBP or GIF · up to 5 MB. Stored on the S3 media server.</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (! $isEdit): ?>
                <h2 class="h6 text-secondary text-uppercase mb-3">Owner login</h2>
                <div class="row g-3 mb-4">
                    <div class="col-md-4"><label class="form-label">Email <span class="text-danger">*</span></label><input type="email" name="email" class="form-control" value="<?= esc(old('email'), 'attr') ?>" required></div>
                    <div class="col-md-4"><label class="form-label">Mobile <span class="text-danger">*</span></label><input name="mobile" class="form-control" value="<?= esc(old('mobile'), 'attr') ?>" placeholder="+91…" required></div>
                    <div class="col-md-4"><label class="form-label">Password <span class="text-danger">*</span></label><input type="password" name="password" class="form-control" minlength="8" required></div>
                </div>

                <h2 class="h6 text-secondary text-uppercase mb-3">First shop</h2>
                <div class="row g-3 mb-4">
                    <div class="col-12"><label class="form-label">Shop Name</label><input name="shop_name" class="form-control" value="<?= esc(old('shop_name'), 'attr') ?>" placeholder="(defaults to display name)"></div>
                    <?= $this->include('partials/_shop_location', ['mapsKey' => $mapsKey, 'states' => $states, 'maxRadius' => $maxRadius]) ?>
                </div>
            <?php else: ?>
                <div class="alert alert-light border small"><i class="bi bi-info-circle me-1"></i>Owner: <?= esc($vendor['owner_email'] ?? '—') ?> · <?= esc($vendor['owner_phone'] ?? '') ?>. Shops are managed under <a href="<?= site_url('admin/shops') ?>">Shops</a>.</div>
            <?php endif; ?>

            <div class="d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= $isEdit ? 'Update vendor' : 'Create vendor' ?></button>
                <a href="<?= site_url('admin/vendors') ?>" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div></div>
</div></div>
<?= $this->endSection() ?>

<?php if (! $isEdit): ?>
<?= $this->section('scripts') ?>
<?= $this->include('partials/_shop_location_scripts', ['mapsKey' => $mapsKey, 'stateNameToCode' => $stateNameToCode]) ?>
<?= $this->endSection() ?>
<?php endif; ?>
