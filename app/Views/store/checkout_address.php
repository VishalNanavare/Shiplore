<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<?php if ($m = session('error')): ?><div class="alert alert-danger"><?= esc($m) ?></div><?php endif; ?>

<nav class="small mb-2 text-secondary"><a href="<?= site_url('store/cart') ?>" class="text-decoration-none">Cart</a> › Delivery address</nav>
<h1 class="h4 mb-3">Select delivery address</h1>

<div class="row g-3">
    <div class="col-lg-8">
        <a href="<?= site_url('store/checkout/add-address') ?>" data-addr-modal="<?= site_url('store/checkout/address-form') ?>" data-addr-target="#checkoutAddrModal" class="st-addnew"><i class="bi bi-plus-lg me-2"></i>Add a new address</a>

        <div class="text-secondary small mt-3 mb-2">Your saved addresses</div>
        <?php foreach ($addresses as $a):
            $isWork  = stripos((string) ($a['label'] ?? ''), 'work') !== false || stripos((string) ($a['label'] ?? ''), 'office') !== false;
            $isHotel = stripos((string) ($a['label'] ?? ''), 'hotel') !== false;
            $hasGeo  = $a['latitude'] !== null && $a['longitude'] !== null;
            $parts   = array_filter([
                $a['recipient_name'] ?? '',
                trim((string) ($a['line1'] ?? '') . (($a['line2'] ?? '') !== '' ? ', ' . $a['line2'] : '')),
                trim((string) ($a['city'] ?? '') . ' ' . (string) ($a['pincode'] ?? '')),
            ]);
            $full = ($a['formatted_address'] ?? '') !== '' ? $a['formatted_address'] : implode(', ', $parts);
        ?>
            <div class="st-addr-card">
                <div class="st-addr-ico"><i class="bi bi-<?= $isHotel ? 'building' : ($isWork ? 'briefcase' : 'house-door') ?>"></i></div>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold"><?= esc($a['label'] ?: 'Address') ?><?php if ($a['is_default']): ?> <span class="badge bg-primary-subtle text-primary ms-1">default</span><?php endif; ?></div>
                    <div class="text-secondary small"><?= esc($full) ?></div>
                    <?php if (! $hasGeo): ?><div class="text-danger small mt-1"><i class="bi bi-exclamation-triangle me-1"></i>Pin this address on the map to deliver here.</div><?php endif; ?>
                    <div class="mt-2 d-flex gap-2 flex-wrap">
                        <form method="post" action="<?= site_url('store/checkout/use-address/' . (int) $a['id']) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-success btn-sm px-3" <?= $hasGeo ? '' : 'disabled' ?>><i class="bi bi-geo-alt me-1"></i>Deliver here</button>
                        </form>
                        <a href="<?= site_url('store/checkout/add-address?id=' . (int) $a['id']) ?>" data-addr-modal="<?= site_url('store/checkout/address-form?id=' . (int) $a['id']) ?>" data-addr-target="#checkoutAddrModal" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
                        <form method="post" action="<?= site_url('store/checkout/delete-address/' . (int) $a['id']) ?>" data-confirm="Remove this saved address?"><?= csrf_field() ?><button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete</button></form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="col-lg-4">
        <div class="card"><div class="card-body">
            <h2 class="h6 mb-2"><i class="bi bi-bag me-1"></i>Your order</h2>
            <div class="text-secondary small mb-2"><?= (int) $count ?> item<?= (int) $count === 1 ? '' : 's' ?> in cart</div>
            <a href="<?= site_url('store/cart') ?>" class="btn btn-outline-secondary btn-sm w-100">View cart</a>
            <div class="text-secondary small mt-3"><i class="bi bi-info-circle me-1"></i>Pick an address to continue to payment.</div>
        </div></div>
    </div>
</div>

<!-- Add / edit address modal (map form loaded via AJAX) -->
<div class="modal fade" id="checkoutAddrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-geo-alt-fill text-primary me-1"></i>Delivery address</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body p-0" data-addr-body><div class="text-center text-secondary py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div></div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>window.__caMapsKey = <?= json_encode($mapsKey ?? '') ?>;</script>
<script src="<?= asset('js/checkout-address.js') ?>"></script>
<?= $this->endSection() ?>
