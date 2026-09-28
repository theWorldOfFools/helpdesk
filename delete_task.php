<?php
// Hapus mini-todo SDLC — admin only, POST + CSRF. Mirror delete_cr.php.
require_once 'config.php';
require_once 'includes/dev_auth.php';
requireLogin();

$user = getCurrentUser();
if (!canDeleteTask(null, $user)) {
    header('Location: tasks.php');
    exit;
}

requirePost();
requireCsrf();

$conn = getDBConnection();
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id > 0) {
    // comments + history ikut terhapus via ON DELETE CASCADE
    pg_query_params($conn, "DELETE FROM dev_tasks WHERE id = $1", [$id]);
}

pg_close($conn);
header('Location: tasks.php');
exit;
