<?= $this->extend('layouts/store') ?>

<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= asset('plugins/datatables/dataTables.bootstrap5.min.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$badge = ['created' => 'info', 'confirmed' => 'primary', 'partially_fulfilled' => 'warning', 'completed' => 'success', 'cancelled' => 'danger'];
$act   = static fn (string $t): bool => ($activeTab ?? 'overview') === $t;
$pf    = $profile ?? [];
$tabs  = [
    'overview'      => ['Overview', 'grid-1x2'],
    'orders'        => ['My Orders', 'bag-check'],
    'addresses'     => ['Addresses', 'geo-alt'],
    'wishlist'      => ['Wishlist', 'heart'],
    'notifications' => ['Notifications', 'bell'],
    'support'       => ['Support & Returns', 'life-preserver'],
    'profile'       => ['Profile', 'person'],
];
$recent = array_slice($orders, 0, 5);
// "View & track" opens the order detail modal (status timeline + bills + cancel/return).
$orderActions = static function (array $o) {
    $frag = site_url('store/account/orders/' . rawurlencode((string) $o['order_no']) . '/fragment');
    $full = site_url('store/account/orders/' . rawurlencode((string) $o['order_no']));
    return '<a href="' . $full . '" data-order-modal="' . $frag . '" data-order-no="' . esc($o['order_no'], 'attr') . '" class="btn btn-sm btn-primary"><i class="bi bi-eye me-1"></i>View &amp; track</a>';
};
?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<!-- Profile header + stats -->
<div class="card st-acc-head mb-3"><div class="card-body d-flex flex-wrap align-items-center gap-3">
    <span class="st-acc-avatar"><?= esc(strtoupper(substr((string) ($pf['name'] ?? 'C'), 0, 2))) ?></span>
    <div class="flex-grow-1 min-w-0">
        <h1 class="h5 mb-0">Hi, <?= esc($pf['name'] ?? 'there') ?> 👋</h1>
        <div class="text-secondary small"><?= esc($pf['email'] ?? '') ?><?php if (($pf['phone'] ?? '') !== ''): ?> · <?= esc($pf['phone']) ?><?php endif; ?></div>
    </div>
    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="bi bi-pencil me-1"></i>Edit profile</button>
    <form method="post" action="<?= site_url('store/logout') ?>" class="m-0"><?= csrf_field() ?><button class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right me-1"></i>Sign out</button></form>
</div></div>

<div class="row g-2 mb-3">
    <?php foreach ([
        ['bag-check', 'Orders', (int) ($stats['orders'] ?? 0), 'orders'],
        ['currency-rupee', 'Total spent', '₹' . number_format((float) ($stats['spent'] ?? 0), 0), 'orders'],
        ['geo-alt', 'Addresses', (int) ($stats['addresses'] ?? 0), 'addresses'],
        ['heart', 'Wishlist', (int) ($stats['wishlist'] ?? 0), 'wishlist'],
    ] as [$ic, $lbl, $val, $go]): ?>
        <div class="col-6 col-md-3">
            <button type="button" class="st-stat w-100 text-start" data-go="<?= $go ?>">
                <span class="st-stat-ic"><i class="bi bi-<?= $ic ?>"></i></span>
                <span class="st-stat-val"><?= esc((string) $val) ?></span>
                <span class="st-stat-lbl"><?= esc($lbl) ?></span>
            </button>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-3">
        <div class="card"><div class="card-body p-2">
            <div class="nav flex-lg-column nav-pills st-acc-nav flex-row overflow-auto" role="tablist">
                <?php foreach ($tabs as $key => [$label, $icon]): ?>
                    <button class="nav-link<?= $act($key) ? ' active' : '' ?>" data-bs-toggle="pill" data-bs-target="#pane-<?= $key ?>" data-tab="<?= $key ?>" type="button" role="tab"><i class="bi bi-<?= $icon ?> me-2"></i><?= esc($label) ?></button>
                <?php endforeach; ?>
            </div>
        </div></div>
    </div>

    <div class="col-lg-9"><div class="tab-content">

        <!-- Overview -->
        <div class="tab-pane fade<?= $act('overview') ? ' show active' : '' ?>" id="pane-overview" role="tabpanel">
            <div id="region-overview"><div class="card"><div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h6 mb-0">Recent orders</h2>
                    <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" data-go="orders">View all</button>
                </div>
                <?php if (empty($recent)): ?>
                    <div class="st-empty"><i class="bi bi-bag"></i>No orders yet.<div class="mt-2"><a href="<?= site_url('store/products') ?>" class="btn btn-sm btn-primary">Start shopping</a></div></div>
                <?php else: ?>
                    <div class="table-responsive"><table id="overviewTable" class="table align-middle w-100">
                        <thead class="table-light"><tr><th>Order</th><th>Total</th><th>Status</th><th>Date</th><th class="no-sort"></th></tr></thead>
                        <tbody>
                        <?php foreach ($recent as $o): ?>
                            <tr>
                                <td class="fw-medium small"><?= esc($o['order_no']) ?></td>
                                <td>₹<?= esc(number_format((float) $o['grand_total'], 2)) ?></td>
                                <td><span class="badge text-bg-<?= esc($badge[$o['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $o['status'])) ?></span></td>
                                <td class="text-secondary small"><?= esc(substr((string) $o['created_at'], 0, 10)) ?></td>
                                <td class="text-end"><?= $orderActions($o) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div></div></div>
        </div>

        <!-- Orders -->
        <div class="tab-pane fade<?= $act('orders') ? ' show active' : '' ?>" id="pane-orders" role="tabpanel">
            <div id="region-orders"><div class="card"><div class="card-body">
                <h2 class="h6 mb-3">My orders</h2>
                <?php if (empty($orders)): ?>
                    <div class="st-empty"><i class="bi bi-bag"></i>No orders yet.</div>
                <?php else: ?>
                    <div class="table-responsive"><table id="ordersTable" class="table align-middle w-100">
                        <thead class="table-light"><tr><th>Order</th><th>Total</th><th>Status</th><th>Date</th><th class="no-sort text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td class="fw-medium small"><?= esc($o['order_no']) ?><div class="text-secondary text-capitalize" style="font-size:.7rem"><?= esc(str_replace('_', ' ', $o['payment_status'])) ?></div></td>
                                <td>₹<?= esc(number_format((float) $o['grand_total'], 2)) ?></td>
                                <td><span class="badge text-bg-<?= esc($badge[$o['status']] ?? 'secondary', 'attr') ?>"><?= esc(str_replace('_', ' ', $o['status'])) ?></span></td>
                                <td class="text-secondary small"><?= esc(substr((string) $o['created_at'], 0, 10)) ?></td>
                                <td class="text-end" style="white-space:nowrap">
                                    <?= $orderActions($o) ?>
                                    <?php foreach (($invoices[$o['order_no']] ?? []) as $inv): ?>
                                        <a href="<?= site_url('store/account/invoices/' . (int) $inv['id'] . '/pdf') ?>" class="btn btn-sm btn-outline-secondary" title="<?= esc($inv['invoice_no'], 'attr') ?>"><i class="bi bi-filetype-pdf"></i></a>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div></div></div>
        </div>

        <!-- Addresses -->
        <div class="tab-pane fade<?= $act('addresses') ? ' show active' : '' ?>" id="pane-addresses" role="tabpanel">
            <div id="region-addresses">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Saved addresses</h2>
                    <a href="<?= site_url('store/account?tab=addresses') ?>" data-addr-modal="<?= site_url('store/account/address-form') ?>" data-addr-target="#addAddrModal" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i>Add a new address</a>
                </div>
                <div class="row g-2">
                    <?php foreach ($addresses as $a): ?>
                        <div class="col-md-6"><div class="card h-100"><div class="card-body">
                            <div class="d-flex justify-content-between gap-2">
                                <div class="min-w-0">
                                    <span class="fw-medium"><?= esc($a['label'] ?: 'Address') ?></span><?php if ($a['is_default']): ?> <span class="badge bg-primary-subtle text-primary">default</span><?php endif; ?>
                                    <?php if (($a['recipient_name'] ?? '') !== ''): ?><div class="small"><?= esc($a['recipient_name']) ?><?php if (($a['phone'] ?? '') !== ''): ?> · <?= esc($a['phone']) ?><?php endif; ?></div><?php endif; ?>
                                    <div class="text-secondary small"><?= esc(($a['formatted_address'] ?? '') !== '' ? $a['formatted_address'] : trim((string) ($a['line1'] ?? '') . (($a['line2'] ?? '') !== '' ? ', ' . $a['line2'] : '') . ', ' . (string) ($a['city'] ?? '') . ' ' . (string) ($a['pincode'] ?? ''), ', ')) ?></div>
                                </div>
                            </div>
                            <div class="mt-2 d-flex gap-2">
                                <a href="<?= site_url('store/account?tab=addresses') ?>" data-addr-modal="<?= site_url('store/account/address-form?id=' . (int) $a['id']) ?>" data-addr-target="#addAddrModal" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
                                <form method="post" action="<?= site_url('store/account/addresses/' . (int) $a['id'] . '/delete') ?>" data-ajax-refresh="#region-addresses" data-confirm="Remove this address?"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete</button></form>
                            </div>
                        </div></div></div>
                    <?php endforeach; ?>
                    <?php if (empty($addresses)): ?><div class="col-12"><div class="st-empty"><i class="bi bi-geo-alt"></i>No saved addresses yet.<div class="mt-2"><a href="<?= site_url('store/account?tab=addresses') ?>" data-addr-modal="<?= site_url('store/account/address-form') ?>" data-addr-target="#addAddrModal" class="btn btn-sm btn-success"><i class="bi bi-plus-lg me-1"></i>Add a new address</a></div></div></div><?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Wishlist -->
        <div class="tab-pane fade<?= $act('wishlist') ? ' show active' : '' ?>" id="pane-wishlist" role="tabpanel">
            <div id="region-wishlist">
                <h2 class="h6 mb-2">Your wishlist</h2>
                <?php if (empty($wishlist)): ?>
                    <div class="st-empty"><i class="bi bi-heart"></i>Your wishlist is empty.<div class="mt-2"><a href="<?= site_url('store/products') ?>" class="btn btn-sm btn-primary">Browse products</a></div></div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($wishlist as $p): ?>
                            <div class="col-6 col-md-4 col-xl-3">
                                <div class="position-relative st-wish-item h-100">
                                    <form method="post" action="<?= site_url('store/wishlist/remove') ?>" data-ajax-refresh="#region-wishlist" class="st-wish-x"><?= csrf_field() ?><input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>"><button type="submit" class="btn btn-sm btn-light rounded-circle" title="Remove from wishlist"><i class="bi bi-x-lg"></i></button></form>
                                    <?= view('partials/_store_product_card', ['p' => $p]) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Notifications -->
        <div class="tab-pane fade<?= $act('notifications') ? ' show active' : '' ?>" id="pane-notifications" role="tabpanel">
            <div class="card"><div class="card-body">
                <h2 class="h6 mb-3">Notifications</h2>
                <?php if (empty($notifications)): ?>
                    <div class="st-empty"><i class="bi bi-bell"></i>No notifications.</div>
                <?php else: ?>
                    <div class="table-responsive"><table id="notifTable" class="table align-middle w-100">
                        <thead class="table-light"><tr><th style="width:40px" class="no-sort"></th><th>Notification</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($notifications as $n): ?>
                            <tr>
                                <td><span class="rounded d-grid bg-primary-subtle text-primary" style="width:32px;height:32px;place-items:center"><i class="bi bi-<?= ($n['category'] ?? '') === 'promotional' ? 'megaphone' : 'bell' ?>"></i></span></td>
                                <td><div class="fw-medium small"><?= esc($n['title'] ?? $n['event_code'] ?? 'Update') ?></div><div class="text-secondary small"><?= esc($n['body'] ?? '') ?></div></td>
                                <td class="text-secondary small" style="white-space:nowrap"><?= esc(substr((string) ($n['created_at'] ?? ''), 0, 16)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div></div>
        </div>

        <!-- Support & Returns -->
        <div class="tab-pane fade<?= $act('support') ? ' show active' : '' ?>" id="pane-support" role="tabpanel">
            <div id="region-support"><div class="row g-3">
                <div class="col-lg-7"><div class="card"><div class="card-body">
                    <h2 class="h6 mb-3">Request a return / refund</h2>
                    <?php if (empty($orders)): ?>
                        <div class="st-empty"><i class="bi bi-bag"></i>You don't have any orders yet — you can request a return once you've made a purchase.<div class="mt-2"><a href="<?= site_url('store/products') ?>" class="btn btn-sm btn-primary">Start shopping</a></div></div>
                    <?php else: ?>
                        <form method="post" action="<?= site_url('store/account/return') ?>" data-ajax-refresh="#region-support">
                            <?= csrf_field() ?>
                            <div class="mb-2"><label class="form-label">Order</label>
                                <select name="order_no" class="form-select" required>
                                    <option value="">Select an order…</option>
                                    <?php foreach ($orders as $o): ?><option value="<?= esc($o['order_no'], 'attr') ?>"><?= esc($o['order_no']) ?> · ₹<?= esc(number_format((float) $o['grand_total'], 0)) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3"><label class="form-label">Reason</label><textarea name="reason" class="form-control" rows="3" placeholder="Tell us what went wrong…" required></textarea></div>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-counterclockwise me-1"></i>Submit request</button>
                        </form>
                        <div class="form-text mt-2">For per-item returns, open an order from <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-go="orders">My Orders</button> and choose "Return".</div>
                    <?php endif; ?>
                </div></div></div>
                <div class="col-lg-5"><div class="card"><div class="card-body">
                    <h2 class="h6 mb-2">Need help?</h2>
                    <p class="text-secondary small mb-2">Our team typically responds within 24 hours. Returns are eligible within 7 days of delivery.</p>
                    <ul class="list-unstyled small mb-0">
                        <li class="mb-1"><i class="bi bi-envelope me-2 text-secondary"></i>support@example.com</li>
                        <li><i class="bi bi-telephone me-2 text-secondary"></i>1800-123-4567</li>
                    </ul>
                </div></div></div>
            </div></div>
        </div>

        <!-- Profile -->
        <div class="tab-pane fade<?= $act('profile') ? ' show active' : '' ?>" id="pane-profile" role="tabpanel">
            <div id="region-profile"><div class="row g-3">
                <div class="col-lg-7"><div class="card"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h6 mb-0">Profile details</h2><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="bi bi-pencil me-1"></i>Edit</button></div>
                    <dl class="row mb-0 small">
                        <dt class="col-4 text-secondary fw-normal">Name</dt><dd class="col-8"><?= esc($pf['name'] ?? '—') ?></dd>
                        <dt class="col-4 text-secondary fw-normal">Email</dt><dd class="col-8"><?= esc(($pf['email'] ?? '') !== '' ? $pf['email'] : '—') ?></dd>
                        <dt class="col-4 text-secondary fw-normal">Mobile</dt><dd class="col-8"><?= esc(($pf['phone'] ?? '') !== '' ? $pf['phone'] : '—') ?></dd>
                    </dl>
                </div></div></div>
                <div class="col-lg-5"><div class="card"><div class="card-body">
                    <h2 class="h6 mb-2">Account</h2>
                    <div class="small text-secondary">Member since</div>
                    <div class="mb-2"><?= esc(substr((string) ($pf['created_at'] ?? ''), 0, 10) ?: '—') ?></div>
                    <div class="small text-secondary">Lifetime value</div>
                    <div>₹<?= esc(number_format((float) ($pf['lifetime_value'] ?? 0), 2)) ?></div>
                </div></div></div>
            </div></div>
        </div>

    </div></div>
</div>

<!-- Order detail modal -->
<div class="modal fade" id="orderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="orderModalTitle">Order details</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body" id="orderModalBody"><div class="text-center text-secondary py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div></div>
        </div>
    </div>
</div>

<!-- Add / edit address modal (map form loaded via AJAX → always a fresh form) -->
<div class="modal fade" id="addAddrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-geo-alt-fill text-primary me-1"></i>Delivery address</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body p-0" data-addr-body><div class="text-center text-secondary py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div></div>
        </div>
    </div>
</div>

<!-- Edit profile modal -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Edit profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <form method="post" action="<?= site_url('store/account/profile') ?>" data-ajax-refresh="#region-profile" id="profileForm">
                <div class="modal-body">
                    <?= csrf_field() ?>
                    <div class="mb-2"><label class="form-label">Full name</label><input name="name" class="form-control" value="<?= esc($pf['name'] ?? '', 'attr') ?>" required></div>
                    <div class="mb-2"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= esc($pf['email'] ?? '', 'attr') ?>"></div>
                    <div class="mb-2"><label class="form-label">Mobile</label><input name="phone" class="form-control" value="<?= esc($pf['phone'] ?? '', 'attr') ?>"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save changes</button></div>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('plugins/datatables/jquery.dataTables.min.js') ?>"></script>
<script src="<?= asset('plugins/datatables/dataTables.bootstrap5.min.js') ?>"></script>
<script>window.__caMapsKey = <?= json_encode($mapsKey ?? '') ?>;</script>
<script src="<?= asset('js/checkout-address.js') ?>"></script>
<script>
(function () {
    var nav = document.querySelector('.st-acc-nav');
    if (!nav || !window.bootstrap) { return; }
    var $$ = window.jQuery;

    function initTables() {
        if (!$$ || !$$.fn.DataTable) { return; }
        [['#overviewTable', [[3, 'desc']]], ['#ordersTable', [[3, 'desc']]], ['#notifTable', [[2, 'desc']]]].forEach(function (cfg) {
            var el = document.querySelector(cfg[0]);
            if (el && !$$.fn.DataTable.isDataTable(el)) {
                $$(el).DataTable({ pageLength: 10, lengthChange: false, order: cfg[1], columnDefs: [{ orderable: false, targets: 'no-sort' }], language: { search: '', searchPlaceholder: 'Search…' } });
            }
        });
    }
    initTables();

    function show(tab) {
        var btn = nav.querySelector('[data-tab="' + tab + '"]'); if (!btn) { return; }
        bootstrap.Tab.getOrCreateInstance(btn).show();
    }
    if (location.hash.length > 1) { show(location.hash.slice(1)); }
    nav.addEventListener('shown.bs.tab', function (e) {
        var t = e.target.getAttribute('data-tab'); if (t) { history.replaceState(null, '', '#' + t); }
        // Fix DataTables column widths that were measured while hidden.
        var pane = document.querySelector(e.target.getAttribute('data-bs-target'));
        var tbl  = pane && pane.querySelector('table.dataTable');
        if (tbl && $$ && $$.fn.DataTable.isDataTable(tbl)) { $$(tbl).DataTable().columns.adjust(); }
    });

    // Re-fetch a dashboard region in place (destroying any DataTable first, then re-init).
    function refreshRegion(sel) {
        var cur = document.querySelector(sel); if (!cur) { return; }
        fetch(location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector(sel);
                if (!fresh) { return; }
                var old = cur.querySelector('table.dataTable');
                if (old && $$ && $$.fn.DataTable.isDataTable(old)) { $$(old).DataTable().destroy(); }
                cur.replaceWith(fresh);
                initTables();
            }).catch(function () {});
    }

    // Order detail modal.
    var modalEl = document.getElementById('orderModal');
    function loadFragment(url) {
        var body = document.getElementById('orderModalBody');
        body.innerHTML = '<div class="text-center text-secondary py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
            .then(function (html) { body.innerHTML = html; })
            .catch(function () { body.innerHTML = '<div class="text-danger small py-3 text-center">Could not load this order.</div>'; });
    }
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-order-modal]') : null;
        if (t) {
            e.preventDefault();
            if (!modalEl) { window.location = t.getAttribute('href'); return; }
            modalEl.dataset.url = t.getAttribute('data-order-modal');
            document.getElementById('orderModalTitle').textContent = 'Order ' + (t.getAttribute('data-order-no') || '');
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            loadFragment(modalEl.dataset.url);
            return;
        }
        var g = e.target.closest ? e.target.closest('[data-go]') : null;
        if (g) { e.preventDefault(); show(g.getAttribute('data-go')); }
    });

    // After an action inside a modal (order cancel/return, add-address, profile),
    // refresh the affected region and close the address/profile modals.
    document.addEventListener('app:success', function (e) {
        var form = e.detail && e.detail.form; if (!form) { return; }
        var ob = document.getElementById('orderModalBody');
        if (ob && ob.contains(form)) {
            if (modalEl.dataset.url) { loadFragment(modalEl.dataset.url); }
            refreshRegion('#region-orders');
            return;
        }
        if (form.id === 'caForm') { var am = document.getElementById('addAddrModal'); if (am) { bootstrap.Modal.getOrCreateInstance(am).hide(); } }
        if (form.id === 'profileForm') {
            // Keep the header (outside #region-profile) in sync without a reload.
            var nm = form.querySelector('input[name="name"]'), em = form.querySelector('input[name="email"]'), ph = form.querySelector('input[name="phone"]');
            var h1 = document.querySelector('.st-acc-head h1'); if (h1 && nm) { h1.textContent = 'Hi, ' + (nm.value || 'there') + ' 👋'; }
            var sub = document.querySelector('.st-acc-head .text-secondary.small');
            if (sub && em) { sub.textContent = (em.value || '') + (ph && ph.value ? ' · ' + ph.value : ''); }
            var av = document.querySelector('.st-acc-avatar'); if (av && nm) { av.textContent = (nm.value || 'C').substring(0, 2).toUpperCase(); }
            var pm = document.getElementById('profileModal'); if (pm) { bootstrap.Modal.getOrCreateInstance(pm).hide(); }
        }
    });
})();
</script>
<?= $this->endSection() ?>
