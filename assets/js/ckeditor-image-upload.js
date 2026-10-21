(function () {
    'use strict';

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) return meta.getAttribute('content');
        var input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }

    // Helper bersama untuk form Ticket/CR/Todo: CKEditor + upload gambar ke server.
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

        var token = csrfToken();
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
            },
            // Simple upload adapter config (CKEditor 5 built-in)
            simpleUpload: {
                uploadUrl: 'editor_upload.php',
                headers: token ? {
                    'X-CSRF-Token': token
                } : {},
                withCredentials: true
            }
        };

        return window.ClassicEditor.create(element, config);
    };
})();