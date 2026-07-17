<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Two lightweight, dependency-free approaches: the native <code class="uk-code">&lt;datalist&gt;</code> and a custom filtered dropdown.</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Native datalist</h2>
        <label class="form-label">City</label>
        <input class="form-control" list="cities" placeholder="Start typing…">
        <datalist id="cities">
            <?php foreach (['Mumbai','Pune','Bengaluru','Delhi','Hyderabad','Chennai','Kolkata','Ahmedabad','Jaipur','Surat'] as $c): ?>
                <option value="<?= $c ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <p class="text-secondary small mt-2 mb-0">Zero JavaScript — browser-native suggestions.</p>
    </div></div></div>

    <div class="col-lg-6"><div class="card uk-card h-100"><div class="card-body">
        <h2 class="uk-section-title mb-3">Custom autocomplete</h2>
        <label class="form-label">Product</label>
        <div class="position-relative">
            <input class="form-control" id="acInput" placeholder="Search products…" autocomplete="off">
            <div id="acList" class="list-group position-absolute w-100 shadow-sm" style="z-index:5; display:none; max-height:220px; overflow:auto"></div>
        </div>
        <p class="text-secondary small mt-2 mb-0">Filters a JS array as you type · keyboard friendly.</p>
    </div></div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var data = ['Wireless Earbuds','Running Shoes','Smart Watch','Cotton T-Shirt','Coffee Beans','Backpack',
        'Bluetooth Speaker','Yoga Mat','Office Chair','Water Bottle','Desk Lamp','Notebook'];
    var input = document.getElementById('acInput'), list = document.getElementById('acList');
    function render(q) {
        list.innerHTML = '';
        if (!q) { list.style.display = 'none'; return; }
        var hits = data.filter(function (d) { return d.toLowerCase().indexOf(q.toLowerCase()) > -1; }).slice(0, 6);
        if (!hits.length) { list.style.display = 'none'; return; }
        hits.forEach(function (h) {
            var a = document.createElement('button');
            a.type = 'button'; a.className = 'list-group-item list-group-item-action';
            a.innerHTML = h.replace(new RegExp('(' + q + ')', 'ig'), '<strong>$1</strong>');
            a.addEventListener('click', function () { input.value = h; list.style.display = 'none'; });
            list.appendChild(a);
        });
        list.style.display = 'block';
    }
    input.addEventListener('input', function () { render(this.value.trim()); });
    document.addEventListener('click', function (e) { if (!e.target.closest('#acInput') && !e.target.closest('#acList')) list.style.display = 'none'; });
})();
</script>
<?= $this->endSection() ?>
