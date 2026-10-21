<?php
// Endpoint upload gambar untuk CKEditor (deskripsi tiket / keterangan CR).
// Hanya menerima gambar, wajib login + CSRF, dan menyimpan ke uploads/editor/.
require_once 'config.php';

if (!isLoggedIn()) {
    jsonResponse(['error' => ['message' => 'Sesi login tidak valid. Silakan login ulang.']], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['error' => ['message' => 'Metode tidak diizinkan.']], 405);
}

if (!verifyCsrf()) {
    jsonResponse(['error' => ['message' => 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.']], 419);
}

if (!isset($_FILES['upload']) || !is_array($_FILES['upload'])) {
    jsonResponse(['error' => ['message' => 'Tidak ada file gambar yang diunggah.']], 400);
}

$f = $_FILES['upload'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    jsonResponse(['error' => ['message' => 'Tidak ada file gambar yang dipilih.']], 400);
}
if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
    jsonResponse(['error' => ['message' => 'Upload gambar gagal (kode ' . (int)$f['error'] . ').']], 400);
}

$maxBytes = 5 * 1024 * 1024;
if (($f['size'] ?? 0) > $maxBytes) {
    jsonResponse(['error' => ['message' => 'Ukuran gambar maksimal 5MB.']], 413);
}

if (!is_uploaded_file($f['tmp_name'] ?? '')) {
    jsonResponse(['error' => ['message' => 'File upload tidak valid.']], 400);
}

// Tentukan jenis gambar dari isi file, bukan dari nama file.
$info = @getimagesize($f['tmp_name']);
$typeToExt = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_WEBP => 'webp',
];
if ($info === false || !isset($typeToExt[$info[2]])) {
    jsonResponse(['error' => ['message' => 'File bukan gambar yang didukung. Gunakan JPG, PNG, GIF, atau WEBP.']], 415);
}

$allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$mime = $info['mime'] ?? '';
if (!in_array($mime, $allowedMime, true)) {
    jsonResponse(['error' => ['message' => 'Tipe gambar tidak didukung.']], 415);
}

$dir = __DIR__ . '/uploads/editor';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    jsonResponse(['error' => ['message' => 'Folder gambar tidak bisa dibuat.']], 500);
}

$ext = $typeToExt[$info[2]];
$stored = date('Ym') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$dest = $dir . '/' . $stored;

if (!move_uploaded_file($f['tmp_name'], $dest)) {
    jsonResponse(['error' => ['message' => 'Gagal menyimpan gambar.']], 500);
}
@chmod($dest, 0644);

jsonResponse(['url' => 'uploads/editor/' . $stored]);
