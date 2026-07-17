<script src="<?= asset('vendor/jquery/jquery.min.js') ?>"></script>
<script src="<?= asset('vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('js/app.js') ?>"></script>
<script src="<?= asset('plugins/sweetalert2/sweetalert2.all.min.js') ?>"></script>
<script src="<?= asset('js/ajax-forms.js') ?>"></script>
<script src="<?= asset('plugins/flatpickr/flatpickr.min.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    flatpickr('input[type="date"]',           { dateFormat: 'Y-m-d', allowInput: true });
    flatpickr('input[type="datetime-local"]', { enableTime: true, dateFormat: 'Y-m-d H:i', allowInput: true, time_24hr: true });
    flatpickr('input[type="time"]',           { enableTime: true, noCalendar: true, dateFormat: 'H:i', allowInput: true, time_24hr: true });
});
</script>
