/* PWA install helper: tombol [data-pwa-install] + panduan per platform.
   Dipakai login.php, includes/header.php (sidebar), dan includes/footer.php.
   Tombol tampil begitu halaman siap; saat diklik memakai prompt native Chrome
   bila tersedia, atau menampilkan panduan manual (iOS/desktop/HTTP). */
(function () {
    'use strict';

    var deferredPrompt = null;
    var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    var isIos = /iphone|ipad|ipod/i.test(window.navigator.userAgent);
    var isAndroid = /android/i.test(window.navigator.userAgent);

    function buttons() {
        return document.querySelectorAll('[data-pwa-install]');
    }

    function setVisible(show) {
        var list = buttons();
        for (var i = 0; i < list.length; i++) list[i].classList.toggle('d-none', !show);
    }

    function guidance() {
        if (!window.isSecureContext) {
            return '<p class="mb-2"><b>Halaman ini belum HTTPS.</b> Chrome Android hanya bisa memasang PWA dari alamat <code>https://</code>, bukan IP/HTTP biasa. Minta admin mengaktifkan HTTPS di server.</p>'
                + '<p class="small text-secondary mb-0">Alternatif sementara (khusus HP sendiri): buka <code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code>, isi alamat situs ini, pilih <b>Enabled</b>, lalu restart Chrome.</p>';
        }
        if (isIos) {
            return '<p class="mb-2">Di iPhone wajib memakai <b>Safari</b>:</p>'
                + '<ol class="mb-0"><li>Ketuk tombol <b>Share</b> (kotak dengan panah).</li><li>Pilih <b>Add to Home Screen</b>.</li><li>Ketuk <b>Add</b>.</li></ol>';
        }
        if (isAndroid) {
            return '<p class="mb-2">Di <b>Chrome Android</b>:</p>'
                + '<ol class="mb-0"><li>Ketuk menu <b>⋮</b> di kanan atas.</li><li>Pilih <b>Install app</b> / <b>Add to Home screen</b>.</li><li>Konfirmasi <b>Install</b>.</li></ol>'
                + '<p class="small text-secondary mt-2 mb-0">Bila menu itu tidak muncul, pastikan situs dibuka lewat HTTPS lalu muat ulang.</p>'
                + '<p class="small text-secondary mt-2 mb-0">Bila muncul pesan <b>&quot;Tidak dapat menginstal&quot;</b> dari Chrome: itu kegagalan WebAPK lewat layanan Google. Coba pakai data seluler (bukan WiFi kantor), update Chrome + Google Play Services, pastikan ruang penyimpanan cukup, lalu ulangi.</p>';
        }
        return '<p class="mb-0">Buka lewat <b>Chrome/Edge</b> di HP, lalu pilih <b>Install app</b> dari menu browser.</p>';
    }

    function showGuidance() {
        var modal = document.getElementById('pwaInstallModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'pwaInstallModal';
            modal.className = 'modal fade';
            modal.tabIndex = -1;
            modal.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
                + '<div class="modal-header py-2"><h5 class="modal-title h6">Pasang aplikasi Helpdesk IT</h5>'
                + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>'
                + '<div class="modal-body small" id="pwaInstallBody"></div></div></div>';
            document.body.appendChild(modal);
        }
        document.getElementById('pwaInstallBody').innerHTML = guidance();
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        } else {
            alert(document.getElementById('pwaInstallBody').textContent);
        }
    }

    function promptInstall() {
        if (!deferredPrompt) {
            showGuidance();
            return;
        }
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function (choice) {
            if (choice && choice.outcome === 'accepted') setVisible(false);
            deferredPrompt = null;
        });
    }

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        setVisible(true);
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        setVisible(false);
    });

    document.addEventListener('DOMContentLoaded', function () {
        if (standalone) {
            setVisible(false);
            return;
        }
        setVisible(true);
        var list = buttons();
        for (var i = 0; i < list.length; i++) list[i].addEventListener('click', promptInstall);
    });
})();
