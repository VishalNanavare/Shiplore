<?php
$mapsKey = (string) (service('integrationRepository')->config('google_maps')['browser_key'] ?? '');
$loc     = service('locationService')->get();
?>
<div class="modal fade" id="stLocModal" tabindex="-1" aria-hidden="true"
     data-maps="<?= $mapsKey !== '' ? '1' : '0' ?>"
     data-lat="<?= esc((string) ($loc['lat'] ?? ''), 'attr') ?>"
     data-lng="<?= esc((string) ($loc['lng'] ?? ''), 'attr') ?>">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content st-locmodal">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title"><i class="bi bi-geo-alt-fill text-primary me-1"></i>Choose your location</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-2">
                <p class="text-secondary small mb-2">We'll show shops and products that deliver to you.</p>
                <button type="button" id="stUseCurrent" class="btn btn-outline-primary w-100 mb-2">
                    <i class="bi bi-crosshair me-1"></i>Use my current location
                </button>

                <div class="position-relative mb-2">
                    <span class="position-absolute top-50 translate-middle-y ms-2 text-secondary" style="left:.25rem"><i class="bi bi-search"></i></span>
                    <input id="stLocSearch" class="form-control ps-5" placeholder="Search area, street or landmark…" autocomplete="off">
                </div>

                <div id="stLocMap" class="st-locmap mb-2"></div>
                <div id="stLocHint" class="form-text mb-2 d-none"><i class="bi bi-hand-index-thumb me-1"></i>Drag the pin to fine-tune.</div>

                <form method="post" action="<?= site_url('store/location') ?>" id="stLocForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="lat" id="stLat" value="<?= esc((string) ($loc['lat'] ?? ''), 'attr') ?>">
                    <input type="hidden" name="lng" id="stLng" value="<?= esc((string) ($loc['lng'] ?? ''), 'attr') ?>">
                    <input type="hidden" name="return" id="stReturn" value="">
                    <!-- reverse-geocoded components (auto-fill checkout); prefilled so re-confirming keeps them -->
                    <input type="hidden" name="city" id="stCity" value="<?= esc((string) ($loc['city'] ?? ''), 'attr') ?>">
                    <input type="hidden" name="state_code" id="stState" value="<?= esc((string) ($loc['state_code'] ?? ''), 'attr') ?>">
                    <input type="hidden" name="line1" id="stLine1" value="<?= esc((string) ($loc['line1'] ?? ''), 'attr') ?>">
                    <input type="hidden" name="formatted" id="stFormatted" value="<?= esc((string) ($loc['formatted'] ?? ''), 'attr') ?>">
                    <div class="row g-2">
                        <div class="col-7"><input name="label" id="stLabel" class="form-control form-control-sm" placeholder="Address / area" value="<?= esc((string) ($loc['label'] ?? ''), 'attr') ?>" required></div>
                        <div class="col-5"><input name="pincode" id="stPin" class="form-control form-control-sm" inputmode="numeric" maxlength="6" placeholder="Pincode" value="<?= esc((string) ($loc['pincode'] ?? ''), 'attr') ?>"></div>
                    </div>
                    <button class="btn btn-primary w-100 mt-2" id="stLocConfirm" <?= $loc ? '' : 'disabled' ?>><i class="bi bi-check2 me-1"></i>Deliver here</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php if ($mapsKey !== ''): ?>
<script>window.__stMapsKey = <?= json_encode($mapsKey) ?>;</script>
<?php endif; ?>
