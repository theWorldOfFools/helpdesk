<?php
require_once 'config.php';
requireLogin();

$user = getCurrentUser();
if (!canDeleteTicket(null, $user)) {
    header('Location: index.php');
    exit;
}

requirePost();
requireCsrf();

$conn = getDBConnection();
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id > 0) {
    $cur = pg_query_params($conn, "SELECT attachment_path FROM tickets WHERE id = $1", [$id]);
    $oldPath = ($cur && pg_num_rows($cur) > 0) ? pg_fetch_result($cur, 0, 0) : null;
    if ($cur) pg_free_result($cur);
    pg_query_params($conn, "DELETE FROM tickets WHERE id = $1", [$id]);
    if ($oldPath && file_exists(__DIR__ . '/' . $oldPath)) @unlink(__DIR__ . '/' . $oldPath);
}

pg_close($conn);
header('Location: index.php');
exit;
