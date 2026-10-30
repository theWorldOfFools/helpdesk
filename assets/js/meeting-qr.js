/* QR undangan rapat: render, salin link, share, dan download PNG. */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    function copyText(text, done) {
        if (!text) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text, done); });
        } else {
            fallbackCopy(text, done);
        }
    }

    function fallbackCopy(text, done) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', 'readonly');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) { /* abaikan */ }
        document.body.removeChild(ta);
        done();
    }

    function flashButton(btn, label) {
        var old = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2"></i> ' + label;
        setTimeout(function () { btn.innerHTML = old; }, 1600);
    }

    ready(function () {
        document.querySelectorAll('[data-meeting-qr-wrap]').forEach(function (wrap) {
            var el = wrap.querySelector('[data-meeting-qr]');
            if (!el || !window.QRCode) return;
            var url = el.getAttribute('data-url') || '';
            if (!url) return;
            el.innerHTML = '';
            try {
                new QRCode(el, {
                    text: url,
                    width: 600,
                    height: 600,
                    colorDark: '#0f172a',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            } catch (e) {
                el.innerHTML = '<span class="small text-danger">QR gagal dibuat.</span>';
            }
        });

        document.querySelectorAll('[data-meeting-copy]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById('meetingShareUrl');
                var text = input ? input.value : (btn.getAttribute('data-url') || '');
                copyText(text, function () { flashButton(btn, 'Tersalin'); });
            });
        });

        document.querySelectorAll('[data-meeting-share]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = btn.getAttribute('data-url') || '';
                var title = btn.getAttribute('data-title') || 'Undangan Rapat';
                if (navigator.share) {
                    navigator.share({ title: title, url: url }).catch(function () {});
                } else {
                    copyText(url, function () { flashButton(btn, 'Tersalin'); });
                }
            });
        });

        document.querySelectorAll('[data-meeting-qr-download]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var wrap = btn.closest('[data-meeting-qr-wrap]') || document.querySelector('[data-meeting-qr-wrap]');
                if (!wrap) return;
                var canvas = wrap.querySelector('canvas');
                var filename = (btn.getAttribute('data-filename') || 'qr-undangan') + '.png';
                var href = '';
                if (canvas) {
                    href = canvas.toDataURL('image/png');
                } else {
                    var img = wrap.querySelector('img');
                    if (img && img.src) href = img.src;
                }
                if (!href) return;
                var a = document.createElement('a');
                a.href = href;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                a.remove();
                flashButton(btn, 'Terunduh');
            });
        });
    });
})();
