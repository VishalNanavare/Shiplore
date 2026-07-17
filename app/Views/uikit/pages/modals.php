<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<p class="uk-section-desc">Dialogs — basic, sizes, centered, scrollable, fullscreen, static backdrop, form, confirm and with tabs.</p>

<div class="card uk-card"><div class="card-body">
    <h2 class="uk-section-title mb-3">Triggers</h2>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#mBasic">Basic</button>
        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mSm">Small</button>
        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mLg">Large</button>
        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mCenter">Centered</button>
        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mScroll">Scrollable</button>
        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mFull">Fullscreen</button>
        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mStatic">Static backdrop</button>
        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mForm">Form</button>
        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mTabs">With tabs</button>
        <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#mConfirm">Confirm delete</button>
    </div>
</div></div>

<?php
if (! function_exists('ukModal')):
function ukModal(string $id, string $title, string $body, string $dialog = '', string $footer = ''): void { ?>
    <div class="modal fade" id="<?= $id ?>" tabindex="-1"><div class="modal-dialog <?= $dialog ?>"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><?= $title ?></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><?= $body ?></div>
        <?php if ($footer !== ''): ?><div class="modal-footer"><?= $footer ?></div><?php endif; ?>
    </div></div></div>
<?php }
endif;
$close = '<button class="btn btn-light" data-bs-dismiss="modal">Close</button><button class="btn btn-primary">Save</button>';
ukModal('mBasic', 'Basic modal', 'A simple modal with header, body and footer.', '', $close);
ukModal('mSm', 'Small', 'A small dialog.', 'modal-sm', $close);
ukModal('mLg', 'Large', 'A large dialog with more room for content.', 'modal-lg', $close);
ukModal('mCenter', 'Vertically centered', 'This dialog is centered in the viewport.', 'modal-dialog-centered', $close);
ukModal('mScroll', 'Scrollable', str_repeat('<p>Long scrollable content paragraph.</p>', 8), 'modal-dialog-scrollable', $close);
ukModal('mFull', 'Fullscreen', 'Occupies the entire viewport.', 'modal-fullscreen', $close);
?>

<!-- Static backdrop -->
<div class="modal fade" id="mStatic" data-bs-backdrop="static" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Static backdrop</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">Clicking outside won't close this — use the buttons.</div>
    <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" data-bs-dismiss="modal">Understood</button></div>
</div></div></div>

<!-- Form -->
<div class="modal fade" id="mForm" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Add user</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="mb-3"><label class="form-label">Name</label><input class="form-control"></div><div class="mb-0"><label class="form-label">Email</label><input type="email" class="form-control"></div></div>
    <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Create</button></div>
</div></div></div>

<!-- Tabs -->
<div class="modal fade" id="mTabs" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Settings</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <ul class="nav nav-tabs mb-3"><li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#mt1">General</button></li><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#mt2">Security</button></li></ul>
        <div class="tab-content"><div class="tab-pane fade show active small" id="mt1">General settings…</div><div class="tab-pane fade small" id="mt2">Security settings…</div></div>
    </div>
</div></div></div>

<!-- Confirm -->
<div class="modal fade" id="mConfirm" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-body text-center p-4">
    <div class="display-6 text-danger mb-2"><i class="bi bi-exclamation-triangle"></i></div>
    <h5>Delete this item?</h5><p class="text-secondary mb-4">This action cannot be undone.</p>
    <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button> <button class="btn btn-danger" data-bs-dismiss="modal">Delete</button>
</div></div></div></div>
<?= $this->endSection() ?>
