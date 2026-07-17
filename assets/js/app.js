/* ==========================================================================
   app.js — bespoke theme behaviour + AJAX bootstrapping (jQuery + Bootstrap 5)
   - Sidebar toggle (mobile offcanvas)
   - CSRF header wired into all jQuery AJAX (reads <meta name="csrf-*">)
   - Bearer token attached if present (sessionStorage 'api_token') for /api calls
   ========================================================================== */
(function ($) {
    'use strict';

    $(function () {
        // ----- Sidebar toggle (mobile) -----
        var $shell = $('#appShell');
        $(document).on('click', '[data-toggle="sidebar"]', function (e) {
            e.preventDefault();
            $shell.toggleClass('sidebar-open');
        });
        $(document).on('click', '.sidebar-backdrop', function () {
            $shell.removeClass('sidebar-open');
        });

        // ----- CSRF for AJAX (CodeIgniter 4) -----
        var csrfName  = $('meta[name="csrf-name"]').attr('content');
        var csrfHash  = $('meta[name="csrf-hash"]').attr('content');
        var apiToken  = window.sessionStorage ? sessionStorage.getItem('api_token') : null;

        $.ajaxSetup({
            beforeSend: function (xhr, settings) {
                if (apiToken && settings.url && settings.url.indexOf('/api/') !== -1) {
                    xhr.setRequestHeader('Authorization', 'Bearer ' + apiToken);
                }
            },
            data: (csrfName && csrfHash)
                ? function () { var o = {}; o[csrfName] = csrfHash; return o; }
                : undefined
        });

        // Refresh CSRF hash after each AJAX response if the server rotates it.
        $(document).ajaxComplete(function (event, xhr) {
            var newHash = xhr.getResponseHeader('X-CSRF-TOKEN');
            if (newHash) { $('meta[name="csrf-hash"]').attr('content', newHash); }
        });

        // ----- Topbar notifications -----
        var $notifList  = $('#notifList');
        var $notifBadge = $('#notifBadge');

        function escapeHtml(s) {
            return $('<div>').text(s == null ? '' : String(s)).html();
        }

        function setBadge(n) {
            if (n > 0) {
                $notifBadge.text(n > 99 ? '99+' : n).removeClass('d-none');
            } else {
                $notifBadge.addClass('d-none');
            }
        }

        function renderItem(it) {
            return '' +
                '<div class="notif-item" data-id="' + escapeHtml(it.id) + '">' +
                    '<span class="notif-icon notif-icon--' + escapeHtml(it.accent) + '">' +
                        '<i class="bi ' + escapeHtml(it.icon) + '"></i>' +
                    '</span>' +
                    '<div class="notif-body">' +
                        '<div class="notif-title">' + escapeHtml(it.title) + '</div>' +
                        '<div class="notif-time">' + escapeHtml(it.time_ago) + '</div>' +
                    '</div>' +
                    '<button type="button" class="notif-dismiss" aria-label="Dismiss">' +
                        '<i class="bi bi-x-lg"></i>' +
                    '</button>' +
                '</div>';
        }

        var emptyHtml =
            '<div class="notif-empty text-center text-secondary py-4">' +
                '<i class="bi bi-bell d-block mb-2 fs-4"></i>No notifications yet.</div>';

        function loadNotifications() {
            if (!$notifList.length) { return; }
            $.getJSON($notifList.data('feed-url'))
                .done(function (data) {
                    setBadge(data.unread || 0);
                    if (data.items && data.items.length) {
                        $notifList.html($.map(data.items, renderItem).join(''));
                    } else {
                        $notifList.html(emptyHtml);
                    }
                })
                .fail(function () { /* leave the empty state in place */ });
        }

        if ($notifList.length) {
            loadNotifications();
            setInterval(loadNotifications, 60000); // refresh badge/list every 60s

            // Visual-only dismiss: remove the row, drop the badge by one.
            $notifList.on('click', '.notif-dismiss', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).closest('.notif-item').remove();
                var n = parseInt($notifBadge.text(), 10);
                setBadge(isNaN(n) ? 0 : Math.max(0, n - 1));
                if (!$notifList.find('.notif-item').length) { $notifList.html(emptyHtml); }
            });

            // Visual-only clear all.
            $(document).on('click', '#notifClear', function (e) {
                e.preventDefault();
                $notifList.html(emptyHtml);
                setBadge(0);
            });
        }

        // ----- Enable Bootstrap tooltips if any -----
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el);
        });
    });
})(jQuery);
