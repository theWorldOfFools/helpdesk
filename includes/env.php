<?php
// Loader .env minimal tanpa composer. Cari file .env di root project.
function loadDotEnv($path = null)
{
    if ($path === null) {
        $path = dirname(__DIR__) . '/.env';
        // config.php ada di root, jadi __DIR__ . '/../.env' = root/.env saat dipanggil dari includes/
        // Saat dipanggil dari config.php (root), dirname = root, jadi fallback di bawah.
        if (!file_exists($path)) {
            $path = __DIR__ . '/../.env';
        }
        if (!file_exists($path)) {
            $alt = dirname(__DIR__);
            // Coba juga root langsung
            if (file_exists($alt . '/.env')) $path = $alt . '/.env';
        }
    }
    // Dipanggil dari config.php (root): .env ada di __DIR__/.env
    $rootEnv = __DIR__ . '/.env';
    // includes/env.php ada di includes/, root = dirname(__DIR__)
    $includesRootEnv = dirname(__DIR__) . '/.env';
    $candidates = array_unique([$path, $rootEnv, $includesRootEnv, dirname(__DIR__) . '/../.env']);
    foreach ($candidates as $f) {
        if ($f && file_exists($f) && is_readable($f)) {
            $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);
                // Hapus quote pembungkus
                if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
                    $v = substr($v, 1, -1);
                }
                if ($k !== '' && getenv($k) === false && !isset($_ENV[$k])) {
                    putenv("$k=$v");
                    $_ENV[$k] = $v;
                    $_SERVER[$k] = $v;
                }
            }
            return true;
        }
    }
    return false;
}

function env($key, $default = null)
{
    $v = getenv($key);
    if ($v === false) {
        if (isset($_ENV[$key])) return $_ENV[$key];
        if (isset($_SERVER[$key])) return $_SERVER[$key];
        return $default;
    }
    return $v;
}
