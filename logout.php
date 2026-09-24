<?php
require_once 'config.php';

$conn = getDBConnection();
// Bersihkan remember token milik session ini
if (!empty($_SESSION['user_id'])) {
    pg_query_params($conn, "UPDATE users SET remember_token = NULL WHERE id = $1", [(int)$_SESSION['user_id']]);
    logActivity($conn, (int)$_SESSION['user_id'], 'logout', 'Logout');
}
pg_close($conn);
setcookie('hd_remember', '', time() - 3600, '/', '', !empty($_SERVER['HTTPS']), true);

session_unset();
session_destroy();
header('Location: login.php');
exit;
