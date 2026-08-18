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
        // impersonating on ANY of those hosts, not just admin's own.
        //
        // site_url(), NOT panel_url() — deliberately, and it is load-bearing.
        // 'admin/portal/leave' is registered STANDALONE in Routes.php, outside the
        // subdomain-pinned admin group, exactly so an impersonated (principal_type-
        // swapped) session can reach it from any panel host; PanelSubdomainIsolationTest
        // pins that it resolves on all five. panel_url() is for routes restricted to
        // ANOTHER subdomain, which this is not.
        //
        // Using it here produced a cross-ORIGIN action, and partials/_scripts.php loads
        // js/ajax-forms.js on every panel page: its isExcluded() does not exempt this
        // form, so it cancels the native POST (which SameSite=Lax permits — same site)
        // and replays it through fetch() with an X-Requested-With header, forcing a CORS
        // preflight nothing answers. The request never left the browser and the button
        // silently did nothing.
        //
        // The cross-host hop still happens, just on the RESPONSE: leave() redirects to
        // admin.shiplore.in, AjaxRedirectFilter turns that into a JSON envelope, and
        // ajax-forms.js navigates via window.location.assign() — a top-level navigation,
        // which is unrestricted cross-origin.
        ?>
        <form method="post" action="<?= site_url('admin/portal/leave') ?>" class="m-0">
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
