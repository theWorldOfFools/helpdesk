<?php
// Tandai pengumuman sudah ditutup user selama 24 jam.
// Dipanggil via fetch dari dialog pengumuman global (includes/footer.php).
require_once 'config.php';

$user = getCurrentUser();
if (!$user) {
    jsonResponse(['ok' => false, 'error' => 'Sesi login tidak valid. Silakan login ulang.'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Metode tidak diizinkan.'], 405);
}

if (!verifyCsrf()) {
    jsonResponse(['ok' => false, 'error' => 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.'], 419);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids)) $ids = [$ids];
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
    return $id > 0;
})));

if (empty($ids)) {
    jsonResponse(['ok' => true, 'dismissed' => 0]);
}

$conn = getDBConnection();
$dismissed = 0;
foreach ($ids as $id) {
    $r = @pg_query_params(
        $conn,
        "INSERT INTO announcement_dismissals (announcement_id, user_id, dismissed_until)
         VALUES ($1, $2, NOW() + INTERVAL '24 hours')
         ON CONFLICT (announcement_id, user_id)
         DO UPDATE SET dismissed_until = NOW() + INTERVAL '24 hours', dismissed_at = NOW()",
        [$id, (int)$user['id']]
    );
    if ($r) {
        $dismissed++;
        pg_free_result($r);
    }
}
pg_close($conn);

jsonResponse(['ok' => true, 'dismissed' => $dismissed]);
