(function () {
    'use strict';

    var MIN_PLAIN_LENGTH = 10;

    // Hitung panjang teks tanpa tag HTML agar validasi sejalan dengan server
    // (server memakai strip_tags + trim).
    function plainTextLength(html) {
        if (!html) return 0;
        var holder = document.createElement('div');
        holder.innerHTML = html;
        var text = holder.textContent || holder.innerText || '';
        return text.replace(/\s+/g, ' ').trim().length;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.getElementById('actionModal');
        var note = document.getElementById('noteInput');
        if (!modal || !note) return;

        var form = note.closest ? note.closest('form') : null;
        var editor = null;
        var initPromise = null;

        function focusNote() {
            if (editor) {
                try { editor.focus(); } catch (e) { /* abaikan */ }
                return;
            }
            try { note.focus(); } catch (e) { /* abaikan */ }
        }

        // Editor baru dibuat saat modal benar-benar tampil agar Toast UI bisa
        // menghitung lebar/tinggi container dengan benar.
        modal.addEventListener('shown.bs.modal', function () {
            if (initPromise) {
                initPromise.then(focusNote);
                return;
            }
            if (!window.createHelpdeskEditor) return;

            initPromise = window.createHelpdeskEditor(note, { height: '260px' }).then(function (instance) {
                editor = instance;
                focusNote();
                return instance;
            }).catch(function () {
                // Gagal inisialisasi: kembalikan textarea biasa agar tetap bisa dipakai.
                note.classList.remove('d-none');
                focusNote();
            });
        });

        // Sisipkan template jawaban ke editor (fallback ke textarea).
        var tplSelect = document.getElementById('tplSelect');
        var tplBtn = document.getElementById('tplBtn');
        if (tplSelect && tplBtn) {
            tplBtn.addEventListener('click', function () {
                var txt = (window.KB_TEMPLATES || {})[tplSelect.value] || '';
                if (!txt) return;

                if (editor && editor.raw && typeof editor.raw.setMarkdown === 'function') {
                    var current = '';
                    try { current = editor.getMarkdown() || ''; } catch (e) { current = ''; }
                    var next = (current.trim() !== '' ? current.replace(/\s+$/, '') + "\n\n" : '') + txt;
                    editor.raw.setMarkdown(next);
                    focusNote();
                    return;
                }

                note.value = (note.value.trim() !== '' ? note.value.replace(/\s+$/, '') + "\n\n" : '') + txt;
                note.dispatchEvent(new Event('input', { bubbles: true }));
                focusNote();
            });
        }

        // Validasi sisi klien: minimal 10 karakter teks (gambar saja tidak cukup,
        // sama seperti validasi server). Fase capture agar berjalan sebelum submit.
        if (form) {
            form.addEventListener('submit', function (event) {
                var html = '';
                if (editor) {
                    try { html = editor.getHTML(); } catch (e) { html = ''; }
                } else {
                    html = note.value;
                }

                if (plainTextLength(html) < MIN_PLAIN_LENGTH) {
                    event.preventDefault();
                    event.stopPropagation();
                    window.alert('Catatan wajib diisi minimal ' + MIN_PLAIN_LENGTH + ' karakter.');
                    focusNote();
                }
            }, true);
        }
    });
})();
