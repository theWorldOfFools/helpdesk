<?php
// Hapus Change Request — admin only, POST + CSRF. Mirror delete_ticket.php.
require_once 'config.php';
require_once 'includes/cr_auth.php';
requireLogin();

$user = getCurrentUser();
if (!canDeleteCr(null, $user)) {
    header('Location: cr_list.php');
    exit;
}

requirePost();
requireCsrf();

$conn = getDBConnection();
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id > 0) {
    $cur = pg_query_params($conn, "SELECT attachment_path FROM change_requests WHERE id = $1", [$id]);
    $oldPath = ($cur && pg_num_rows($cur) > 0) ? pg_fetch_result($cur, 0, 0) : null;
    if ($cur) pg_free_result($cur);
    // items + comments + history ikut terhapus via ON DELETE CASCADE
    pg_query_params($conn, "DELETE FROM change_requests WHERE id = $1", [$id]);
    if ($oldPath && file_exists(__DIR__ . '/' . $oldPath)) @unlink(__DIR__ . '/' . $oldPath);
}

pg_close($conn);
header('Location: cr_list.php');
exit;
