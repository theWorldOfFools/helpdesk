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
    // Hapus file fisik lampiran dulu (baris ikut CASCADE)
    $ar = pg_query_params($conn, "SELECT file_path FROM dev_task_attachments WHERE task_id = $1", [$id]);
    $paths = [];
    if ($ar) {
        while ($row = pg_fetch_assoc($ar)) $paths[] = $row['file_path'];
        pg_free_result($ar);
    }
    // comments + history + assignees + attachments ikut terhapus via ON DELETE CASCADE
    pg_query_params($conn, "DELETE FROM dev_tasks WHERE id = $1", [$id]);
    foreach ($paths as $p) {
        if ($p && file_exists(__DIR__ . '/' . $p)) @unlink(__DIR__ . '/' . $p);
    }
}

pg_close($conn);
header('Location: tasks.php');
exit;
