/* UI Kit — standalone demo theme behaviour (reference only). */
(function () {
    'use strict';

    // Mobile sidebar toggle
    var shell = document.getElementById('ukShell');
    var burger = document.getElementById('ukBurger');
    if (burger && shell) {
        burger.addEventListener('click', function () { shell.classList.toggle('uk-open'); });
        document.addEventListener('click', function (e) {
            if (window.innerWidth < 992 && shell.classList.contains('uk-open') &&
                !e.target.closest('.uk-sidebar') && !e.target.closest('#ukBurger')) {
                shell.classList.remove('uk-open');
            }
        });
    }

    // Enable Bootstrap tooltips & popovers everywhere
    if (window.bootstrap) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) { new bootstrap.Tooltip(el); });
        document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) { new bootstrap.Popover(el); });
    }

    // Toastr defaults (if loaded)
    if (window.toastr) {
        toastr.options = { closeButton: true, progressBar: true, newestOnTop: true, positionClass: 'toast-top-right', timeOut: 3500 };
    }

    // Shared chart palette
    window.UK = window.UK || {};
    window.UK.colors = { primary: '#5b6ef5', success: '#28c76f', warning: '#ff9f43', danger: '#ea5455', info: '#00cfe8', grey: '#c7cad9' };
})();
