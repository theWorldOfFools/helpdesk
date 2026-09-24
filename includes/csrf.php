<?php
// CSRF helper sederhana. Membutuhkan session aktif (config.php sudah session_start()).

function csrf_token()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrf($token = null)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }
    if (empty($token) || empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], (string)$token);
}

function requireCsrf()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
        http_response_code(419);
        $isJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
        if ($isJson) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'CSRF token tidak valid. Muat ulang halaman.']);
            exit;
        }
        // Untuk form biasa: set flash sederhana via session lalu redirect back
        $_SESSION['flash_error'] = 'Sesi kedaluwarsa / CSRF tidak valid. Silakan coba lagi.';
        $back = $_SERVER['HTTP_REFERER'] ?? 'index.php';
        header('Location: ' . $back);
        exit;
    }
}

function flash_error()
{
    if (!empty($_SESSION['flash_error'])) {
        $m = $_SESSION['flash_error'];
        unset($_SESSION['flash_error']);
        return $m;
    }
    return null;
}
