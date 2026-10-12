<?php
// Download / preview file Drive — GET dengan cek hak akses (anti tebak URL).
// Pakai: drive_download.php?id=FILE_ID [&preview=1 untuk inline].
require_once 'config.php';
require_once 'includes/drive_auth.php';
requireLogin();

$user = getCurrentUser();
$fileId = (int)($_GET['id'] ?? 0);
$preview = !empty($_GET['preview']);
if ($fileId <= 0) {
    http_response_code(404);
    exit('File tidak ditemukan.');
}

$conn = getDBConnection();
$fr = pg_query_params(
    $conn,
    "SELECT f.*, d.name AS folder_name, d.owner_id, d.visibility, d.division, d.parent_id"
    . " FROM drive_files f JOIN drive_folders d ON d.id = f.folder_id WHERE f.id = $1",
    [$fileId]
);
if (!$fr || pg_num_rows($fr) === 0) {
    if ($fr) pg_free_result($fr);
    pg_close($conn);
    http_response_code(404);
    exit('File tidak ditemukan.');
}
$file = pg_fetch_assoc($fr);
pg_free_result($fr);

// Bangun row folder minimal untuk cek akses warisan.
$folder = [
    'id' => $file['folder_id'],
    'parent_id' => $file['parent_id'],
    'name' => $file['folder_name'],
    'owner_id' => $file['owner_id'],
    'division' => $file['division'],
    'visibility' => $file['visibility'],
];
// canView butuh ancestor penuh bila parent ada — ambil dari DB.
$fullFolder = getDriveFolder($conn, (int)$file['folder_id']);
if ($fullFolder) $folder = $fullFolder;
if (!canViewDrive($conn, $folder, $user)) {
    pg_close($conn);
    http_response_code(403);
    exit('Anda tidak punya akses ke file ini.');
}
pg_close($conn);

$path = __DIR__ . '/' . $file['stored_path'];
if (!is_file($path)) {
    http_response_code(404);
    exit('File fisik tidak ditemukan.');
}

$mime = $file['mime'] ?: (function_exists('mime_content_type') ? @mime_content_type($path) : null);
if (!$mime) {
    $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
    $map = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/csv; charset=utf-8', 'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg'];
    $mime = $map[$ext] ?? 'application/octet-stream';
}

$filename = $file['original_name'];
// RFC 5987 untuk nama non-ASCII
$disposition = $preview ? 'inline' : 'attachment';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '_', basename($filename)) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
readfile($path);
exit;
