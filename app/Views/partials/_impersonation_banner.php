<?php if (session()->get('is_impersonating')): ?>
<div class="impersonation-bar" role="alert">
    <div class="impersonation-bar__inner">
        <span class="impersonation-bar__text">
            <i class="bi bi-incognito me-1"></i>
            Viewing as <strong><?= esc(session()->get('impersonation_label') ?: 'portal user') ?></strong>
            <span class="d-none d-sm-inline">— signed in by <?= esc(session()->get('impersonator_name') ?: 'Admin') ?></span>
        </span>
        <?php
        // Shared by admin/vendor/manufacturer/rider layouts, so this renders while
        // impersonating on ANY of those hosts, not just admin's own. 'admin/portal/leave'
        // is a standalone route (Routes.php) outside the admin group precisely so an
        // impersonated (non-platform) session can still reach it, but it still needs an
        // explicit admin-host URL: PortalController::leave() itself already redirects
        // back to admin.shiplore.in on success, so this keeps the exit link consistent
        // with where the flow actually lands.
        ?>
        <form method="post" action="<?= panel_url('admin', 'admin/portal/leave') ?>" class="m-0">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-light fw-semibold">
                <i class="bi bi-box-arrow-left me-1"></i>Return to Admin
            </button>
        </form>
    </div>
</div>
<style>
.impersonation-bar{position:sticky;top:0;z-index:1080;background:linear-gradient(90deg,#7c2d12,#b45309);color:#fff;box-shadow:0 2px 6px rgba(0,0,0,.18)}
.impersonation-bar__inner{max-width:1320px;margin:0 auto;padding:.5rem 1rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;font-size:.875rem}
.impersonation-bar__text{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.impersonation-bar a,.impersonation-bar .btn-light{--bs-btn-color:#7c2d12}
</style>
<?php endif; ?>
