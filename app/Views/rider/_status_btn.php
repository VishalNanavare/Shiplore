<form method="post" action="<?= site_url('rider/deliveries/' . $id . '/status') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="status" value="<?= esc($to, 'attr') ?>">
    <button class="btn <?= esc($cls ?? 'btn-rd', 'attr') ?> w-100"><i class="bi bi-<?= esc($icon ?? 'arrow-right', 'attr') ?> me-1"></i><?= esc($label) ?></button>
</form>
