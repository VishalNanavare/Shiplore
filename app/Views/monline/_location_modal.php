<?php
$mapsKey = (string) (service('integrationRepository')->config('google_maps')['browser_key'] ?? '');
$loc     = service('monlineLocationService')->get();
?>
<div class="modal fade" id="moLocModal" tabindex="-1" aria-hidden="true"
     data-maps="<?= $mapsKey !== '' ? '1' : '0' ?>"
     data-lat="<?= esc((string) ($loc['lat'] ?? ''), 'attr') ?>"
     data-lng="<?= esc((string) ($loc['lng'] ?? ''), 'attr') ?>">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title"><i class="bi bi-geo-alt-fill text-primary me-1"></i>Sort by a different location</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-2">
                <p class="text-secondary small mb-2">
                    Manufacturers are sorted nearest first — every one still shows, this only changes the order.
                </p>
                <button type="button" id="moUseCurrent" class="btn btn-outline-primary w-100 mb-2">
                    <i class="bi bi-crosshair me-1"></i>Use my current location
                </button>

                <div class="position-relative mb-2">
                    <span class="position-absolute top-50 translate-middle-y ms-2 text-secondary" style="left:.25rem"><i class="bi bi-search"></i></span>
                    <input id="moLocSearch" class="form-control ps-5" placeholder="Search area, city or landmark…" autocomplete="off">
                </div>

                <div id="moLocMap" style="height:220px;border-radius:var(--mo-radius)" class="mb-2"></div>
                <div id="moLocHint" class="form-text mb-2 d-none"><i class="bi bi-hand-index-thumb me-1"></i>Drag the pin to fine-tune.</div>

                <form method="post" action="<?= site_url('monline/location') ?>" id="moLocForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="lat" id="moLat" value="<?= esc((string) ($loc['lat'] ?? ''), 'attr') ?>">
                    <input type="hidden" name="lng" id="moLng" value="<?= esc((string) ($loc['lng'] ?? ''), 'attr') ?>">
                    <input type="hidden" name="return" id="moReturn" value="">
                    <input name="label" id="moLabel" class="form-control form-control-sm" placeholder="Label (e.g. a city or area)" value="<?= esc((string) ($loc['label'] ?? ''), 'attr') ?>" required>
                    <button class="btn btn-primary w-100 mt-2" id="moLocConfirm" <?= $loc ? '' : 'disabled' ?>><i class="bi bi-check2 me-1"></i>Sort from here</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php if ($mapsKey !== ''): ?>
<script>window.__moMapsKey = <?= json_encode($mapsKey) ?>;</script>
<?php endif; ?>
