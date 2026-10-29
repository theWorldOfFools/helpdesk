<?php
// Impor gambar dari konten rich-text (clipboard) ke penyimpanan lokal.
// Dipanggil saat SAVE, sebelum sanitizeRichText(), supaya gambar remote/data URI
// tidak dibuang oleh whitelist src di config.php.

if (!defined('HELPDESK_EDITOR_IMAGE_MAX_BYTES')) define('HELPDESK_EDITOR_IMAGE_MAX_BYTES', 5 * 1024 * 1024);
if (!defined('HELPDESK_EDITOR_IMAGE_MAX_COUNT')) define('HELPDESK_EDITOR_IMAGE_MAX_COUNT', 10);
if (!defined('HELPDESK_EDITOR_IMAGE_TIMEOUT')) define('HELPDESK_EDITOR_IMAGE_TIMEOUT', 5);
if (!defined('HELPDESK_EDITOR_IMAGE_MAX_REDIRECTS')) define('HELPDESK_EDITOR_IMAGE_MAX_REDIRECTS', 2);

// Tentukan ekstensi dari isi file (bukan nama/Content-Type).
function editor_image_ext_from_bytes($bytes)
{
    $info = @getimagesizefromstring($bytes);
    if ($info === false) return null;
    $map = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    return $map[$info[2]] ?? null;
}

// Simpan byte gambar ke uploads/editor/. Return path relatif atau null.
function editor_store_image_bytes($bytes, &$error = null)
{
    $error = null;
    if (!is_string($bytes) || $bytes === '') {
        $error = 'Data gambar kosong.';
        return null;
    }
    if (strlen($bytes) > HELPDESK_EDITOR_IMAGE_MAX_BYTES) {
        $error = 'Ukuran gambar maksimal 5MB.';
        return null;
    }
    $ext = editor_image_ext_from_bytes($bytes);
    if ($ext === null) {
        $error = 'File bukan gambar yang didukung. Gunakan JPG, PNG, GIF, atau WEBP.';
        return null;
    }

    $dir = __DIR__ . '/../uploads/editor';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        $error = 'Folder gambar tidak bisa dibuat.';
        return null;
    }

    $name = date('Ym') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (@file_put_contents($dest, $bytes) === false) {
        $error = 'Gagal menyimpan gambar.';
        return null;
    }
    @chmod($dest, 0644);
    return 'uploads/editor/' . $name;
}

function editor_is_local_image_src($src)
{
    return (bool)preg_match(
        '#^(?:\./|/)?uploads/(?:editor|tickets|change_requests)/[A-Za-z0-9._-]+\.(?:jpe?g|png|gif|webp)$#i',
        (string)$src
    );
}

// Ambil nilai atribut dari tag <img ...> (mendukung kutip ganda, tunggal, tanpa kutip).
function editor_img_attr($tag, $name)
{
    if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*("|\')(.*?)\1/is', $tag, $m)) {
        return html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*([^\s>"\']+)/i', $tag, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return null;
}

function editor_build_image_tag($localSrc, $originalTag)
{
    $out = '<img src="' . htmlspecialchars($localSrc, ENT_QUOTES, 'UTF-8') . '"';
    foreach (['alt', 'title'] as $name) {
        $val = editor_img_attr($originalTag, $name);
        if ($val !== null && $val !== '') {
            $out .= ' ' . $name . '="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
        }
    }
    foreach (['width', 'height'] as $name) {
        $val = editor_img_attr($originalTag, $name);
        if ($val !== null && preg_match('/^\d{1,5}$/', $val)) {
            $out .= ' ' . $name . '="' . $val . '"';
        }
    }
    return $out . '>';
}

// Validasi IP: publik, bukan private/reserved/link-local/loopback.
function editor_ip_is_public($ip)
{
    $ip = trim((string)$ip, '[]');
    if ($ip === '') return false;

    // IPv4-mapped IPv6 (::ffff:127.0.0.1) → validasi sebagai IPv4.
    if (stripos($ip, '::ffff:') === 0) {
        $v4 = substr($ip, 7);
        if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) $ip = $v4;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP) === false) return false;
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

// Resolve host ke IP publik dan kembalikan target untuk CURLOPT_RESOLVE (anti DNS rebinding).
function editor_resolve_public_target($url, &$error = null)
{
    $error = null;
    $parts = @parse_url($url);
    if (!is_array($parts)) {
        $error = 'URL gambar tidak valid.';
        return null;
    }

    $scheme = strtolower($parts['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        $error = 'Skema URL gambar tidak didukung.';
        return null;
    }
    if (!empty($parts['user']) || !empty($parts['pass'])) {
        $error = 'URL gambar tidak boleh memakai kredensial.';
        return null;
    }

    $host = trim($parts['host'] ?? '', '[]');
    if ($host === '') {
        $error = 'Host gambar tidak valid.';
        return null;
    }

    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($port, [80, 443], true)) {
        $error = 'Port URL gambar tidak diizinkan.';
        return null;
    }

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        if (!editor_ip_is_public($host)) {
            $error = 'Alamat gambar tidak boleh mengarah ke jaringan internal.';
            return null;
        }
        $ips[] = $host;
    } else {
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) $ips = array_merge($ips, $v4);

        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $rec) {
                if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
            }
        }
    }

    $ips = array_values(array_unique(array_filter($ips, 'editor_ip_is_public')));
    if (!$ips) {
        $error = 'Host gambar tidak bisa di-resolve ke IP publik.';
        return null;
    }

    return ['host' => $host, 'port' => $port, 'ip' => $ips[0], 'scheme' => $scheme, 'literal' => (filter_var($host, FILTER_VALIDATE_IP) !== false)];
}

