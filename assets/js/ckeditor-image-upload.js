(function () {
    'use strict';

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) return meta.getAttribute('content');
        var input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }

    function HelpdeskUploadAdapter(loader) {
        this.loader = loader;
        this.abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
    }

    HelpdeskUploadAdapter.prototype.upload = function () {
        var self = this;
        return this.loader.file.then(function (file) {
            return new Promise(function (resolve, reject) {
                var data = new FormData();
                data.append('upload', file, file.name);

                var token = csrfToken();
                if (token) data.append('csrf_token', token);

                var headers = { 'Accept': 'application/json' };
                if (token) headers['X-CSRF-Token'] = token;

                var options = {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                    headers: headers
                };
                if (self.abortController) options.signal = self.abortController.signal;

                var endpoint = window.HELPDESK_EDITOR_UPLOAD_URL || 'editor_upload.php';

                fetch(endpoint, options)
                    .then(function (response) {
                        return response.json().catch(function () { return {}; }).then(function (json) {
                            if (!response.ok || !json.url) {
                                var message = json && json.error && json.error.message
                                    ? json.error.message
                                    : 'Gagal mengunggah gambar.';
                                throw new Error(message);
                            }
                            resolve({ default: json.url });
                        });
                    })
                    .catch(function (error) {
                        if (error && error.name === 'AbortError') return;
                        reject(error && error.message ? error.message : 'Gagal mengunggah gambar.');
                    });
            });
        });
    };

    HelpdeskUploadAdapter.prototype.abort = function () {
        if (this.abortController) this.abortController.abort();
    };

    // Helper bersama untuk form Ticket/CR: CKEditor + upload gambar ke server.
    window.createHelpdeskEditor = function (element, options) {
        if (!window.ClassicEditor || !element) return Promise.resolve(null);

        var opts = options || {};
        var defaultToolbar = [
            'heading', '|',
            'bold', 'italic', 'underline', 'link',
            'bulletedList', 'numberedList', 'blockQuote', 'code', '|',
            'uploadImage', '|',
            'undo', 'redo'
        ];

        var config = {
            toolbar: opts.toolbar || defaultToolbar,
            image: {
                upload: {
                    types: ['jpeg', 'png', 'gif', 'webp']
                },
                toolbar: [
                    'imageStyle:inline', 'imageStyle:block', 'imageStyle:side', '|',
                    'toggleImageCaption', 'imageTextAlternative'
                ]
            }
        };

        return window.ClassicEditor.create(element, config).then(function (editor) {
            var repository = editor.plugins.get('FileRepository');
            if (repository) {
                repository.createUploadAdapter = function (loader) {
                    return new HelpdeskUploadAdapter(loader);
                };
            }
            return editor;
        });
    };
})();
