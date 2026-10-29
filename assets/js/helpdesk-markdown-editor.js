(function () {
    'use strict';

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) return meta.getAttribute('content');
        var input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }

    function notifyFailure(message) {
        if (window.Notiflix && window.Notiflix.Notify) {
            window.Notiflix.Notify.failure(message);
        } else if (window.console && console.warn) {
            console.warn(message);
        }
    }

    // Upload gambar dari clipboard/toolbar ke endpoint server yang sudah ada.
    function uploadImageBlob(blob, callback) {
        var data = new FormData();
        var name = (blob && blob.name) ? blob.name : 'image.png';
        data.append('upload', blob, name);

        var token = csrfToken();
        if (token) data.append('csrf_token', token);

        var headers = { 'Accept': 'application/json' };
        if (token) headers['X-CSRF-Token'] = token;

        var endpoint = window.HELPDESK_EDITOR_UPLOAD_URL || 'editor_upload.php';

        fetch(endpoint, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: headers
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (json) {
                if (!response.ok || !json.url) {
                    var msg = (json && json.error && json.error.message)
                        ? json.error.message
                        : 'Gagal mengunggah gambar.';
                    throw new Error(msg);
                }
                callback(json.url, (blob && blob.name) ? blob.name : 'gambar');
            });
        }).catch(function (error) {
            notifyFailure(error && error.message ? error.message : 'Gagal mengunggah gambar.');
        });
    }

    var DEFAULT_TOOLBAR = [
        ['heading', 'bold', 'italic', 'strike'],
        ['hr', 'quote'],
        ['ul', 'ol', 'indent', 'outdent'],
        ['table', 'image', 'link'],
        ['code', 'codeblock']
    ];

    // Pengganti createHelpdeskEditor CKEditor. Tetap mengembalikan Promise agar
    // pemanggilan di halaman lama tidak perlu berubah banyak.
    window.createHelpdeskEditor = function (element, options) {
        return new Promise(function (resolve, reject) {
            if (!element) {
                reject(new Error('Elemen editor tidak ditemukan.'));
                return;
            }
            if (!window.toastui || !window.toastui.Editor) {
                reject(new Error('Toast UI Editor tidak tersedia.'));
                return;
            }

            var opts = options || {};
            var rows = parseInt(element.getAttribute('rows') || '8', 10);
            if (!rows || rows < 4) rows = 8;
            var height = opts.height || (Math.max(260, rows * 32) + 'px');

            var host = document.createElement('div');
            host.className = 'helpdesk-md-editor';
            element.parentNode.insertBefore(host, element.nextSibling);

            var editor = null;
            try {
                editor = new window.toastui.Editor({
                    el: host,
                    height: height,
                    initialEditType: opts.initialEditType || 'wysiwyg',
                    previewStyle: (window.innerWidth < 768) ? 'tab' : 'vertical',
                    initialValue: '',
                    placeholder: element.getAttribute('placeholder') || '',
                    usageStatistics: false,
                    hideModeSwitch: false,
                    toolbarItems: opts.toolbarItems || DEFAULT_TOOLBAR,
                    hooks: {
                        addImageBlobHook: uploadImageBlob
                    }
                });

                // Data lama tersimpan sebagai HTML. setHTML juga mengonversinya
                // ke markdown bila pengguna beralih ke mode Markdown.
                if (element.value && element.value.trim() !== '') {
                    editor.setHTML(element.value);
                }
                element.classList.add('d-none');
            } catch (error) {
                if (editor) {
                    try { editor.destroy(); } catch (e) { /* abaikan */ }
                }
                if (host.parentNode) host.parentNode.removeChild(host);
                reject(error);
                return;
            }

            // Toast UI tidak menulis otomatis ke textarea. Sync di fase capture
            // supaya nilai sudah benar sebelum handler submit halaman berjalan.
            var form = element.closest ? element.closest('form') : null;
            if (form) {
                form.addEventListener('submit', function () {
                    try { element.value = editor.getHTML(); } catch (e) { /* abaikan */ }
                }, true);
            }

            resolve({
                getData: function () { return editor.getHTML(); },
                getHTML: function () { return editor.getHTML(); },
                getMarkdown: function () { return editor.getMarkdown(); },
                insertText: function (text) {
                    editor.insertText(text);
                    editor.focus();
                },
                replaceSelection: function (text) {
                    editor.replaceSelection(text);
                    editor.focus();
                },
                focus: function () { editor.focus(); },
                destroy: function () {
                    try { editor.destroy(); } catch (e) { /* abaikan */ }
                    if (host.parentNode) host.parentNode.removeChild(host);
                    element.classList.remove('d-none');
                },
                raw: editor
            });
        });
    };
})();
