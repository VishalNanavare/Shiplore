<?php if (($mapsKey ?? '') !== ''): ?>
<script>window.__stateCodes = <?= json_encode($stateNameToCode ?? [], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= asset('js/address-map.js') ?>"></script>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= esc($mapsKey, 'attr') ?>&libraries=places&callback=initRegMap"></script>
<?php endif; ?>
