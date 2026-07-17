<?= $this->extend('uikit/_layout') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/leaflet/leaflet.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Interactive maps with Leaflet (loaded locally). Map tiles are fetched from OpenStreetMap and require an internet connection.</p>

<div class="card uk-card mb-3"><div class="card-body">
    <h2 class="uk-section-title mb-3">Vendor locations</h2>
    <div id="ukMap" style="height:420px;border-radius:.6rem"></div>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/leaflet/leaflet.js') ?>"></script>
<script>
L.Icon.Default.imagePath = '<?= base_url('assets/plugins/leaflet/images') ?>/';
var map = L.map('ukMap').setView([19.076, 72.877], 5);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
var pins = [
    [19.076, 72.877, 'Fresh Foods — Mumbai'],
    [18.520, 73.856, 'Style Hub — Pune'],
    [12.972, 77.594, 'Tech World — Bengaluru'],
    [28.704, 77.102, 'Shoe Bazaar — Delhi'],
    [17.385, 78.486, 'Green Grocers — Hyderabad']
];
pins.forEach(function (p) { L.marker([p[0], p[1]]).addTo(map).bindPopup('<b>' + p[2] + '</b>'); });
</script>
<?= $this->endSection() ?>
