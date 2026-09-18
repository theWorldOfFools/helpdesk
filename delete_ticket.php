<?php
require_once 'config.php';
requireLogin();

$conn = getDBConnection();
$user = getCurrentUser();

if ($user['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    $query = "DELETE FROM tickets WHERE id = $1";
    pg_query_params($conn, $query, [$id]);
}

pg_close($conn);
header('Location: index.php');
exit;
