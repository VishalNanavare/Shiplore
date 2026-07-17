<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?= $this->include('partials/_store_order_detail', [
    'order'      => $order,
    'orderNo'    => $orderNo,
    'canCancel'  => $canCancel,
    'canReturn'  => $canReturn,
    'windowDays' => $windowDays,
    'invoices'   => $invoices,
    'modal'      => false,
]) ?>
<?= $this->endSection() ?>
