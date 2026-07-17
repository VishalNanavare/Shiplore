<?php $rider = session()->get('rider_name'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title><?= esc(($title ?? 'Rider') . ' · ' . service('settingsRepository')->brandName() . ' Rider') ?></title>
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-name" content="<?= csrf_token() ?>">
    <meta name="csrf-hash" content="<?= csrf_hash() ?>">
    <link rel="icon" href="<?= esc(service('settingsRepository')->logoUrl(), 'attr') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/rider.css') ?>">
    <link rel="stylesheet" href="<?= asset('plugins/flatpickr/flatpickr.min.css') ?>">
    <?= $this->renderSection('head') ?>
</head>
<body class="rd-body">
    <header class="rd-header">
        <div class="rd-wrap d-flex align-items-center justify-content-between">
            <a href="<?= site_url('rider/dashboard') ?>" class="rd-brand d-flex align-items-center gap-2">
                <img src="<?= esc(service('settingsRepository')->logoUrl(), 'attr') ?>" alt="" width="26" height="26" style="object-fit:contain;border-radius:4px">
                <?= esc(service('settingsRepository')->brandName()) ?> Rider
            </a>
            <?php if ($rider): ?>
                <div class="dropdown">
                    <button class="btn btn-sm rd-userbtn dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-person-circle me-1"></i><?= esc($rider) ?></button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><a class="dropdown-item" href="<?= site_url('rider/dashboard') ?>"><i class="bi bi-house me-2"></i>Home</a></li>
                        <li><a class="dropdown-item" href="<?= site_url('rider/deliveries') ?>"><i class="bi bi-box-seam me-2"></i>My trips</a></li>
                        <li><a class="dropdown-item" href="<?= site_url('rider/earnings') ?>"><i class="bi bi-wallet2 me-2"></i>Earnings</a></li>
                        <li><a class="dropdown-item" href="<?= site_url('rider/performance') ?>"><i class="bi bi-graph-up-arrow me-2"></i>Performance</a></li>
                        <li><a class="dropdown-item" href="<?= site_url('rider/documents') ?>"><i class="bi bi-file-earmark-text me-2"></i>My documents</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><form method="post" action="<?= site_url('rider/logout') ?>" class="m-0"><?= csrf_field() ?><button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button></form></li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <?= $this->include('partials/_impersonation_banner') ?>
    <main class="rd-wrap py-3"><?= $this->renderSection('content') ?></main>

    <?php if ($rider): ?>
    <nav class="rd-tabbar">
        <a href="<?= site_url('rider/dashboard') ?>" class="rd-tab"><i class="bi bi-house-door"></i><span>Home</span></a>
        <a href="<?= site_url('rider/deliveries?status=active') ?>" class="rd-tab"><i class="bi bi-truck"></i><span>Active</span></a>
        <a href="<?= site_url('rider/route') ?>" class="rd-tab"><i class="bi bi-signpost-split"></i><span>Route</span></a>
        <a href="<?= site_url('rider/earnings') ?>" class="rd-tab"><i class="bi bi-wallet2"></i><span>Earnings</span></a>
        <a href="<?= site_url('rider/performance') ?>" class="rd-tab"><i class="bi bi-graph-up-arrow"></i><span>You</span></a>
    </nav>
    <?php endif; ?>

    <script src="<?= asset('vendor/jquery/jquery.min.js') ?>"></script>
    <script src="<?= asset('vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
    <script src="<?= asset('js/app.js') ?>"></script>
    <script src="<?= asset('plugins/sweetalert2/sweetalert2.all.min.js') ?>"></script>
    <script src="<?= asset('js/ajax-forms.js') ?>"></script>
    <script src="<?= asset('plugins/flatpickr/flatpickr.min.js') ?>"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        flatpickr('input[type="date"]',           { dateFormat: 'Y-m-d', allowInput: true });
        flatpickr('input[type="datetime-local"]', { enableTime: true, dateFormat: 'Y-m-d H:i', allowInput: true, time_24hr: true });
        flatpickr('input[type="time"]',           { enableTime: true, noCalendar: true, dateFormat: 'H:i', allowInput: true, time_24hr: true });
    });
    </script>
    <?php if ($rider): ?>
    <script>
    // Phase 3 — periodic GPS ping so customers see the rider's live location.
    (function () {
        if (!('geolocation' in navigator)) { return; }
        function csrf(name) { var m = document.querySelector('meta[name="' + name + '"]'); return m ? m.getAttribute('content') : ''; }
        function ping() {
            navigator.geolocation.getCurrentPosition(function (pos) {
                var body = new URLSearchParams();
                body.append('lat', pos.coords.latitude);
                body.append('lng', pos.coords.longitude);
                body.append(csrf('csrf-name'), csrf('csrf-hash'));
                fetch('<?= site_url('rider/location') ?>', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (j) {
                        // CSRF regenerates per request — push the fresh hash to the meta AND
                        // every on-page form input so the next form submit isn't stale.
                        if (!j || !j.csrf) { return; }
                        var name = csrf('csrf-name');
                        if (name) { document.querySelectorAll('input[name="' + name + '"]').forEach(function (i) { i.value = j.csrf; }); }
                        var m = document.querySelector('meta[name="csrf-hash"]');
                        if (m) { m.setAttribute('content', j.csrf); }
                    })
                    .catch(function () {});
            }, function () {}, { enableHighAccuracy: true, maximumAge: 15000, timeout: 12000 });
        }
        ping();
        setInterval(ping, 30000);
    })();
    </script>
    <?php endif; ?>
    <?= $this->renderSection('scripts') ?>
</body>
</html>
