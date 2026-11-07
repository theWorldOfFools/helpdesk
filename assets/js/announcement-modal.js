(function () {
    'use strict';

    var modalEl = document.getElementById('announcementModal');
    if (!modalEl || !window.bootstrap || !window.bootstrap.Modal) return;

    var ids = (modalEl.getAttribute('data-announcement-ids') || '')
        .split(',')
        .map(function (id) { return id.trim(); })
        .filter(function (id) { return id !== ''; });
    if (!ids.length) return;

    var endpoint = modalEl.getAttribute('data-dismiss-url') || 'announcement_dismiss.php';
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl, {
        backdrop: 'static',
        keyboard: false
    });
    var reported = false;

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) return meta.getAttribute('content');
        var input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }

    // Simpan dismissal 24 jam saat dialog ditutup (tombol X / Mengerti).
    function reportDismissed() {
        if (reported) return;
        reported = true;

        var data = new FormData();
        ids.forEach(function (id) { data.append('ids[]', id); });
        var token = csrfToken();
        if (token) data.append('csrf_token', token);

        fetch(endpoint, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            keepalive: true,
            headers: { 'Accept': 'application/json' }
        }).catch(function () {
            // Bila gagal, dialog akan tampil lagi pada halaman berikutnya.
        });
    }

    modalEl.addEventListener('hidden.bs.modal', reportDismissed);

    // Tampilkan setelah layout halaman siap.
    window.setTimeout(function () {
        modal.show();
    }, 250);
})();