function editor_absolute_url($base, $location)
{
    $location = trim((string)$location);
    if ($location === '') return null;
    if (preg_match('#^https?://#i', $location)) return $location;

    $b = @parse_url($base);
    if (!is_array($b) || empty($b['scheme']) || empty($b['host'])) return null;
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

    if (strpos($location, '//') === 0) return $b['scheme'] . ':' . $location;
    if ($location[0] === '/') return $origin . $location;
    if ($location[0] === '?') return $origin . ($b['path'] ?? '/') . $location;

    $path = $b['path'] ?? '/';
    $dir = substr($path, 0, strrpos($path, '/') + 1);
    return $origin . $dir . $location;
}

// Satu request HTTP tanpa follow redirect. Body dibatasi ukuran maksimum.
function editor_http_get_once($url, $target)
{
    $body = '';
    $tooBig = false;
    $headers = [];

    $options = [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => HELPDESK_EDITOR_IMAGE_TIMEOUT,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Helpdesk-Editor/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: image/*'],
        CURLOPT_MAXFILESIZE    => HELPDESK_EDITOR_IMAGE_MAX_BYTES,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $name = strtolower(trim(substr($line, 0, $pos)));
                $value = trim(substr($line, $pos + 1));
                if ($name !== '') $headers[$name] = $value;
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, &$tooBig) {
            if (strlen($body) + strlen($chunk) > HELPDESK_EDITOR_IMAGE_MAX_BYTES) {
                $tooBig = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];

    // IP literal (terutama IPv6) tidak butuh CURLOPT_RESOLVE; entri resolve
    // untuk host IPv6 bisa salah format dan membuat request gagal.
    if (empty($target['literal'])) {
        $ip = $target['ip'];
        $options[CURLOPT_RESOLVE] = [
            $target['host'] . ':' . $target['port'] . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip)
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $options);

    $ok = curl_exec($ch);
    $errno = curl_errno($ch);
    $curlError = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($tooBig) {
        $curlError = 'Ukuran gambar melebihi 5MB.';
    } elseif ($errno === 63) {
        $curlError = 'Ukuran gambar melebihi 5MB.';
    }

    return [
        'ok'      => ($ok !== false && !$tooBig && $errno === 0),
        'body'    => $body,
        'code'    => $code,
        'headers' => $headers,
        'error'   => $curlError,
    ];
}

// Download gambar remote dengan validasi SSRF + redirect manual.
function editor_fetch_remote_image($url, &$error = null)
{
    $error = null;
    $current = $url;

    for ($hop = 0; $hop <= HELPDESK_EDITOR_IMAGE_MAX_REDIRECTS; $hop++) {
        $target = editor_resolve_public_target($current, $resolveError);
        if (!$target) {
            $error = $resolveError ?: 'URL gambar tidak valid.';
            return null;
        }

        $res = editor_http_get_once($current, $target);
        if (!$res['ok']) {
            $error = $res['error'] ?: 'Gagal mengunduh gambar.';
            return null;
        }

        $code = (int)$res['code'];
        if (in_array($code, [301, 302, 303, 307, 308], true)) {
            $location = $res['headers']['location'] ?? '';
            if ($location === '') {
                $error = 'Redirect gambar tanpa lokasi.';
                return null;
            }
            $next = editor_absolute_url($current, $location);
            if (!$next) {
                $error = 'Redirect gambar tidak valid.';
                return null;
            }
            $current = $next;
            continue;
        }

        if ($code < 200 || $code >= 300) {
            $error = 'Gambar tidak bisa diunduh (HTTP ' . $code . ').';
            return null;
        }

        $stored = editor_store_image_bytes($res['body'], $storeError);
        if (!$stored) {
            $error = $storeError ?: 'Gambar tidak valid.';
            return null;
        }
        return $stored;
    }

    $error = 'Terlalu banyak redirect gambar.';
    return null;
}

// Ganti semua <img> non-lokal di HTML dengan salinan lokal.
// $failed diisi jumlah gambar yang gagal diimpor.
function localizeRichTextImages($html, &$failed = null)
{
    $failed = 0;
    $html = (string)($html ?? '');
    if ($html === '' || stripos($html, '<img') === false) return $html;

    $count = 0;
    $cache = [];

    $out = preg_replace_callback('/<img\b(?:"[^"]*"|\'[^\']*\'|[^>])*>/i', function ($m) use (&$failed, &$count, &$cache) {
        $tag = $m[0];
        $src = editor_img_attr($tag, 'src');
        if ($src === null || trim($src) === '') return '';

        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($src === '') return '';

        // Gambar lokal (upload lama/baru) dibiarkan apa adanya.
        if (editor_is_local_image_src($src)) return $tag;

        // Clipboard dari browser biasanya mengubah src menjadi URL absolut.
        // Hanya URL same-origin yang dipetakan kembali ke path lokal, supaya
        // gambar dari situs lain tidak salah dianggap sebagai file lokal.
        if (preg_match('#^(?:https?:)?//([^/]+)/(?:[^/]+/)*(uploads/(?:editor|tickets|change_requests)/[A-Za-z0-9._-]+\.(?:jpe?g|png|gif|webp))$#i', $src, $lm)) {
            $srcHost = strtolower(preg_replace('/:\d+$/', '', $lm[1]));
            $appHost = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
            $isSameHost = ($appHost !== '' && $srcHost === $appHost);
            $isLoopback = in_array($srcHost, ['localhost', '127.0.0.1', '[::1]'], true);
            if ($isSameHost || $isLoopback) {
                return editor_build_image_tag($lm[2], $tag);
            }
        }

        if (isset($cache[$src])) {
            return editor_build_image_tag($cache[$src], $tag);
        }

        if ($count >= HELPDESK_EDITOR_IMAGE_MAX_COUNT) {
            $failed++;
            return '';
        }
        $count++;

        $local = null;
        $err = null;

        if (preg_match('#^data:image/(?:jpeg|jpg|png|gif|webp);base64,(.+)$#is', $src, $dm)) {
            $encoded = preg_replace('/\s+/', '', $dm[1]);
            if (strlen($encoded) <= (int)ceil(HELPDESK_EDITOR_IMAGE_MAX_BYTES * 4 / 3) + 16) {
                $bytes = base64_decode($encoded, true);
                if ($bytes !== false) $local = editor_store_image_bytes($bytes, $err);
            }
        } elseif (strpos($src, '//') === 0) {
            $local = editor_fetch_remote_image('https:' . $src, $err);
        } elseif (preg_match('#^https?://#i', $src)) {
            $local = editor_fetch_remote_image($src, $err);
        }

        if (!$local) {
            $failed++;
            return '';
        }

        $cache[$src] = $local;
        return editor_build_image_tag($local, $tag);
    }, $html);

    return $out;
}

// Helper bersama untuk save handler: impor gambar, lalu sanitasi.
// Jika hasil sanitasi kosong tetapi ada teks, simpan sebagai teks aman.
function prepareRichTextForStorage($raw, &$imageFailures = null)
{
    $imageFailures = 0;
    $localized = localizeRichTextImages($raw, $imageFailures);
    $clean = sanitizeRichText($localized);

    $cleanPlain = trim(strip_tags($clean));
    if ($cleanPlain !== '') return $clean;

    $plain = trim(strip_tags((string)$raw));
    if ($plain !== '') return nl2br(htmlspecialchars($plain));

    if ($imageFailures > 0) {
        return '<p><em>Gambar gagal diimpor dari sumbernya.</em></p>';
    }

    return $clean;
}
