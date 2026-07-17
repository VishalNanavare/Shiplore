<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?php if ($m = session('success')): ?><div class="alert alert-success"><?= esc($m) ?></div><?php endif; ?>
<h1 class="h4 mb-3">Wishlist</h1>
<div class="row g-3">
    <?php foreach ($items as $p): ?>
        <div class="col-6 col-md-4 col-lg-3">
            <?= view('partials/_store_product_card', ['p' => $p]) ?>
            <form method="post" action="<?= site_url('store/wishlist/remove') ?>" class="mt-1"><?= csrf_field() ?><input type="hidden" name="product_id" value="<?= esc($p['id'], 'attr') ?>"><button class="btn btn-sm btn-link text-danger p-0">Remove</button></form>
        </div>
    <?php endforeach; ?>
    <?php if (empty($items)): ?><div class="col-12 st-empty"><i class="bi bi-heart"></i>Your wishlist is empty.<div class="mt-2"><a href="<?= site_url('store/products') ?>" class="btn btn-sm btn-primary">Browse products</a></div></div><?php endif; ?>
</div>
<?= $this->endSection() ?>
